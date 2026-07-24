<?php
// File: app/vendor_categories_get.php
// Penjelasan: API publik untuk mengambil daftar kategori vendor.
// Digunakan di halaman registrasi agar pendaftar bisa memilih kategori.

require_once __DIR__ . '/config.php';

// Endpoint ini tidak memerlukan otentikasi karena digunakan di halaman publik.

try {
    $result = $conn->query("SELECT id, name FROM vendor_categories ORDER BY name ASC");
    if ($result === false) {
        throw new Exception("Query gagal: " . $conn->error);
    }
    $categories = $result->fetch_all(MYSQLI_ASSOC);
    http_response_code(200);
    echo json_encode($categories);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data kategori.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
