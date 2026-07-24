<?php
// File: app/users_get_profile.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];

$sql = "SELECT id, username, full_name, email, phone_number, role_id, str_number, str_expiry FROM users WHERE id = ? AND organization_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user_profile = $result->fetch_assoc();
    http_response_code(200);
    echo json_encode($user_profile);
} else {
    http_response_code(404);
    echo json_encode(['message' => 'Profil pengguna tidak ditemukan.']);
}

$stmt->close();
$conn->close();
