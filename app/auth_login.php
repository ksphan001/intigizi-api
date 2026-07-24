<?php
// File: app/auth_login.php
// PERBAIKAN KEAMANAN: Login sekarang menggunakan email (unik global), bukan username.

require_once __DIR__ . '/config.php';
use Firebase\JWT\JWT;

$data = json_decode(file_get_contents("php://input"));

// PERBAIKAN: Validasi sekarang untuk 'email'
if (!isset($data->email) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode(['message' => 'Email dan password diperlukan.']);
    exit();
}

$email = $conn->real_escape_string($data->email);
$password = $data->password;

// PERBAIKAN: Query mencari berdasarkan 'email'
$sql = "SELECT 
            u.id, u.username, u.password, u.role_id, u.organization_id,
            o.is_active as organization_is_active,
            o.registration_type as org_type,
            u.is_active as user_is_active
        FROM users u
        LEFT JOIN organizations o ON u.organization_id = o.id
        WHERE u.email = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();

    $isSuperAdmin = isset($user['role_id']) && $user['role_id'] == 8;

    if (isset($user['organization_is_active']) && $user['organization_is_active'] == 0 && !$isSuperAdmin) {
        http_response_code(403);
        echo json_encode(['message' => 'Akses ditolak. Organisasi Anda saat ini tidak aktif.']);
        exit();
    }

    if (isset($user['user_is_active']) && $user['user_is_active'] == 0 && !$isSuperAdmin) {
        http_response_code(403);
        echo json_encode(['message' => 'Akun Anda belum aktif. Silakan hubungi administrator.']);
        exit();
    }

    if (password_verify($password, $user['password'])) {
        $payload = [
            'iss' => $_ENV['JWT_ISSUER'],
            'aud' => $_ENV['JWT_AUDIENCE'],
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24),
            'data' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role_id' => $user['role_id'],
                'org_id' => $user['organization_id'],
                'org_type' => $user['org_type']
            ]
        ];

        $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

        http_response_code(200);
        echo json_encode(['message' => 'Login berhasil.', 'token' => $jwt]);
    } else {
        http_response_code(401);
        echo json_encode(['message' => 'Email atau password salah.']);
    }
} else {
    http_response_code(401);
    echo json_encode(['message' => 'Email atau password salah.']);
}

$stmt->close();
$conn->close();
?>
