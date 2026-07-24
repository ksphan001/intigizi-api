<?php
// File: app/notifications_mark_read.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (isset($data->notif_id)) {
    $notif_id = (int)$data->notif_id;
    $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $notif_id, $user_id, $org_id);
} else {
    $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $org_id);
}

if ($stmt->execute()) {
    http_response_code(200);
    echo json_encode(['message' => 'Notifikasi ditandai sebagai dibaca.']);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memperbarui notifikasi.']);
}

$stmt->close();
$conn->close();
?>
