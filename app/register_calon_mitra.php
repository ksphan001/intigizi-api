<?php
// File: app/register_calon_mitra.php
// Versi ini sudah benar dan sesuai dengan alur frontend.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_engine.php';
use Firebase\JWT\JWT;

$data = json_decode(file_get_contents("php://input"));

// 1. Validasi input
if (!isset($data->pic_name) || empty(trim($data->pic_name))) {
    http_response_code(400);
    echo json_encode(['message' => "Field 'Nama Lengkap Anda' wajib diisi."]);
    exit();
}
if (!isset($data->pic_email) || !isset($data->username) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode(['message' => 'Email PIC, username, dan password wajib diisi.']);
    exit();
}

$conn->begin_transaction();

try {
    // 2. Cek duplikasi username atau email
    $checkSql = "SELECT username FROM users WHERE username = ? OR email = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ss", $data->username, $data->pic_email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        throw new Exception("Username atau email sudah terdaftar.", 409);
    }
    $checkStmt->close();

    // 3. Buat entitas 'organizations' untuk Calon Mitra (non-aktif)
    $orgSql = "INSERT INTO organizations (name, registration_type, is_active, pic_name, pic_whatsapp, subscription_status) VALUES (?, 'Calon Mitra', 0, ?, ?, 'inactive')";
    $orgStmt = $conn->prepare($orgSql);
    $orgStmt->bind_param("sss", $data->pic_name, $data->pic_name, $data->pic_whatsapp);
    $orgStmt->execute();
    $org_id = $conn->insert_id;
    if ($org_id == 0) throw new Exception("Gagal membuat entitas organisasi.");
    $orgStmt->close();

    // 4. Buat akun user baru dengan role_id = 10 (Calon Mitra)
    $hashed_password = password_hash($data->password, PASSWORD_BCRYPT);
    $userSql = "INSERT INTO users (organization_id, full_name, username, email, phone_number, password, role_id, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, 10, 1)";
    $userStmt = $conn->prepare($userSql);
    $userStmt->bind_param(
        "isssss",
        $org_id,
        $data->pic_name, // Menggunakan pic_name sebagai full_name
        $data->username,
        $data->pic_email, // Menggunakan pic_email
        $data->pic_whatsapp,
        $hashed_password
    );
    $userStmt->execute();
    $user_id = $conn->insert_id;
    if ($user_id == 0) throw new Exception("Gagal membuat akun calon mitra.");
    $userStmt->close();
    
    // 5. Kirim notifikasi ke Super Admin
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn, 
                $admin['organization_id'],
                $admin['id'],
                "Calon Mitra Baru Mendaftar", 
                "Calon Mitra baru '{$data->pic_name}' telah mendaftar dan menunggu pengajuan pendanaan.", 
                "/app/admin/funding-applications"
            );
        }
    }

    // 6. Buat JWT untuk auto-login
    $payload = [
        'iss' => $_ENV['JWT_ISSUER'],
        'aud' => $_ENV['JWT_AUDIENCE'],
        'iat' => time(),
        'exp' => time() + (60 * 60 * 24), // Token berlaku 1 hari
        'data' => [
            'id' => $user_id,
            'username' => $data->username,
            'role_id' => 10,
            'org_id' => $org_id,
            'org_type' => 'Calon Mitra'
        ]
    ];
    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

    $conn->commit();
    http_response_code(201);
    // 7. Kembalikan token
    echo json_encode([
        'message' => 'Pendaftaran berhasil! Anda akan diarahkan ke formulir pengajuan.',
        'token' => $jwt
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        if (strpos($e->getMessage(), 'username') !== false) {
            echo json_encode(['message' => "Username '{$data->username}' sudah digunakan."]);
        } elseif (strpos($e->getMessage(), 'email') !== false) {
            echo json_encode(['message' => "Email '{$data->pic_email}' sudah terdaftar."]);
        } else {
            echo json_encode(['message' => 'Terjadi error duplikasi data.']);
        }
    } else {
        $error_code = $e->getCode() > 0 && $e->getCode() < 599 ? $e->getCode() : 500;
        http_response_code($error_code);
        echo json_encode(['message' => $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>