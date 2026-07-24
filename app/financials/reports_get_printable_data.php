<?php
// File: app/financials/reports_get_printable_data.php
// Penjelasan: Diperbarui untuk menghitung "Dana Diajukan" (Estimasi Anggaran)
// menggunakan logika BDD (BERAT KOTOR), agar konsisten dengan semua kalkulasi lain.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

try {
    $response = [
        'organization_info' => [],
        'lpa_data' => [],
        'sptj_data' => [],
        'bapsd_data' => [],
        'dafnom_data' => []
    ];

    // 1. Ambil Informasi Organisasi & Pimpinan
    $org_sql = "SELECT o.name as org_name, o.director_name FROM organizations o WHERE o.id = ?";
    $org_stmt = $conn->prepare($org_sql);
    $org_stmt->bind_param("i", $org_id);
    $org_stmt->execute();
    $response['organization_info'] = $org_stmt->get_result()->fetch_assoc();
    $org_stmt->close();
    
    // --- PERBAIKAN LOGIKA BDD DIMULAI DI SINI ---

    // 2. Kalkulasi "Dana Diajukan" (Estimasi Anggaran)
    // Kita perlu menghitung manual di PHP agar bisa menerapkan logika BDD
    
    // 2a. Ambil total penerima per kategori
    $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count FROM distribution_point_counts dpc JOIN distribution_points dp ON dpc.distribution_point_id = dp.id WHERE dp.organization_id = ? GROUP BY dpc.category_id";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->bind_param("i", $org_id);
    $countsStmt->execute();
    $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $countsStmt->close();
    $beneficiary_counts = [];
    foreach($category_totals_result as $row) { $beneficiary_counts[$row['category_id']] = (int)$row['total_count']; }

    // 2b. Ambil resep untuk proposal yang disetujui dalam rentang tanggal
    $recipesSql = "
        SELECT 
            mi.quantity_per_portion, i.latest_price, u.conversion_factor, nd.bdd_percentage
        FROM proposal_menus pm
        JOIN proposals p ON pm.proposal_id = p.id
        JOIN menu_ingredients mi ON pm.menu_id = mi.menu_id AND mi.organization_id = p.organization_id
        JOIN ingredients i ON mi.ingredient_id = i.id AND i.organization_id = p.organization_id
        JOIN units u ON i.unit_id = u.id
        LEFT JOIN nutrition_data nd ON i.id = nd.ingredient_id AND i.organization_id = nd.organization_id
        WHERE p.organization_id = ? AND p.status = 'Disetujui' AND pm.serving_date BETWEEN ? AND ? AND pm.menu_id != 1
    ";
    $recipe_stmt = $conn->prepare($recipesSql);
    $recipe_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $recipe_stmt->execute();
    $ingredients = $recipe_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recipe_stmt->close();
    
    $dana_diajukan = 0;
    foreach ($ingredients as $ingredient) {
        $portions = json_decode($ingredient['quantity_per_portion'], true);
        
        $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
        if ($bdd_factor <= 0) $bdd_factor = 1.00; // Safety check
        
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
            $dana_diajukan += $grams_needed_gross * $price_per_gram;
        }
    }
    
    // --- PERBAIKAN LOGIKA BDD SELESAI ---

    // 3. Kalkulasi Dana Terealisasi (Pengeluaran) - Logika ini sudah benar
    $realisasi_sql = "SELECT fa.id as account_id, fa.name as account_name, COALESCE(SUM(ft.amount), 0) as total
                      FROM financial_transactions ft
                      JOIN financial_accounts fa ON ft.debit_account_id = fa.id
                      WHERE ft.organization_id = ? AND ft.transaction_date BETWEEN ? AND ?
                      AND fa.type = 'Biaya'
                      GROUP BY fa.id, fa.name";
    $real_stmt = $conn->prepare($realisasi_sql);
    $real_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $real_stmt->execute();
    $realisasi_result = $real_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $real_stmt->close();

    $realisasi_bahan = 0; $realisasi_ops = 0; $realisasi_sewa = 0; $realisasi_tenaga_kerja = 0;
    foreach($realisasi_result as $row){
        if ($row['account_id'] == 4) $realisasi_bahan = (float)$row['total']; // ID 4: Biaya Bahan Baku
        if ($row['account_id'] == 5) $realisasi_ops = (float)$row['total']; // ID 5: Biaya Operasional
        if ($row['account_id'] == 6) $realisasi_sewa = (float)$row['total']; // ID 6: Biaya Sewa
        if ($row['account_id'] == 9) $realisasi_tenaga_kerja = (float)$row['total']; // ID 9: Biaya Tenaga Kerja
    }
    $total_realisasi = $realisasi_bahan + $realisasi_ops + $realisasi_sewa + $realisasi_tenaga_kerja;

    $response['lpa_data'] = [
        'dana_diajukan' => ['bahan' => $dana_diajukan, 'operasional' => 0, 'sewa' => 0, 'tenaga_kerja' => 0, 'total' => $dana_diajukan],
        'dana_terealisasi' => ['bahan' => $realisasi_bahan, 'operasional' => $realisasi_ops, 'sewa' => $realisasi_sewa, 'tenaga_kerja' => $realisasi_tenaga_kerja, 'total' => $total_realisasi],
        'sisa_dana' => ['total' => $dana_diajukan - $total_realisasi]
    ];

    // 4. Kalkulasi Data untuk SPTJ & BAPSD (Logika ini tetap benar)
    $penerimaan_sql = "SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE organization_id = ? AND transaction_date BETWEEN ? AND ? AND debit_account_id IN (1,2)";
    $penerimaan_stmt = $conn->prepare($penerimaan_sql);
    $penerimaan_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $penerimaan_stmt->execute();
    $total_penerimaan = (float)$penerimaan_stmt->get_result()->fetch_assoc()['total'];
    $penerimaan_stmt->close();

    $pengeluaran_sql = "SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE organization_id = ? AND transaction_date BETWEEN ? AND ? AND credit_account_id IN (1,2)";
    $pengeluaran_stmt = $conn->prepare($pengeluaran_sql);
    $pengeluaran_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $pengeluaran_stmt->execute();
    $total_pengeluaran = (float)$pengeluaran_stmt->get_result()->fetch_assoc()['total'];
    $pengeluaran_stmt->close();
    
    $sisa_dana_kas = $total_penerimaan - $total_pengeluaran;

    $response['sptj_data'] = [ 'total_penerimaan' => $total_penerimaan, 'total_pengeluaran' => $total_pengeluaran, 'sisa_dana' => $sisa_dana_kas ];
    $response['bapsd_data'] = [ 'sisa_dana' => $sisa_dana_kas ];

    // 5. Ambil data untuk DafNom (Logika ini tetap benar)
    $dafnom_sql = "SELECT v.full_name, v.job_type, hp.honorarium_amount, hp.health_fund_amount, hp.tax_amount, hp.total_amount
                   FROM honorarium_payments hp
                   JOIN volunteers v ON hp.volunteer_id = v.id
                   WHERE hp.organization_id = ? AND hp.payment_date BETWEEN ? AND ?";
    $dafnom_stmt = $conn->prepare($dafnom_sql);
    $dafnom_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $dafnom_stmt->execute();
    $response['dafnom_data'] = $dafnom_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dafnom_stmt->close();
    
    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data laporan cetak.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>