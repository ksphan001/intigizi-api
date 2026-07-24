<?php
// File: app/beneficiary_categories_get.php
// Deskripsi: API endpoint BARU untuk mengambil daftar semua kategori penerima manfaat.
// Akan digunakan untuk mengisi dropdown di form-form frontend.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

// Cukup verifikasi bahwa pengguna sudah login, tidak perlu cek peran spesifik.
verify_jwt_token();

try {
    // Ambil semua kategori dari tabel baru, diurutkan berdasarkan sort_order.
    $sql = "SELECT id, name FROM beneficiary_categories ORDER BY sort_order ASC";
    
    $result = $conn->query($sql);
    $categories = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($categories);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data kategori penerima manfaat.']);
} finally {
    if (isset($conn)) $conn->close();
}
?>
