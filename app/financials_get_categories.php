<?php
// File: app/financials_get_categories.php
// Penjelasan: API endpoint BARU yang lebih sederhana, khusus untuk mengambil
// daftar kategori biaya. Dapat diakses oleh pengguna yang sudah login (seperti Akuntan)
// untuk mengisi dropdown pada form, tanpa memberikan hak akses untuk mengelola kategori.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

// Cukup verifikasi bahwa pengguna sudah login, tidak perlu cek peran spesifik.
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // Ambil semua kategori global (organization_id IS NULL) DAN
    // kategori kustom milik organisasi itu sendiri (jika ada di masa depan).
    $sql = "SELECT id, name 
            FROM expense_categories 
            WHERE organization_id IS NULL OR organization_id = ? 
            ORDER BY name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $categories = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($categories);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data kategori biaya.']);
} finally {
    if (isset($conn)) $conn->close();
}
?>
