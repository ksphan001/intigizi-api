<?php
// File: app/financials/reports_get_bp_pengeluaran.php
// Penjelasan: API BARU untuk mengambil data Buku Pembantu Pengeluaran.
// Mengambil semua transaksi di mana akun debet adalah jenis 'Biaya'.

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
    // Mengambil semua transaksi di mana akun debet adalah jenis 'Biaya'
    // Ini mencakup semua jenis pengeluaran seperti bahan baku, operasional, sewa, dll.
    $sql = "SELECT 
                t.id,
                t.transaction_date,
                t.description,
                t.amount,
                da.name as debit_account_name,
                u.full_name as created_by_name
            FROM financial_transactions t
            JOIN financial_accounts da ON t.debit_account_id = da.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.organization_id = ? 
            AND da.type = 'Biaya'
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
    echo json_encode(['message' => 'Gagal mengambil data Buku Pembantu Pengeluaran.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
