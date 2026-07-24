<?php
// File: app/users_update_profile.php
// Penjelasan: Diperbarui untuk SaaS, hanya bisa mengedit profil dalam organisasi yang sama.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->full_name) || !isset($data->email)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama lengkap dan email wajib diisi.']);
    exit();
}

$full_name = $conn->real_escape_string($data->full_name);
$email = $conn->real_escape_string($data->email);
$phone_number = $conn->real_escape_string($data->phone_number);

if (!empty($data->password)) {
    if (strlen($data->password) < 6) {
        http_response_code(400);
        echo json_encode(['message' => 'Password minimal harus 6 karakter.']);
        exit();
    }
    $password = password_hash($data->password, PASSWORD_BCRYPT);
    $sql = "UPDATE users SET full_name = ?, email = ?, password = ?, phone_number = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssii", $full_name, $email, $password, $phone_number, $user_id, $org_id);
} else {
    $sql = "UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssiis", $full_name, $email, $phone_number, $user_id, $org_id);
}

if ($stmt->execute()) {
    http_response_code(200);
    echo json_encode(['message' => 'Profil berhasil diperbarui.']);
} else {
    if ($conn->errno == 1062) {
        http_response_code(409);
        echo json_encode(['message' => 'Email sudah digunakan oleh pengguna lain.']);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui profil: ' . $stmt->error]);
    }
}

$stmt->close();
$conn->close();
