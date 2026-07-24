<?php
// File: app/users_create.php
// PERBAIKAN FINAL: Logika 'catch' disempurnakan untuk menangani mysqli_sql_exception
// secara eksplisit dan memberikan pesan error yang lebih spesifik.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->full_name) || !isset($data->username) || !isset($data->password) || !isset($data->email) || !isset($data->role_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'Semua field (nama, username, password, email, peran) wajib diisi.']);
    exit();
}

$role_id = (int)$data->role_id;

if ($userData['role_id'] != 8 && $role_id == 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Anda tidak memiliki izin untuk menetapkan peran Super Admin.']);
    exit();
}

$full_name = $conn->real_escape_string($data->full_name);
$username = $conn->real_escape_string($data->username);
$password = password_hash($data->password, PASSWORD_BCRYPT);
$email = $conn->real_escape_string($data->email);
$phone_number = $conn->real_escape_string($data->phone_number);
$is_active = isset($data->is_active) ? (int)$data->is_active : 1;

try {
    $sql = "INSERT INTO users (organization_id, full_name, username, password, email, phone_number, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssiii", $org_id, $full_name, $username, $password, $email, $phone_number, $role_id, $is_active);

    $stmt->execute();

    http_response_code(201);
    echo json_encode(['message' => 'Pengguna berhasil dibuat.', 'id' => $conn->insert_id]);
    $stmt->close();
} catch (Throwable $e) {
    // --- PERBAIKAN DI SINI ---
    // Cek secara spesifik untuk mysqli_sql_exception dengan kode 1062 (duplikasi)
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409); // 409 Conflict
        // Periksa pesan error untuk menentukan apakah yang duplikat adalah username atau email
        if (strpos($e->getMessage(), 'unique_username_per_org') !== false) {
            echo json_encode(['message' => "Username '{$username}' sudah digunakan di organisasi Anda."]);
        } elseif (strpos($e->getMessage(), 'email') !== false) {
            echo json_encode(['message' => "Email '{$email}' sudah digunakan oleh pengguna lain."]);
        } else {
            echo json_encode(['message' => 'Terjadi error duplikasi data.']);
        }
    } else {
        // Fallback untuk error lainnya
        $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        http_response_code($code);
        echo json_encode(['message' => 'Gagal membuat pengguna: ' . $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
