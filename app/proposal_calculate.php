<?php
// File: app/proposal_calculate.php
// PENJELASAN: Dirombak total untuk menerapkan logika BDD (Berat Dapat Dimakan).
// 1. HPP (Biaya) dihitung dari BERAT KOTOR (Netto / BDD).
// 2. Gizi (Kalori, dll) dihitung dari BERAT BERSIH (Netto).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

header('Content-Type: application/json');

try {
    $proposal_id = isset($_GET['proposal_id']) ? (int)$_GET['proposal_id'] : 0;
    if ($proposal_id <= 0 || $proposal_id === 'undefined') {
        http_response_code(400);
        throw new Exception('ID proposal wajib disertakan.');
    }

    // 1. Ambil total jumlah penerima manfaat per kategori
    $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count
                  FROM distribution_point_counts dpc
                  JOIN distribution_points dp ON dpc.distribution_point_id = dp.id
                  WHERE dp.organization_id = ?
                  GROUP BY dpc.category_id";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->bind_param("i", $org_id);
    $countsStmt->execute();
    $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $countsStmt->close();
    
    $beneficiary_counts = [];
    foreach($category_totals_result as $row) {
        $beneficiary_counts[$row['category_id']] = (int)$row['total_count'];
    }

    // 2. Ambil semua kategori penerima manfaat
    $catSql = "SELECT id, name FROM beneficiary_categories ORDER BY sort_order ASC, id ASC";
    $categories = $conn->query($catSql)->fetch_all(MYSQLI_ASSOC);

    // 3. Ambil jadwal menu untuk proposal ini
    $scheduleSql = "SELECT menu_id, serving_date FROM proposal_menus WHERE proposal_id = ? AND organization_id = ? AND menu_id != 1";
    $scheduleStmt = $conn->prepare($scheduleSql);
    $scheduleStmt->bind_param("ii", $proposal_id, $org_id);
    $scheduleStmt->execute();
    $schedule = $scheduleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $scheduleStmt->close();

    // 4. Ambil semua resep yang relevan (TERMASUK DATA BDD)
    $menuIds = array_unique(array_column($schedule, 'menu_id'));
    $recipes = [];
    $ingredientIds = [];
    if (!empty($menuIds)) {
        $recipePlaceholders = implode(',', array_fill(0, count($menuIds), '?'));
        // --- PERUBAHAN: JOIN nutrition_data untuk ambil bdd_percentage ---
        $recipeSql = "
            SELECT 
                mi.menu_id, mi.ingredient_id, mi.quantity_per_portion as portions_json,
                i.name as ingredient_name, i.latest_price,
                u.conversion_factor, u.symbol as unit_symbol,
                nd.bdd_percentage
            FROM menu_ingredients mi
            JOIN ingredients i ON mi.ingredient_id = i.id
            JOIN units u ON i.unit_id = u.id
            LEFT JOIN nutrition_data nd ON i.id = nd.ingredient_id AND i.organization_id = nd.organization_id
            WHERE mi.menu_id IN ($recipePlaceholders) AND mi.organization_id = ?
        ";
        $recipeStmt = $conn->prepare($recipeSql);
        $types = str_repeat('i', count($menuIds)) . 'i';
        $params = array_merge($menuIds, [$org_id]);
        $recipeStmt->bind_param($types, ...$params);
        $recipeStmt->execute();
        $recipeResult = $recipeStmt->get_result();
        while($row = $recipeResult->fetch_assoc()){
            $recipes[$row['menu_id']][] = $row;
            $ingredientIds[] = $row['ingredient_id'];
        }
        $recipeStmt->close();
    }
    $ingredientIds = array_unique($ingredientIds);

    // 5. Ambil data gizi untuk semua bahan yang relevan
    $nutritionData = [];
    if (!empty($ingredientIds)) {
        $nutriPlaceholders = implode(',', array_fill(0, count($ingredientIds), '?'));
        // --- PERUBAHAN: Ambil bdd_percentage juga ---
        $nutriSql = "SELECT ingredient_id, calories, protein, carbohydrates, fat, fiber, bdd_percentage FROM nutrition_data WHERE ingredient_id IN ($nutriPlaceholders) AND organization_id = ?";
        $nutriStmt = $conn->prepare($nutriSql);
        $types = str_repeat('i', count($ingredientIds)) . 'i';
        $params = array_merge($ingredientIds, [$org_id]);
        $nutriStmt->bind_param($types, ...$params);
        $nutriStmt->execute();
        $nutriResult = $nutriStmt->get_result();
        while($row = $nutriResult->fetch_assoc()) {
            $nutritionData[$row['ingredient_id']] = $row;
        }
        $nutriStmt->close();
    }

    // 6. Proses Kalkulasi di PHP
    $ingredientNeeds = []; // Untuk total kebutuhan
    $menu_details = [];    // Untuk detail gizi per menu

    // Inisialisasi $ingredientNeeds
    foreach ($recipes as $menuId => $ingredients) {
        foreach ($ingredients as $ing) {
            if (!isset($ingredientNeeds[$ing['ingredient_id']])) {
                $ingredientNeeds[$ing['ingredient_id']] = [
                    'name' => $ing['ingredient_name'],
                    'total_grams_gross' => 0, // --- PERUBAHAN: Kita akan hitung berat kotor ---
                    'price' => (float)$ing['latest_price'],
                    'conversion' => (float)$ing['conversion_factor'],
                    'symbol' => $ing['unit_symbol']
                ];
            }
        }
    }
    
    // Kalkulasi total kebutuhan bahan baku (BERAT KOTOR)
    foreach ($schedule as $day) {
        $menuId = $day['menu_id'];
        if (isset($recipes[$menuId])) {
            foreach ($recipes[$menuId] as $ingredient) {
                $ing_id = $ingredient['ingredient_id'];
                $portions = json_decode($ingredient['portions_json'], true);
                
                // --- LOGIKA BDD ---
                $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
                if ($bdd_factor <= 0) $bdd_factor = 1.00; // Safety check
                // ------------------
                
                $total_grams_net_for_day = 0; // Berat bersih total
                if (is_array($portions)) {
                    foreach ($beneficiary_counts as $cat_id => $count) {
                        $portion_net = (float)($portions[$cat_id] ?? 0);
                        $total_grams_net_for_day += $portion_net * $count;
                    }
                }
                // Konversi dari berat bersih ke berat kotor
                $total_grams_gross_for_day = $total_grams_net_for_day / $bdd_factor;
                
                $ingredientNeeds[$ing_id]['total_grams_gross'] += $total_grams_gross_for_day;
            }
        }
    }

    // Kalkulasi detail gizi & HPP per menu (per kategori)
    foreach ($recipes as $menuId => $ingredients) {
        $menu_name = $ingredients[0]['ingredient_name']; // Ambil nama dari bahan pertama (asumsi)
        if (count($ingredients) > 0) { // Cek jika ada bahan
            $menu_name_sql = "SELECT menu_name FROM menus WHERE id = ? LIMIT 1";
            $menu_name_stmt = $conn->prepare($menu_name_sql);
            $menu_name_stmt->bind_param("i", $menuId);
            $menu_name_stmt->execute();
            $menu_name_res = $menu_name_stmt->get_result()->fetch_assoc();
            if ($menu_name_res) $menu_name = $menu_name_res['menu_name'];
            $menu_name_stmt->close();
        }

        $details_per_category = [];
        foreach ($categories as $category) {
            $cat_id = $category['id'];
            $hpp = 0;
            $nutrition = ['calories' => 0, 'protein' => 0, 'carbohydrates' => 0, 'fat' => 0, 'fiber' => 0];

            foreach ($ingredients as $ingredient) {
                $portions = json_decode($ingredient['portions_json'], true);
                $portion_for_cat_net = (is_array($portions) && isset($portions[$cat_id])) ? (float)$portions[$cat_id] : 0;

                // --- LOGIKA BDD ---
                $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
                if ($bdd_factor <= 0) $bdd_factor = 1.00;
                $portion_for_cat_gross = $portion_for_cat_net / $bdd_factor;
                // ------------------
                
                // Hitung HPP (berdasarkan BERAT KOTOR)
                if ($ingredient['conversion_factor'] > 0) {
                    $price_per_gram = (float)$ingredient['latest_price'] / (float)$ingredient['conversion_factor'];
                    $hpp += $portion_for_cat_gross * $price_per_gram;
                }

                // Hitung Gizi (berdasarkan BERAT BERSIH)
                $nutri = $nutritionData[$ingredient['ingredient_id']] ?? null;
                if ($nutri) {
                    $nutrition['calories'] += ((float)$nutri['calories'] / 100) * $portion_for_cat_net;
                    $nutrition['protein'] += ((float)$nutri['protein'] / 100) * $portion_for_cat_net;
                    $nutrition['carbohydrates'] += ((float)$nutri['carbohydrates'] / 100) * $portion_for_cat_net;
                    $nutrition['fat'] += ((float)$nutri['fat'] / 100) * $portion_for_cat_net;
                    $nutrition['fiber'] += ((float)$nutri['fiber'] / 100) * $portion_for_cat_net;
                }
            }
            $details_per_category[] = ['category_id' => $cat_id, 'category_name' => $category['name'], 'hpp' => $hpp, 'nutrition' => $nutrition];
        }
        $menu_details[] = ['menu_id' => $menuId, 'menu_name' => $menu_name, 'details_per_category' => $details_per_category];
    }

    $required_ingredients = [];
    $total_estimated_budget = 0;
    foreach ($ingredientNeeds as $id => $ing) {
        if ($ing['conversion'] > 0) {
            // --- PERUBAHAN: Gunakan total_grams_gross ---
            $qty_in_purchase_unit = $ing['total_grams_gross'] / $ing['conversion'];
            $cost = $qty_in_purchase_unit * $ing['price'];
            $total_estimated_budget += $cost;
            $required_ingredients[] = [ 
                'ingredient_id' => $id, 
                'ingredient_name' => $ing['name'], 
                'total_needed' => $qty_in_purchase_unit, 
                'estimated_cost' => $cost, 
                'unit_symbol' => $ing['symbol'] 
            ];
        }
    }
    
    $response = [
        'proposal_id' => $proposal_id,
        'effective_days_count' => count($schedule),
        'total_estimated_budget' => $total_estimated_budget,
        'required_ingredients' => $required_ingredients,
        'menu_details' => $menu_details
    ];

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error internal saat kalkulasi proposal.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>