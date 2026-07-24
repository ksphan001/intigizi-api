<?php
// File: app/notifications_get.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];

$sql = "SELECT id, title, message, link, is_read, created_at 
        FROM notifications 
        WHERE user_id = ? AND organization_id = ?
        ORDER BY created_at DESC
        LIMIT 10";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $org_id);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);

http_response_code(200);
echo json_encode($notifications);

$stmt->close();
$conn->close();
?>
