<?php
// File: app/superadmin_convert_to_kitchen.php
// API BARU: Untuk Super Admin mengkonversi 'Calon Mitra' menjadi 'Mitra Dapur'
// setelah pendanaan berhasil.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));
$application_id = isset($data->application_id) ? (int)$data->application_id : 0;

if ($application_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Pengajuan wajib diisi.']);
    exit();
}

$conn->begin_transaction();

try {
    // 1. Ambil data Calon Mitra dari pengajuan
    $sql_app = "SELECT organization_id, pic_full_name, pic_email, kitchen_name, kitchen_address, latitude, longitude
                FROM funding_applications WHERE id = ? LIMIT 1";
    $stmt_app = $conn->prepare($sql_app);
    $stmt_app->bind_param("i", $application_id);
    $stmt_app->execute();
    $application = $stmt_app->get_result()->fetch_assoc();
    $stmt_app->close();

    if (!$application) throw new Exception("Pengajuan tidak ditemukan.", 404);
    
    $org_id = $application['organization_id'];

    // 2. Tentukan masa aktif langganan (default 1 tahun)
    $subscription_until = date('Y-m-d', strtotime('+1 year'));

    // 3. UPDATE tabel `organizations`
    $sql_org = "UPDATE organizations SET 
                    registration_type = 'Mitra Dapur', 
                    is_active = 1, 
                    subscription_status = 'active', 
                    subscription_until = ?,
                    name = ?, 
                    director_name = ?
                WHERE id = ?";
    $stmt_org = $conn->prepare($sql_org);
    // Menggunakan nama dapur sebagai nama organisasi utama
    $stmt_org->bind_param("sssi", $subscription_until, $application['kitchen_name'], $application['pic_full_name'], $org_id);
    $stmt_org->execute();
    $stmt_org->close();

    // 4. UPDATE tabel `users` (ubah peran dari 10 ke 7)
    $sql_user = "UPDATE users SET role_id = 7 WHERE organization_id = ? AND role_id = 10 LIMIT 1";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->bind_param("i", $org_id);
    $stmt_user->execute();
    $stmt_user->close();
    
    // 5. UPDATE atau INSERT `distribution_points`
    // (Meskipun sudah dibuat saat registrasi, kita update lagi untuk pastikan data kitchen_name benar)
    $sql_dp = "INSERT INTO distribution_points (organization_id, name, address, latitude, longitude, is_main_kitchen)
               VALUES (?, ?, ?, ?, ?, 1)
               ON DUPLICATE KEY UPDATE 
                   name = VALUES(name), address = VALUES(address), latitude = VALUES(latitude), longitude = VALUES(longitude)";
    $stmt_dp = $conn->prepare($sql_dp);
    $stmt_dp->bind_param("issdd", $org_id, $application['kitchen_name'], $application['kitchen_address'], $application['latitude'], $application['longitude']);
    $stmt_dp->execute();
    $stmt_dp->close();

    // 6. Kirim email sambutan
    $title = "Selamat! Dapur Anda Telah Diaktifkan";
    $message = "Akun 'Calon Mitra' Anda telah berhasil dikonversi menjadi 'Mitra Dapur' aktif. Langganan Anda aktif hingga {$subscription_until}. Anda sekarang dapat login dan mulai mengelola operasional dapur Anda.";
    send_direct_email($application['pic_email'], $application['pic_full_name'], $title, $message);

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Akun Calon Mitra berhasil dikonversi menjadi Mitra Dapur aktif.']);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>