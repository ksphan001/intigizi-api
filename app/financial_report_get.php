<?php
// File: app/financial_report_get.php
// Penjelasan: Logika kalkulasi anggaran diubah untuk menggunakan BERAT KOTOR (BDD).
// Realisasi tetap mengambil dari Jurnal Keuangan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

try {
    $response = [
        'summary' => ['total_estimated_budget' => 0, 'total_realized_spending' => 0, 'variance' => 0],
        'spending_breakdown' => ['procurement' => 0, 'operational' => 0],
        'recent_pos' => [],
        'recent_expenses' => []
    ];

    // --- KALKULASI ESTIMASI ANGGARAN (Logika BDD Diterapkan) ---
    $proposals_sql = "SELECT id FROM proposals WHERE organization_id = ? AND status = 'Disetujui'";
    $proposals_stmt = $conn->prepare($proposals_sql);
    $proposals_stmt->bind_param("i", $org_id);
    $proposals_stmt->execute();
    $proposals = $proposals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $proposals_stmt->close();

    $total_estimated_budget = 0;
    if (!empty($proposals)) {
        $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count FROM distribution_point_counts dpc JOIN distribution_points dp ON dpc.distribution_point_id = dp.id WHERE dp.organization_id = ? GROUP BY dpc.category_id";
        $countsStmt = $conn->prepare($countsSql);
        $countsStmt->bind_param("i", $org_id);
        $countsStmt->execute();
        $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $countsStmt->close();
        $beneficiary_counts = [];
        foreach($category_totals_result as $row) { $beneficiary_counts[$row['category_id']] = (int)$row['total_count']; }

        $proposal_ids = array_column($proposals, 'id');
        $placeholders = implode(',', array_fill(0, count($proposal_ids), '?'));
        
        // --- PERUBAHAN: JOIN nutrition_data untuk ambil bdd_percentage ---
        $recipesSql = "
            SELECT mi.quantity_per_portion, i.latest_price, u.conversion_factor, nd.bdd_percentage
            FROM proposal_menus pm
            JOIN menu_ingredients mi ON pm.menu_id = mi.menu_id AND mi.organization_id = pm.organization_id
            JOIN ingredients i ON mi.ingredient_id = i.id AND i.organization_id = pm.organization_id
            JOIN units u ON i.unit_id = u.id
            LEFT JOIN nutrition_data nd ON i.id = nd.ingredient_id AND i.organization_id = nd.organization_id
            WHERE pm.proposal_id IN ($placeholders) AND pm.serving_date BETWEEN ? AND ? AND pm.menu_id != 1";
            
        $recipe_params = array_merge($proposal_ids, [$start_date, $end_date]);
        $recipe_types = str_repeat('i', count($proposal_ids)) . 'ss';
        $recipeStmt = $conn->prepare($recipesSql);
        $recipeStmt->bind_param($recipe_types, ...$recipe_params);
        $recipeStmt->execute();
        $ingredients = $recipeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recipeStmt->close();

        foreach ($ingredients as $ingredient) {
            $portions = json_decode($ingredient['quantity_per_portion'], true);
            
            // --- LOGIKA BDD ---
            $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
            if ($bdd_factor <= 0) $bdd_factor = 1.00;
            // ------------------
            
            $grams_needed_net = 0;
            if (is_array($portions)) {
                foreach ($beneficiary_counts as $cat_id => $count) { 
                    $grams_needed_net += (float)($portions[$cat_id] ?? 0) * $count; 
                }
            }
            
            // Konversi ke BERAT KOTOR
            $grams_needed_gross = $grams_needed_net / $bdd_factor;
            
            // Hitung HPP dari BERAT KOTOR
            if ($ingredient['conversion_factor'] > 0) {
                $price_per_gram = (float)$ingredient['latest_price'] / (float)$ingredient['conversion_factor'];
                $total_estimated_budget += $grams_needed_gross * $price_per_gram;
            }
        }
    }
    $response['summary']['total_estimated_budget'] = $total_estimated_budget;

    // --- KALKULASI BELANJA DARI JURNAL (Logika ini tetap) ---
    $spendingSql = "SELECT 
                        SUM(CASE WHEN debit_account_id = 4 THEN amount ELSE 0 END) as procurement_total,
                        SUM(CASE WHEN debit_account_id IN (5, 6, 9) THEN amount ELSE 0 END) as operational_total
                    FROM financial_transactions
                    WHERE organization_id = ? AND transaction_date BETWEEN ? AND ? AND debit_account_id IN (4, 5, 6, 9)";
    $spendingStmt = $conn->prepare($spendingSql);
    $spendingStmt->bind_param("iss", $org_id, $start_date, $end_date);
    $spendingStmt->execute();
    $spending_result = $spendingStmt->get_result()->fetch_assoc();
    $spendingStmt->close();

    $procurement_spending = (float)($spending_result['procurement_total'] ?? 0);
    $operational_spending = (float)($spending_result['operational_total'] ?? 0);
    
    $response['spending_breakdown']['procurement'] = $procurement_spending;
    $response['spending_breakdown']['operational'] = $operational_spending;
    
    $response['summary']['total_realized_spending'] = $procurement_spending + $operational_spending;
    $response['summary']['variance'] = $response['summary']['total_estimated_budget'] - $response['summary']['total_realized_spending'];

    // --- AMBIL 5 PO TERBARU (Logika ini tetap) ---
    $recentPoSql = "SELECT po.id, po.po_code, po.total_amount, po.created_at, COALESCE(s.supplier_name, o.name) as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id AND s.organization_id = po.organization_id LEFT JOIN organizations o ON po.supplier_id = o.id AND o.registration_type = 'Vendor' WHERE po.organization_id = ? AND DATE(po.created_at) BETWEEN ? AND ? ORDER BY po.created_at DESC LIMIT 5";
    $recentPoStmt = $conn->prepare($recentPoSql);
    $recentPoStmt->bind_param("iss", $org_id, $start_date, $end_date);
    $recentPoStmt->execute();
    $response['recent_pos'] = $recentPoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentPoStmt->close();

    // --- AMBIL 5 BIAYA OPERASIONAL/SEWA/HONORARIUM TERBARU DARI JURNAL (PERBAIKAN KECIL) ---
    // Memperbaiki query yang error dari file asli Anda (menambahkan JOIN)
    $recentExpenseSql = "SELECT ft.id, ft.description, ft.amount, ft.transaction_date, fa.name as category_name 
                         FROM financial_transactions ft
                         JOIN financial_accounts fa ON ft.debit_account_id = fa.id
                         WHERE ft.organization_id = ? AND ft.debit_account_id IN (5, 6, 9) AND ft.transaction_date BETWEEN ? AND ? 
                         ORDER BY ft.transaction_date DESC, ft.id DESC LIMIT 5";
    $recentExpenseStmt = $conn->prepare($recentExpenseSql);
    $recentExpenseStmt->bind_param("iss", $org_id, $start_date, $end_date);
    $recentExpenseStmt->execute();
    $response['recent_expenses'] = $recentExpenseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentExpenseStmt->close();

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>