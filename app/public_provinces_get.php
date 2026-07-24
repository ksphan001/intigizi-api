<?php
// File: app/public_provinces_get.php
// PENJELASAN: API ini mengambil daftar unik nama-nama provinsi dari semua
// vendor yang aktif. Ini digunakan untuk mengisi dropdown filter di halaman direktori.

require_once __DIR__ . '/config.php';

try {
    // Query ini memastikan hanya provinsi yang memiliki setidaknya satu vendor aktif yang akan muncul di filter.
    $sql = "SELECT DISTINCT province 
            FROM organizations 
            WHERE registration_type = 'Vendor' AND is_active = 1 AND province IS NOT NULL AND province != ''
            ORDER BY province ASC";
            
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $provinces = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    // Mengembalikan array of strings (contoh: ["JAWA BARAT", "BANTEN"]) untuk kemudahan di frontend.
    echo json_encode(array_column($provinces, 'province'));

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
