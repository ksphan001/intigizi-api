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
    'distribution_points' => [],
    'nutritional_status_summary' => ['Sangat Kurus' => 0, 'Kurus' => 0, 'Normal' => 0, 'Kelebihan Berat Badan' => 0, 'Obesitas' => 0]
];

try {
    // Mengambil list org_id yang dapat diakses oleh user
    $accessible_org_ids = get_accessible_organization_ids($userData, $conn);
    
    // Menentukan org_id yang dipilih (jika dikirim dari frontend)
    $selected_sppg_id = $_GET['sppg_id'] ?? 'all';
    $target_org_ids = [];

    if ($selected_sppg_id !== 'all' && is_numeric($selected_sppg_id)) {
        $selected_sppg_id = (int)$selected_sppg_id;
        if (in_array($selected_sppg_id, $accessible_org_ids)) {
            $target_org_ids = [$selected_sppg_id];
        } else {
            $target_org_ids = [$org_id];
        }
    } else {
        $target_org_ids = $accessible_org_ids;
    }

    $org_ids_str = implode(',', $target_org_ids);
    if (empty($org_ids_str)) {
        $org_ids_str = '0';
    }

    // Helper function
    function get_count($conn, $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($result['count'] ?? 0);
    }
    
    // Total Data Master
    $response['total_menus'] = get_count($conn, "SELECT COUNT(id) as count FROM menus WHERE organization_id IN ($org_ids_str)");
    $response['total_ingredients'] = get_count($conn, "SELECT COUNT(id) as count FROM ingredients WHERE organization_id IN ($org_ids_str)");
    $response['total_suppliers'] = get_count($conn, "SELECT COUNT(id) as count FROM suppliers WHERE organization_id IN ($org_ids_str)");

    // Ringkasan Proposal
    $prop_sql = "SELECT status, COUNT(id) as count FROM proposals WHERE organization_id IN ($org_ids_str) GROUP BY status";
    $prop_stmt = $conn->prepare($prop_sql);
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
    $proposals_sql = "SELECT id, organization_id FROM proposals WHERE organization_id IN ($org_ids_str) AND status = 'Disetujui'";
    $proposals_stmt = $conn->prepare($proposals_sql);
    $proposals_stmt->execute();
    $proposals = $proposals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $proposals_stmt->close();

    if (!empty($proposals)) {
        $countsSql = "SELECT dp.organization_id, dpc.category_id, SUM(dpc.count) as total_count FROM distribution_point_counts dpc JOIN distribution_points dp ON dpc.distribution_point_id = dp.id WHERE dp.organization_id IN ($org_ids_str) GROUP BY dp.organization_id, dpc.category_id";
        $countsStmt = $conn->prepare($countsSql);
        $countsStmt->execute();
        $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $countsStmt->close();
        
        $beneficiary_counts = [];
        $total_beneficiaries_per_org = [];
        foreach($category_totals_result as $row) {
            $o_id = (int)$row['organization_id'];
            $cat_id = (int)$row['category_id'];
            $cnt = (int)$row['total_count'];
            $beneficiary_counts[$o_id][$cat_id] = $cnt;
            $total_beneficiaries_per_org[$o_id] = ($total_beneficiaries_per_org[$o_id] ?? 0) + $cnt;
        }

        $proposal_ids = array_column($proposals, 'id');
        $placeholders = implode(',', array_fill(0, count($proposal_ids), '?'));
        $recipesSql = "
            SELECT pm.proposal_id, pm.organization_id, mi.quantity_per_portion, i.latest_price, u.conversion_factor
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
            $o_id = (int)$ingredient['organization_id'];
            $portions_json = $ingredient['quantity_per_portion'];
            $portions = json_decode($portions_json, true);
            $grams_needed = 0;

            $org_beneficiary_counts = $beneficiary_counts[$o_id] ?? [];
            $total_all_beneficiaries = $total_beneficiaries_per_org[$o_id] ?? 0;

            if (is_array($portions)) {
                foreach ($org_beneficiary_counts as $cat_id => $count) { $grams_needed += (float)($portions[$cat_id] ?? 0) * $count; }
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

    // Mengambil total realisasi dari Jurnal Keuangan
    $spending_sql = "SELECT SUM(amount) as total FROM financial_transactions 
                     WHERE organization_id IN ($org_ids_str) AND debit_account_id IN (4, 5, 6, 9)";
    $spending_stmt = $conn->prepare($spending_sql);
    $spending_stmt->execute();
    $spending_res = $spending_stmt->get_result()->fetch_assoc();
    $response['total_realized_spending'] = (float)($spending_res['total'] ?? 0);
    $spending_stmt->close();

    // Kinerja Distribusi 7 Hari Terakhir
    $dist_sql = "SELECT DATE(distribution_date) as date, SUM(quantity_sent) as sent, SUM(quantity_received) as received FROM distribution_reports WHERE organization_id IN ($org_ids_str) AND distribution_date >= CURDATE() - INTERVAL 7 DAY GROUP BY DATE(distribution_date) ORDER BY date ASC";
    $dist_stmt = $conn->prepare($dist_sql);
    $dist_stmt->execute();
    $response['daily_distribution'] = $dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dist_stmt->close();

    // Jadwal Produksi Mendatang
    $sched_sql = "SELECT pm.serving_date, m.menu_name, p.target_recipients, o.name as organization_name FROM proposal_menus pm JOIN menus m ON pm.menu_id = m.id JOIN proposals p ON pm.proposal_id = p.id JOIN organizations o ON p.organization_id = o.id WHERE p.organization_id IN ($org_ids_str) AND p.status = 'Disetujui' AND pm.serving_date >= CURDATE() AND pm.menu_id != 1 ORDER BY pm.serving_date ASC LIMIT 5";
    $sched_stmt = $conn->prepare($sched_sql);
    $sched_stmt->execute();
    $response['production_schedule'] = $sched_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sched_stmt->close();

    // Stok Kritis
    $stock_sql = "SELECT i.id as ingredient_id, i.name as ingredient_name, (s.current_quantity / u.conversion_factor) as current_quantity, u.symbol as unit_symbol, o.name as organization_name FROM stock s JOIN ingredients i ON s.ingredient_id = i.id JOIN units u ON i.unit_id = u.id JOIN organizations o ON s.organization_id = o.id WHERE s.organization_id IN ($org_ids_str) AND s.current_quantity < 5000 ORDER BY s.current_quantity ASC LIMIT 5";
    $stock_stmt = $conn->prepare($stock_sql);
    $stock_stmt->execute();
    $response['low_stock_items'] = $stock_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stock_stmt->close();
    
    // Titik Distribusi untuk Peta
    $points_sql = "SELECT dp.id, dp.name, dp.latitude, dp.longitude, dp.address, o.name as organization_name FROM distribution_points dp JOIN organizations o ON dp.organization_id = o.id WHERE dp.organization_id IN ($org_ids_str) AND dp.latitude IS NOT NULL AND dp.longitude IS NOT NULL";
    $points_stmt = $conn->prepare($points_sql);
    $points_stmt->execute();
    $response['distribution_points'] = $points_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $points_stmt->close();

    // Analisis Status Gizi Penerima Manfaat (BMI / Stunting Analysis)
    $nutri_sql = "SELECT 
                    CASE 
                        WHEN current_bmi < 16.5 THEN 'Sangat Kurus'
                        WHEN current_bmi >= 16.5 AND current_bmi < 18.5 THEN 'Kurus'
                        WHEN current_bmi >= 18.5 AND current_bmi < 25.0 THEN 'Normal'
                        WHEN current_bmi >= 25.0 AND current_bmi < 30.0 THEN 'Kelebihan Berat Badan'
                        ELSE 'Obesitas'
                    END as status,
                    COUNT(id) as count
                  FROM beneficiaries
                  WHERE organization_id IN ($org_ids_str) AND current_bmi IS NOT NULL AND current_bmi > 0
                  GROUP BY status";
    $nutri_stmt = $conn->prepare($nutri_sql);
    $nutri_stmt->execute();
    $nutri_res = $nutri_stmt->get_result();
    while ($row = $nutri_res->fetch_assoc()) {
        if (array_key_exists($row['status'], $response['nutritional_status_summary'])) {
            $response['nutritional_status_summary'][$row['status']] = (int)$row['count'];
        }
    }
    $nutri_stmt->close();

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

