<?php
// File: app/financials/reports_get_bp_pajak.php
// Penjelasan: API BARU untuk mengambil data Buku Pembantu Pajak.
// Mengambil semua transaksi yang melibatkan akun-akun pajak.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Hanya Akuntan, Yayasan, dan Administrator
if (!in_array($userData['role_id'], [3, 4, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

try {
    // Mengambil transaksi di mana akun debet atau kredit mengandung kata 'Pajak', 'PPN', atau 'PPh'
    $sql = "SELECT 
                t.id,
                t.transaction_date,
                t.description,
                t.amount,
                (CASE WHEN da.name LIKE '%Pungutan%' THEN 'Pungutan' ELSE 'Setoran' END) as type,
                da.name as debit_account_name,
                ca.name as credit_account_name,
                u.full_name as created_by_name
            FROM financial_transactions t
            JOIN financial_accounts da ON t.debit_account_id = da.id
            JOIN financial_accounts ca ON t.credit_account_id = ca.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.organization_id = ? 
            AND (da.name LIKE '%Pungutan%' OR ca.name LIKE '%Pungutan%')
            AND t.transaction_date BETWEEN ? AND ?
            ORDER BY t.transaction_date ASC, t.id ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($transactions);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data Buku Pembantu Pajak.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
