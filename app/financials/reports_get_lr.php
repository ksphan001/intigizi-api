<?php
// File: app/financials/reports_get_lr.php
// Penjelasan: API BARU untuk menghasilkan data Laporan Resume (LR).

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Helper function untuk menjalankan query SUM
function get_sum($conn, $sql, $params, $types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $result;
}

try {
    $response = [];
    $periods = [
        'previous' => ['start' => '1970-01-01', 'end' => date('Y-m-d', strtotime($start_date . ' -1 day'))],
        'ongoing' => ['start' => $start_date, 'end' => $end_date]
    ];
    
    $results = [];

    foreach ($periods as $key => $period) {
        // --- PERBAIKAN DI SINI ---
        // Menambahkan ID Akun "Modal Awal" (ID 7) ke dalam perhitungan Penerimaan.
        $results[$key]['penerimaan_bantuan'] = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND credit_account_id IN (3, 8, 7) AND transaction_date BETWEEN ? AND ?", [$org_id, $period['start'], $period['end']], "iss");
        
        // PENGELUARAN
        $results[$key]['pengeluaran_bahan_baku'] = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND debit_account_id = 4 AND transaction_date BETWEEN ? AND ?", [$org_id, $period['start'], $period['end']], "iss");
        $results[$key]['pengeluaran_operasional'] = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND debit_account_id = 5 AND transaction_date BETWEEN ? AND ?", [$org_id, $period['start'], $period['end']], "iss");
        $results[$key]['pengeluaran_sewa'] = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND debit_account_id = 6 AND transaction_date BETWEEN ? AND ?", [$org_id, $period['start'], $period['end']], "iss");
        $results[$key]['pengeluaran_tenaga_kerja'] = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND debit_account_id = 9 AND transaction_date BETWEEN ? AND ?", [$org_id, $period['start'], $period['end']], "iss");
    }

    // SALDO BUKU KAS (dihitung sampai akhir periode 'ongoing')
    $kas_tunai_debit = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND debit_account_id = 1 AND transaction_date <= ?", [$org_id, $end_date], "is");
    $kas_tunai_credit = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND credit_account_id = 1 AND transaction_date <= ?", [$org_id, $end_date], "is");
    $results['saldo_kas_tunai'] = $kas_tunai_debit - $kas_tunai_credit;

    $kas_bank_debit = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND debit_account_id = 2 AND transaction_date <= ?", [$org_id, $end_date], "is");
    $kas_bank_credit = get_sum($conn, "SELECT SUM(amount) as total FROM financial_transactions WHERE organization_id = ? AND credit_account_id = 2 AND transaction_date <= ?", [$org_id, $end_date], "is");
    $results['saldo_kas_bank'] = $kas_bank_debit - $kas_bank_credit;
    
    http_response_code(200);
    echo json_encode($results);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data Laporan Resume.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
