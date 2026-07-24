<?php
// File: app/public_provinces_list_get.php
// Penjelasan: API publik baru untuk mengambil daftar lengkap provinsi
// dari tabel 'provinces' untuk digunakan di dropdown.

require_once __DIR__ . '/config.php';

try {
    $sql = "SELECT name FROM provinces ORDER BY name ASC";
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    // Mengambil hanya kolom 'name' untuk dijadikan array sederhana
    $provinces = $result->fetch_all(MYSQLI_ASSOC);
    $province_names = array_column($provinces, 'name');
    
    http_response_code(200);
    echo json_encode($province_names);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi error internal pada server.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
