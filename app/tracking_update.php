<?php
// File: app/tracking_update.php
// Penjelasan: Menerima koordinat GPS dari aplikasi mobile kurir dan menyimpannya.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

// 1. Verifikasi Token (Hanya user login yang bisa kirim lokasi)
$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];

// Pastikan role adalah Tim Distribusi (6), Kepala Dapur (2), atau Admin (7)
if (!in_array($userData['role_id'], [2, 6, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

// 2. Ambil Data Input (JSON)
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->latitude) || !isset($data->longitude)) {
    http_response_code(400);
    echo json_encode(['message' => 'Latitude dan Longitude wajib diisi.']);
    exit();
}

$lat = (float)$data->latitude;
$lng = (float)$data->longitude;

// 3. Simpan ke Database
try {
    // Pastikan tabel distributor_tracking sudah dibuat
    $sql = "INSERT INTO distributor_tracking (user_id, organization_id, latitude, longitude) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iidd", $user_id, $org_id, $lat, $lng);
    
    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['message' => 'Lokasi berhasil diperbarui.']);
    } else {
        throw new Exception("Gagal menyimpan lokasi: " . $stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>