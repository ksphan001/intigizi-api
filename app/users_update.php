<?php
// File: app/users_update.php
// PERBAIKAN: Pesan error dispesifikkan untuk duplikasi username atau email.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id) || !isset($data->full_name) || !isset($data->username) || !isset($data->email) || !isset($data->role_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID, nama, username, email, dan peran wajib diisi.']);
    exit();
}

$id = (int)$data->id;
$full_name = $conn->real_escape_string($data->full_name);
$username = $conn->real_escape_string($data->username);
$email = $conn->real_escape_string($data->email);
$phone_number = $conn->real_escape_string($data->phone_number);
$role_id = (int)$data->role_id;
$is_active = isset($data->is_active) ? (int)$data->is_active : 1;

try {
    if (!empty($data->password)) {
        $password = password_hash($data->password, PASSWORD_BCRYPT);
        $sql = "UPDATE users SET full_name = ?, username = ?, email = ?, phone_number = ?, role_id = ?, is_active = ?, password = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiisiii", $full_name, $username, $email, $phone_number, $role_id, $is_active, $password, $id, $org_id);
    } else {
        $sql = "UPDATE users SET full_name = ?, username = ?, email = ?, phone_number = ?, role_id = ?, is_active = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiisii", $full_name, $username, $email, $phone_number, $role_id, $is_active, $id, $org_id);
    }

    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['message' => 'Data pengguna berhasil diperbarui.']);
    } else {
        http_response_code(200);
        echo json_encode(['message' => 'Tidak ada perubahan data.']);
    }
    $stmt->close();
} catch (Throwable $e) {
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        if (strpos($e->getMessage(), 'username') !== false) {
            echo json_encode(['message' => "Username '{$username}' sudah digunakan oleh pengguna lain."]);
        } elseif (strpos($e->getMessage(), 'email') !== false) {
            echo json_encode(['message' => "Email '{$email}' sudah digunakan."]);
        } else {
            echo json_encode(['message' => 'Terjadi error duplikasi data.']);
        }
    } else {
        $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        http_response_code($code);
        echo json_encode(['message' => 'Gagal memperbarui pengguna: ' . $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
