<?php
// File: app/tracking_stop.php
// Penjelasan: API untuk menghentikan sesi live tracking.
// Menghapus data lokasi terbaru agar status "Live" di dashboard segera hilang.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Pastikan user memiliki hak (Kepala Dapur, Tim Distribusi, Admin)
if (!in_array($userData['role_id'], [2, 6, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    // Hapus data tracking dari 24 jam terakhir untuk organisasi ini.
    // Ini akan membuat endpoint 'public_get_courier_location.php' tidak menemukan data,
    // sehingga status "Live" di peta akan langsung mati.
    $sql = "DELETE FROM distributor_tracking 
            WHERE organization_id = ? 
            AND recorded_at >= NOW() - INTERVAL 1 DAY";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    
    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['message' => 'Pelacakan dihentikan. Data lokasi dibersihkan.']);
    } else {
        throw new Exception("Gagal menghapus data tracking.");
    }
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>