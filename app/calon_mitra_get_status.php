<?php
// File: app/calon_mitra_get_status.php
// API BARU: Endpoint terproteksi untuk Calon Mitra (role_id 10)
// untuk mengambil status pengajuan pendanaan terakhir mereka.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$role_id = (int)$userData['role_id'];

// Keamanan: Hanya Calon Mitra (10) atau Yayasan (4) yang bisa mengakses
if ($role_id !== 10 && $role_id !== 4) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak untuk peran ini.']);
    exit();
}

try {
    // Ambil pengajuan terakhir dari organisasi Calon Mitra ini
    // Kita juga JOIN ke funding_campaigns untuk mendapatkan ID kampanye jika sudah diterbitkan
    $sql = "SELECT 
                fa.id,
                fa.legal_entity_name,
                fa.kitchen_name,
                fa.status,
                fa.rejection_reason,
                fa.created_at,
                fc.id as campaign_id
            FROM funding_applications fa
            LEFT JOIN funding_campaigns fc ON fa.id = fc.funding_application_id
            WHERE fa.organization_id = ?
            ORDER BY fa.created_at DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $application = $result->fetch_assoc();
    $stmt->close();

    if (!$application) {
        // Ini seharusnya tidak terjadi jika alur pendaftaran-ke-form berjalan lancar,
        // tapi sebagai pengaman, kita arahkan mereka ke form jika belum ada data.
        // Frontend akan menangani 'null' dengan mengarahkan ke form.
        http_response_code(200);
        echo json_encode(null);
        exit();
    }
    
    http_response_code(200);
    echo json_encode($application);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>