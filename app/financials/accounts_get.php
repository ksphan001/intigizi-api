<?php
// File: app/financials/accounts_get.php
// Penjelasan: API BARU untuk mengambil daftar semua akun keuangan (Chart of Accounts).
// Data ini akan digunakan untuk mengisi dropdown filter di halaman Buku Pembantu.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // Ambil semua akun global (organization_id IS NULL) DAN
    // akun kustom milik organisasi itu sendiri (jika ada di masa depan).
    $sql = "SELECT id, name, account_code, type 
            FROM financial_accounts 
            WHERE organization_id IS NULL OR organization_id = ? 
            ORDER BY account_code ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $accounts = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($accounts);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data akun keuangan.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
