<?php
// File: app/public_get_track_filters.php
// Deskripsi: API publik untuk mengambil data filter (Provinsi, Kabupaten, Dapur)
// yang akan digunakan di halaman Lacak Distribusi.

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $response = [
        'provinces' => [],
        'regencies' => [],
        'kitchens'  => []
    ];

    // 1. Ambil semua provinsi yang memiliki dapur aktif
    $sql_provinces = "SELECT DISTINCT p.id, p.name 
                      FROM provinces p
                      JOIN organizations o ON p.id = o.province_id
                      WHERE o.is_active = 1 AND o.registration_type = 'Mitra Dapur'
                      ORDER BY p.name ASC";
    $result_provinces = $conn->query($sql_provinces);
    if ($result_provinces) {
        $response['provinces'] = $result_provinces->fetch_all(MYSQLI_ASSOC);
    }

    // 2. Ambil semua kabupaten/kota yang memiliki dapur aktif
    $sql_regencies = "SELECT DISTINCT r.id, r.province_id, r.name 
                      FROM regencies r
                      JOIN organizations o ON r.id = o.regency_id
                      WHERE o.is_active = 1 AND o.registration_type = 'Mitra Dapur'
                      ORDER BY r.name ASC";
    $result_regencies = $conn->query($sql_regencies);
    if ($result_regencies) {
        $response['regencies'] = $result_regencies->fetch_all(MYSQLI_ASSOC);
    }

    // --- PERBAIKAN DI SINI ---
    // 3. Ambil semua dapur (organisasi) yang aktif, tetapi tampilkan nama dapur utama, bukan nama organisasi.
    $sql_kitchens = "SELECT 
                        o.id, 
                        o.province_id, 
                        o.regency_id, 
                        dp.name -- Mengambil nama dapur dari tabel distribution_points
                     FROM organizations o
                     JOIN distribution_points dp ON o.id = dp.organization_id
                     WHERE o.is_active = 1 
                       AND o.registration_type = 'Mitra Dapur'
                       AND dp.is_main_kitchen = 1
                     ORDER BY dp.name ASC";
    $result_kitchens = $conn->query($sql_kitchens);
    if ($result_kitchens) {
        $response['kitchens'] = $result_kitchens->fetch_all(MYSQLI_ASSOC);
    }
    
    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mengambil data filter lokasi.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
