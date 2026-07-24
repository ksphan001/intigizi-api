<?php
// File: app/procurement_get_details.php
// PENJELASAN: Logika kalkulasi dirombak total untuk MENGGUNAKAN LOGIKA BDD (Berat Dapat Dimakan)
// Kebutuhan bahan baku (total_needed) dihitung berdasarkan BERAT KOTOR.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$proposal_id = isset($_GET['proposal_id']) ? (int)$_GET['proposal_id'] : 0;

if ($proposal_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Proposal wajib diisi.']);
    exit();
}

try {
    $response = [];

    // 1. Ambil info proposal dasar
    $propSql = "SELECT proposal_code FROM proposals WHERE id = ? AND organization_id = ? AND status = 'Disetujui'";
    $propStmt = $conn->prepare($propSql);
    $propStmt->bind_param("ii", $proposal_id, $org_id);
    $propStmt->execute();
    $proposal_data = $propStmt->get_result()->fetch_assoc();
    $propStmt->close();
    if (!$proposal_data) {
        throw new Exception("Proposal tidak ditemukan atau belum disetujui.", 404);
    }
    $response['proposal'] = $proposal_data;

    // 2. Ambil total jumlah penerima manfaat per kategori
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
        }
        $recipeStmt->close();
    }
    
    // 5. Kalkulasi Total Kebutuhan Bahan Baku (BERAT KOTOR)
    $ingredientNeeds = [];
    foreach ($recipes as $menuId => $ingredients) {
        foreach ($ingredients as $ing) {
            if (!isset($ingredientNeeds[$ing['ingredient_id']])) {
                $ingredientNeeds[$ing['ingredient_id']] = [
                    'name' => $ing['ingredient_name'], 
                    'total_grams_gross' => 0, // --- PERUBAHAN: Hitung berat kotor ---
                    'price' => (float)$ing['latest_price'], 
                    'conversion' => (float)$ing['conversion_factor'],
                    'symbol' => $ing['unit_symbol'],
                    'bdd_percentage' => (float)($ing['bdd_percentage'] ?? 1.00)
                ];
            }
        }
    }
    
    foreach ($schedule as $day) {
        $menuId = $day['menu_id'];
        if (isset($recipes[$menuId])) {
            foreach ($recipes[$menuId] as $ingredient) {
                $ing_id = $ingredient['ingredient_id'];
                $portions = json_decode($ingredient['portions_json'], true);

                // --- LOGIKA BDD ---
                $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
                if ($bdd_factor <= 0) $bdd_factor = 1.00;
                // ------------------
                
                $total_grams_net_for_day = 0;
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

    $procurementMap = [];
    foreach ($ingredientNeeds as $id => $ing) {
        if ($ing['conversion'] > 0) {
            // --- PERUBAHAN: Gunakan total_grams_gross ---
            $qty_in_purchase_unit = $ing['total_grams_gross'] / $ing['conversion'];
            $procurementMap[$id] = [
                'ingredient_name' => $ing['name'],
                'unit_symbol' => $ing['symbol'],
                'latest_price' => $ing['price'],
                'total_needed' => $qty_in_purchase_unit, // Ini adalah berat kotor
                'total_ordered' => 0,
            ];
        }
    }

    // 6. Hitung total yang sudah dipesan dalam PO (Logika ini tidak berubah)
    $orderedSql = "SELECT pi.ingredient_id, SUM(pi.quantity) AS total_ordered FROM po_items pi JOIN purchase_orders po ON pi.po_id = po.id WHERE po.proposal_id = ? AND po.organization_id = ? GROUP BY pi.ingredient_id";
    $orderedStmt = $conn->prepare($orderedSql);
    $orderedStmt->bind_param("ii", $proposal_id, $org_id);
    $orderedStmt->execute();
    $orderedResult = $orderedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $orderedStmt->close();

    foreach ($orderedResult as $item) {
        if (isset($procurementMap[$item['ingredient_id']])) {
            $procurementMap[$item['ingredient_id']]['total_ordered'] = (float)$item['total_ordered'];
        }
    }

    // 7. Format hasil akhir dan hitung sisa kebutuhan
    $procurementItems = [];
    foreach ($procurementMap as $id => $data) {
        $remaining = $data['total_needed'] - $data['total_ordered'];
        $procurementItems[] = [
            'ingredient_id' => $id,
            'ingredient_name' => $data['ingredient_name'],
            'unit_symbol' => $data['unit_symbol'],
            'latest_price' => $data['latest_price'],
            'total_needed' => $data['total_needed'],
            'total_ordered' => $data['total_ordered'],
            'remaining' => $remaining < 0 ? 0 : $remaining,
        ];
    }
    
    usort($procurementItems, fn($a, $b) => strcmp($a['ingredient_name'], $b['ingredient_name']));
    $response['procurement_items'] = $procurementItems;

    // 8. Ambil daftar PO yang sudah dibuat untuk proposal ini
    $poSql = "SELECT po.id, po.po_code, po.total_amount, po.status, COALESCE(s.supplier_name, v.name) as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id AND s.organization_id = po.organization_id LEFT JOIN organizations v ON po.supplier_id = v.id AND v.registration_type = 'Vendor' WHERE po.proposal_id = ? AND po.organization_id = ? ORDER BY po.created_at DESC";
    $poStmt = $conn->prepare($poSql);
    $poStmt->bind_param("ii", $proposal_id, $org_id);
    $poStmt->execute();
    $response['purchase_orders'] = $poStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $poStmt->close();

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>