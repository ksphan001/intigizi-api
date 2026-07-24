<?php
// File: app/financials/reports_get_bku.php
// Penjelasan: Logika disempurnakan untuk stabilitas dan kejelasan, memastikan
// semua perhitungan saldo awal, transaksi, dan saldo akhir akurat.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

if (!in_array($userData['role_id'], [3, 7, 4])) { // Akuntan, Admin, Yayasan
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

if ($account_id === 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Silakan pilih akun kas/bank terlebih dahulu.']);
    exit();
}

try {
    $response = [
        'opening_balance' => 0.00,
        'transactions' => [],
        'total_debit' => 0.00,
        'total_credit' => 0.00,
        'closing_balance' => 0.00,
    ];

    // 1. Hitung Saldo Awal (semua transaksi SEBELUM start_date untuk akun ini)
    $opening_sql = "SELECT 
                        COALESCE(SUM(CASE WHEN debit_account_id = ? THEN amount ELSE 0 END), 0) as total_debit,
                        COALESCE(SUM(CASE WHEN credit_account_id = ? THEN amount ELSE 0 END), 0) as total_credit
                    FROM financial_transactions
                    WHERE organization_id = ? AND transaction_date < ?";
    $stmt = $conn->prepare($opening_sql);
    $stmt->bind_param("iiis", $account_id, $account_id, $org_id, $start_date);
    $stmt->execute();
    $opening_result = $stmt->get_result()->fetch_assoc();
    $response['opening_balance'] = (float)$opening_result['total_debit'] - (float)$opening_result['total_credit'];
    $stmt->close();

    // 2. Ambil semua transaksi PADA PERIODE yang dipilih yang melibatkan akun ini
    $transactions_sql = "SELECT 
                            id,
                            transaction_date,
                            description,
                            (CASE WHEN debit_account_id = ? THEN amount ELSE 0 END) as debit,
                            (CASE WHEN credit_account_id = ? THEN amount ELSE 0 END) as credit
                         FROM financial_transactions
                         WHERE organization_id = ? 
                           AND (debit_account_id = ? OR credit_account_id = ?)
                           AND transaction_date BETWEEN ? AND ?
                         ORDER BY transaction_date ASC, id ASC";
    $stmt = $conn->prepare($transactions_sql);
    $stmt->bind_param("iiiiiss", $account_id, $account_id, $org_id, $account_id, $account_id, $start_date, $end_date);
    $stmt->execute();
    $transactions_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // 3. Hitung saldo berjalan (running balance) dan total periode
    $running_balance = $response['opening_balance'];
    foreach($transactions_result as $row) {
        $running_balance += (float)$row['debit'] - (float)$row['credit'];
        $response['transactions'][] = [
            'id' => $row['id'],
            'transaction_date' => $row['transaction_date'],
            'description' => $row['description'],
            'debit' => (float)$row['debit'],
            'credit' => (float)$row['credit'],
            'balance' => $running_balance
        ];
        $response['total_debit'] += (float)$row['debit'];
        $response['total_credit'] += (float)$row['credit'];
    }
    $stmt->close();
    
    // 4. Saldo akhir adalah saldo berjalan terakhir
    $response['closing_balance'] = $running_balance;

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data BKU.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
