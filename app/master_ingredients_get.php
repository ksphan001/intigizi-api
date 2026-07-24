<?php
// File: app/master_ingredients_get.php
// Penjelasan: API endpoint baru untuk mengambil daftar bahan baku master
// dari tabel `master_ingredients` untuk ditampilkan di modal "Tambah dari Pustaka".

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

// Verifikasi token untuk memastikan hanya pengguna terautentikasi yang bisa akses
verify_jwt_token();

header('Content-Type: application/json');

try {
    $sql = "SELECT id, name, `group` FROM master_ingredients ORDER BY `group` ASC, name ASC";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan query: " . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $master_ingredients = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($master_ingredients);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi kesalahan pada server saat mengambil data pustaka bahan.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
