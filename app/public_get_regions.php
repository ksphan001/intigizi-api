<?php
// File: app/public_get_regions.php
// Deskripsi: API publik untuk mengambil daftar lengkap semua provinsi dan kabupaten/kota.

require_once __DIR__ . '/config.php';

try {
    // 1. Ambil semua provinsi
    $provinces_sql = "SELECT id, name FROM provinces ORDER BY name ASC";
    $provinces_result = $conn->query($provinces_sql);
    $provinces = $provinces_result->fetch_all(MYSQLI_ASSOC);

    // 2. Ambil semua kabupaten/kota
    $regencies_sql = "SELECT id, province_id, name FROM regencies ORDER BY name ASC";
    $regencies_result = $conn->query($regencies_sql);
    $regencies_data = $regencies_result->fetch_all(MYSQLI_ASSOC);

    // 3. Kelompokkan kabupaten/kota berdasarkan province_id untuk kemudahan di frontend
    $regencies_by_province = [];
    foreach ($regencies_data as $regency) {
        $regencies_by_province[$regency['province_id']][] = $regency;
    }

    // 4. Gabungkan dalam satu response
    $response = [
        'provinces' => $provinces,
        'regencies' => $regencies_by_province
    ];

    http_response_code(200);
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data wilayah.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

