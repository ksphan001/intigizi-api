<?php
// File: app/menu_nutrition_get.php
// PENJELASAN: Diperbarui untuk menghitung total 'fiber' dan
// menghitung HPP berdasarkan BERAT KOTOR (menggunakan BDD).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$menu_id = isset($_GET['menu_id']) ? (int)$_GET['menu_id'] : 0;

if ($menu_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID menu wajib disertakan.']);
    exit();
}

try {
    $categories_sql = "SELECT c.id, c.name, COALESCE(kcl.max_hpp, 8000.00) as max_hpp 
                       FROM beneficiary_categories c 
                       LEFT JOIN kitchen_category_limits kcl ON c.id = kcl.category_id AND kcl.organization_id = ? 
                       ORDER BY c.sort_order ASC, c.id ASC";
    $cat_stmt = $conn->prepare($categories_sql);
    $cat_stmt->bind_param("i", $org_id);
    $cat_stmt->execute();
    $categories = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cat_stmt->close();

    // Query diperbarui untuk menyertakan 'nd.fiber' dan 'nd.bdd_percentage'
    $ingredients_sql = "SELECT
                            mi.quantity_per_portion,
                            nd.calories, nd.protein, nd.carbohydrates, nd.fat, nd.fiber, nd.bdd_percentage,
                            i.latest_price, u.conversion_factor
                        FROM menu_ingredients mi
                        JOIN ingredients i ON mi.ingredient_id = i.id AND i.organization_id = mi.organization_id
                        JOIN units u ON i.unit_id = u.id
                        LEFT JOIN nutrition_data nd ON mi.ingredient_id = nd.ingredient_id AND nd.organization_id = mi.organization_id
                        WHERE mi.menu_id = ? AND mi.organization_id = ?";
    $stmt = $conn->prepare($ingredients_sql);
    $stmt->bind_param("ii", $menu_id, $org_id);
    $stmt->execute();
    $ingredients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $results_by_category = [];

    foreach ($categories as $category) {
        $cat_id = $category['id'];
        $totals = [
            'total_calories' => 0, 'total_protein' => 0, 'total_carbs' => 0,
            'total_fat' => 0, 'total_fiber' => 0, 'total_hpp' => 0
        ];

        foreach ($ingredients as $ingredient) {
            $portions_json = $ingredient['quantity_per_portion'];
            $portions = json_decode($portions_json, true);
            
            // Ini adalah BERAT BERSIH (NET) per porsi
            $quantity_for_category_net = 0;
            if (is_array($portions)) {
                $quantity_for_category_net = (float)($portions[$cat_id] ?? 0);
            }

            if ($quantity_for_category_net > 0) {
                // --- LOGIKA BDD ---
                $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
                if ($bdd_factor <= 0) $bdd_factor = 1.00;
                // Hitung BERAT KOTOR (GROSS)
                $quantity_for_category_gross = $quantity_for_category_net / $bdd_factor;
                // ------------------
                
                // Hitung Gizi (berdasarkan BERAT BERSIH)
                $totals['total_calories'] += ((float)$ingredient['calories'] * $quantity_for_category_net) / 100;
                $totals['total_protein'] += ((float)$ingredient['protein'] * $quantity_for_category_net) / 100;
                $totals['total_carbs'] += ((float)$ingredient['carbohydrates'] * $quantity_for_category_net) / 100;
                $totals['total_fat'] += ((float)$ingredient['fat'] * $quantity_for_category_net) / 100;
                $totals['total_fiber'] += ((float)$ingredient['fiber'] * $quantity_for_category_net) / 100; 

                // Hitung HPP (berdasarkan BERAT KOTOR)
                if ($ingredient['conversion_factor'] > 0) {
                    $price_per_base_unit = (float)$ingredient['latest_price'] / (float)$ingredient['conversion_factor'];
                    $totals['total_hpp'] += $price_per_base_unit * $quantity_for_category_gross;
                }
            }
        }
        
        $results_by_category[] = [
            'category_id' => $cat_id,
            'category_name' => $category['name'],
            'nutrition' => [
                'total_calories' => $totals['total_calories'],
                'total_protein' => $totals['total_protein'],
                'total_carbs' => $totals['total_carbs'],
                'total_fat' => $totals['total_fat'],
                'total_fiber' => $totals['total_fiber'],
            ],
            'hpp' => $totals['total_hpp'],
            'max_hpp' => (float)($category['max_hpp'] ?? 8000.00)
        ];
    }
    
    http_response_code(200);
    echo json_encode($results_by_category);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menghitung data gizi menu.', 'error' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>