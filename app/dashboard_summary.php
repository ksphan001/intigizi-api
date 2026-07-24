<?php
// File: app/dashboard_summary.php
// PENJELASAN: Logika `total_realized_spending` diubah total untuk mengambil data dari
// financial_transactions agar semua jenis biaya (bahan baku, operasional, honorarium) terhitung.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$response = [
    'total_menus' => 0,
    'total_ingredients' => 0,
    'total_suppliers' => 0,
    'proposal_summary' => ['Draft' => 0, 'Diajukan' => 0, 'Ditolak' => 0, 'Disetujui' => 0],
    'total_estimated_budget' => 0,
    'total_realized_spending' => 0,
    'daily_distribution' => [],
    'production_schedule' => [],
    'low_stock_items' => [],
    'distribution_points' => []
];

try {
    // Helper function
    function get_count($conn, $sql, $params = null) {
        $stmt = $conn->prepare($sql);
        if ($params) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($result['count'] ?? 0);
    }
    
    // Total Data Master
    $response['total_menus'] = get_count($conn, "SELECT COUNT(id) as count FROM menus WHERE organization_id = ?", [$org_id]);
    $response['total_ingredients'] = get_count($conn, "SELECT COUNT(id) as count FROM ingredients WHERE organization_id = ?", [$org_id]);
    $response['total_suppliers'] = get_count($conn, "SELECT COUNT(id) as count FROM suppliers WHERE organization_id = ?", [$org_id]);

    // Ringkasan Proposal
    $prop_sql = "SELECT status, COUNT(id) as count FROM proposals WHERE organization_id = ? GROUP BY status";
    $prop_stmt = $conn->prepare($prop_sql);
    $prop_stmt->bind_param("i", $org_id);
    $prop_stmt->execute();
    $prop_result = $prop_stmt->get_result();
    while($row = $prop_result->fetch_assoc()) {
        if (array_key_exists($row['status'], $response['proposal_summary'])) {
            $response['proposal_summary'][$row['status']] = (int)$row['count'];
        }
    }
    $prop_stmt->close();

    // Logika Perhitungan Anggaran (Estimasi & Realisasi)
    $total_estimated_budget = 0;
    $proposals_sql = "SELECT id FROM proposals WHERE organization_id = ? AND status = 'Disetujui'";
    $proposals_stmt = $conn->prepare($proposals_sql);
    $proposals_stmt->bind_param("i", $org_id);
    $proposals_stmt->execute();
    $proposals = $proposals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $proposals_stmt->close();

    if (!empty($proposals)) {
        // ... (Logika estimasi anggaran tetap sama) ...
        $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count FROM distribution_point_counts dpc JOIN distribution_points dp ON dpc.distribution_point_id = dp.id WHERE dp.organization_id = ? GROUP BY dpc.category_id";
        $countsStmt = $conn->prepare($countsSql);
        $countsStmt->bind_param("i", $org_id);
        $countsStmt->execute();
        $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $countsStmt->close();
        $beneficiary_counts = [];
        foreach($category_totals_result as $row) { $beneficiary_counts[$row['category_id']] = (int)$row['total_count']; }
        $total_all_beneficiaries = array_sum($beneficiary_counts);

        $proposal_ids = array_column($proposals, 'id');
        $placeholders = implode(',', array_fill(0, count($proposal_ids), '?'));
        $recipesSql = "
            SELECT mi.quantity_per_portion, i.latest_price, u.conversion_factor
            FROM proposal_menus pm
            JOIN menu_ingredients mi ON pm.menu_id = mi.menu_id AND mi.organization_id = pm.organization_id
            JOIN ingredients i ON mi.ingredient_id = i.id AND i.organization_id = pm.organization_id
            JOIN units u ON i.unit_id = u.id
            WHERE pm.proposal_id IN ($placeholders) AND pm.menu_id != 1";
        $recipeStmt = $conn->prepare($recipesSql);
        $recipeStmt->bind_param(str_repeat('i', count($proposal_ids)), ...$proposal_ids);
        $recipeStmt->execute();
        $ingredients = $recipeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recipeStmt->close();

        foreach ($ingredients as $ingredient) {
            $portions_json = $ingredient['quantity_per_portion'];
            $portions = json_decode($portions_json, true);
            $grams_needed = 0;

            if (is_array($portions)) {
                foreach ($beneficiary_counts as $cat_id => $count) { $grams_needed += (float)($portions[$cat_id] ?? 0) * $count; }
            } elseif (is_numeric($portions_json)) {
                $grams_needed = (float)$portions_json * $total_all_beneficiaries;
            }

            if ($ingredient['conversion_factor'] > 0) {
                $price_per_gram = (float)$ingredient['latest_price'] / (float)$ingredient['conversion_factor'];
                $total_estimated_budget += $grams_needed * $price_per_gram;
            }
        }
    }
    $response['total_estimated_budget'] = $total_estimated_budget;

    // --- PERUBAHAN UTAMA DI SINI ---
    // Mengambil total realisasi dari Jurnal Keuangan, bukan hanya dari PO.
    // Ini akan mencakup semua biaya: Bahan Baku (4), Operasional (5), Sewa (6), Tenaga Kerja (9).
    $spending_sql = "SELECT SUM(amount) as total FROM financial_transactions 
                     WHERE organization_id = ? AND debit_account_id IN (4, 5, 6, 9)";
    $spending_stmt = $conn->prepare($spending_sql);
    $spending_stmt->bind_param("i", $org_id);
    $spending_stmt->execute();
    $spending_res = $spending_stmt->get_result()->fetch_assoc();
    $response['total_realized_spending'] = (float)($spending_res['total'] ?? 0);
    $spending_stmt->close();
    // --- AKHIR PERUBAHAN ---


    // Kinerja Distribusi 7 Hari Terakhir
    $dist_sql = "SELECT DATE(distribution_date) as date, SUM(quantity_sent) as sent, SUM(quantity_received) as received FROM distribution_reports WHERE organization_id = ? AND distribution_date >= CURDATE() - INTERVAL 7 DAY GROUP BY DATE(distribution_date) ORDER BY date ASC";
    $dist_stmt = $conn->prepare($dist_sql);
    $dist_stmt->bind_param("i", $org_id);
    $dist_stmt->execute();
    $response['daily_distribution'] = $dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dist_stmt->close();

    // Jadwal Produksi Mendatang
    $sched_sql = "SELECT pm.serving_date, m.menu_name, p.target_recipients FROM proposal_menus pm JOIN menus m ON pm.menu_id = m.id JOIN proposals p ON pm.proposal_id = p.id WHERE p.organization_id = ? AND p.status = 'Disetujui' AND pm.serving_date >= CURDATE() AND pm.menu_id != 1 ORDER BY pm.serving_date ASC LIMIT 5";
    $sched_stmt = $conn->prepare($sched_sql);
    $sched_stmt->bind_param("i", $org_id);
    $sched_stmt->execute();
    $response['production_schedule'] = $sched_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sched_stmt->close();

    // Stok Kritis
    $stock_sql = "SELECT i.id as ingredient_id, i.name as ingredient_name, (s.current_quantity / u.conversion_factor) as current_quantity, u.symbol as unit_symbol FROM stock s JOIN ingredients i ON s.ingredient_id = i.id JOIN units u ON i.unit_id = u.id WHERE s.organization_id = ? AND s.current_quantity < 5000 ORDER BY s.current_quantity ASC LIMIT 5";
    $stock_stmt = $conn->prepare($stock_sql);
    $stock_stmt->bind_param("i", $org_id);
    $stock_stmt->execute();
    $response['low_stock_items'] = $stock_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stock_stmt->close();
    
    // Titik Distribusi untuk Peta
    $points_sql = "SELECT id, name, latitude, longitude, address FROM distribution_points WHERE organization_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL";
    $points_stmt = $conn->prepare($points_sql);
    $points_stmt->bind_param("i", $org_id);
    $points_stmt->execute();
    $response['distribution_points'] = $points_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $points_stmt->close();

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error internal pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>

