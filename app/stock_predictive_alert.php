<?php
// File: app/stock_predictive_alert.php
// Penjelasan: API untuk mendeteksi bahan makanan kritis / defisit berdasarkan menu rencana minggu depan vs stok riil dapur.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

header('Content-Type: application/json');

try {
    // 1. Dapatkan proposal aktif / draf terbaru dapur ini
    $proposalSql = "SELECT id, proposal_code, start_date, end_date FROM proposals WHERE organization_id = ? ORDER BY created_at DESC LIMIT 1";
    $propStmt = $conn->prepare($proposalSql);
    $propStmt->bind_param("i", $org_id);
    $propStmt->execute();
    $proposal = $propStmt->get_result()->fetch_assoc();
    $propStmt->close();

    if (!$proposal) {
        // Jika belum ada proposal sama sekali
        echo json_encode([
            'proposal' => null,
            'deficits' => []
        ]);
        exit();
    }

    $proposal_id = $proposal['id'];

    // 2. Ambil total jumlah penerima manfaat per kategori
    $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count
                  FROM distribution_point_counts dpc
                  JOIN distribution_points dp ON dpc.distribution_point_id = dp.id
                  WHERE dp.organization_id = ?
                  GROUP BY dpc.category_id";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->bind_param("i", $org_id);
    $countsStmt->execute();
    $category_totals = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $countsStmt->close();

    $beneficiary_counts = [];
    foreach ($category_totals as $row) {
        $beneficiary_counts[$row['category_id']] = (int)$row['total_count'];
    }

    // 3. Ambil jadwal menu untuk proposal ini (kecuali id default 1 = menu kosong)
    $scheduleSql = "SELECT menu_id, serving_date FROM proposal_menus WHERE proposal_id = ? AND organization_id = ? AND menu_id != 1";
    $scheduleStmt = $conn->prepare($scheduleSql);
    $scheduleStmt->bind_param("ii", $proposal_id, $org_id);
    $scheduleStmt->execute();
    $schedule = $scheduleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $scheduleStmt->close();

    // 4. Ambil resep menu (termasuk BDD percentage)
    $menuIds = array_unique(array_column($schedule, 'menu_id'));
    $recipes = [];
    $ingredientIds = [];
    if (!empty($menuIds)) {
        $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
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
            WHERE mi.menu_id IN ($placeholders) AND mi.organization_id = ?
        ";
        $recipeStmt = $conn->prepare($recipeSql);
        $types = str_repeat('i', count($menuIds)) . 'i';
        $params = array_merge($menuIds, [$org_id]);
        $recipeStmt->bind_param($types, ...$params);
        $recipeStmt->execute();
        $recipeResult = $recipeStmt->get_result();
        while ($row = $recipeResult->fetch_assoc()) {
            $recipes[$row['menu_id']][] = $row;
            $ingredientIds[] = $row['ingredient_id'];
        }
        $recipeStmt->close();
    }
    $ingredientIds = array_unique($ingredientIds);

    // 5. Kalkulasi total kebutuhan bahan baku (dalam Satuan Beli / Purchase Unit)
    $ingredientNeeds = [];
    foreach ($recipes as $menuId => $ingredients) {
        foreach ($ingredients as $ing) {
            if (!isset($ingredientNeeds[$ing['ingredient_id']])) {
                $ingredientNeeds[$ing['ingredient_id']] = [
                    'name' => $ing['ingredient_name'],
                    'total_grams_gross' => 0,
                    'conversion' => (float)$ing['conversion_factor'],
                    'symbol' => $ing['unit_symbol'],
                    'latest_price' => (float)$ing['latest_price']
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
                
                $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 100) / 100;
                if ($bdd_factor <= 0) $bdd_factor = 1.00;

                $total_grams_net_for_day = 0;
                if (is_array($portions)) {
                    foreach ($beneficiary_counts as $cat_id => $count) {
                        $portion_net = (float)($portions[$cat_id] ?? 0);
                        $total_grams_net_for_day += $portion_net * $count;
                    }
                }
                $total_grams_gross_for_day = $total_grams_net_for_day / $bdd_factor;
                $ingredientNeeds[$ing_id]['total_grams_gross'] += $total_grams_gross_for_day;
            }
        }
    }

    // 6. Bandingkan kebutuhan gizi vs stok riil & cari supplier termurah
    $deficits = [];
    foreach ($ingredientNeeds as $id => $ing) {
        if ($ing['conversion'] <= 0) continue;

        $qty_needed = $ing['total_grams_gross'] / $ing['conversion'];

        // Ambil stok saat ini
        $stockSql = "SELECT current_quantity FROM stock WHERE ingredient_id = ? AND organization_id = ? LIMIT 1";
        $stockStmt = $conn->prepare($stockSql);
        $stockStmt->bind_param("ii", $id, $org_id);
        $stockStmt->execute();
        $stockRes = $stockStmt->get_result()->fetch_assoc();
        $stockStmt->close();

        $current_qty = $stockRes ? (float)$stockRes['current_quantity'] : 0.00;

        // --- TAMBAHAN BARU: Ambil jumlah yang sedang dipesan (outstanding PO / pending) ---
        // Menghitung barang yang sudah di-PO tapi belum Selesai agar tidak memicu PO ganda.
        $pendingSql = "SELECT SUM(pi.quantity) as pending_qty 
                       FROM po_items pi 
                       JOIN purchase_orders po ON pi.po_id = po.id 
                       WHERE pi.ingredient_id = ? AND po.organization_id = ? AND po.status != 'Selesai'";
        $pendingStmt = $conn->prepare($pendingSql);
        $pendingStmt->bind_param("ii", $id, $org_id);
        $pendingStmt->execute();
        $pendingRes = $pendingStmt->get_result()->fetch_assoc();
        $pendingStmt->close();
        
        $pending_qty = $pendingRes ? (float)$pendingRes['pending_qty'] : 0.00;
        
        // Total stok virtual = Stok riil + Stok yang sedang dalam pemesanan
        $virtual_available = $current_qty + $pending_qty;

        // Jika kebutuhan > total stok virtual, maka defisit
        if ($qty_needed > $virtual_available) {
            $deficit_qty = $qty_needed - $virtual_available;

            // Cari supplier lokal termurah untuk bahan baku ini
            $supSql = "
                SELECT si.supplier_id, s.supplier_name, si.base_price 
                FROM supplier_ingredients si
                JOIN suppliers s ON si.supplier_id = s.id
                WHERE si.ingredient_id = ? AND s.organization_id = ?
                ORDER BY si.base_price ASC LIMIT 1
            ";
            $supStmt = $conn->prepare($supSql);
            $supStmt->bind_param("ii", $id, $org_id);
            $supStmt->execute();
            $best_supplier = $supStmt->get_result()->fetch_assoc();
            $supStmt->close();

            $deficits[] = [
                'ingredient_id' => $id,
                'ingredient_name' => $ing['name'],
                'unit_symbol' => $ing['symbol'],
                'required_qty' => round($qty_needed, 2),
                'current_qty' => round($current_qty, 2),
                'deficit_qty' => round($deficit_qty, 2),
                'suggested_price' => $best_supplier ? (float)$best_supplier['base_price'] : $ing['latest_price'],
                'suggested_supplier_id' => $best_supplier ? (int)$best_supplier['supplier_id'] : null,
                'suggested_supplier_name' => $best_supplier ? $best_supplier['supplier_name'] : 'Belanja Mandiri'
            ];
        }
    }

    echo json_encode([
        'proposal' => $proposal,
        'deficits' => $deficits
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menghitung prediksi stok kritis.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
