<?php
// File: app/register_investor.php
// Penjelasan: API untuk pendaftaran Investor (role_id = 9).
// PERBAIKAN: Mengubah validasi dari 'full_name' -> 'pic_name' dan 'email' -> 'pic_email'
// agar sesuai dengan data yang dikirim oleh RegisterPage.jsx.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_engine.php';

use Firebase\JWT\JWT; // 1. Tambahkan use JWT

$data = json_decode(file_get_contents("php://input"));

// 1. Validasi input dasar (PERBAIKAN DI SINI)
if (!isset($data->pic_name) || !isset($data->pic_email) || !isset($data->username) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode(['message' => 'Semua field wajib diisi.']);
    exit();
}

$conn->begin_transaction();

try {
    // 2. Cek duplikasi username atau email (PERBAIKAN DI SINI)
    $checkSql = "SELECT username FROM users WHERE username = ? OR email = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ss", $data->username, $data->pic_email); // Menggunakan pic_email
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        throw new Exception("Username atau email sudah terdaftar.", 409);
    }
    $checkStmt->close();

    // 3. Buat akun user baru dengan role_id = 9 (Investor)
    $hashed_password = password_hash($data->password, PASSWORD_BCRYPT);
    $userSql = "INSERT INTO users (organization_id, full_name, username, email, phone_number, password, role_id, is_active) 
                VALUES (1, ?, ?, ?, ?, ?, 9, 1)";
    $userStmt = $conn->prepare($userSql);

    // 4. Bind parameter (PERBAIKAN DI SINI)
    $userStmt->bind_param(
        "sssss",
        $data->pic_name, // Menggunakan pic_name
        $data->username,
        $data->pic_email, // Menggunakan pic_email
        $data->pic_whatsapp, // Menggunakan pic_whatsapp
        $hashed_password
    );
    $userStmt->execute();
    $user_id = $conn->insert_id;
    if ($user_id == 0) throw new Exception("Gagal membuat akun investor.");
    $userStmt->close();

    // 5. Kirim notifikasi ke Super Admin (PERBAIKAN DI SINI)
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn,
                $admin['organization_id'],
                $admin['id'],
                "Investor Baru Bergabung",
                "Investor baru '{$data->pic_name}' telah mendaftar ke platform.", // Menggunakan pic_name
                "/app/admin/organizations"
            );
        }
    }

    // --- 6. MODIFIKASI: Buat JWT untuk auto-login ---
    $payload = [
        'iss' => $_ENV['JWT_ISSUER'],
        'aud' => $_ENV['JWT_AUDIENCE'],
        'iat' => time(),
        'exp' => time() + (60 * 60 * 24), // Token berlaku 1 hari
        'data' => [
            'id' => $user_id,
            'username' => $data->username,
            'role_id' => 9,
            'org_id' => null, // Investor tidak punya org_id
            'org_type' => 'Investor' // Tipe kustom
        ]
    ];
    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    // --- AKHIR MODIFIKASI ---

    $conn->commit();
    http_response_code(201);
    // 7. Kembalikan token
    echo json_encode([
        'message' => 'Pendaftaran sebagai investor berhasil! Anda akan diarahkan ke dasbor.',
        'token' => $jwt
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        if (strpos($e->getMessage(), 'username') !== false) {
            echo json_encode(['message' => "Username '{$data->username}' sudah digunakan."]);
        } elseif (strpos($e->getMessage(), 'email') !== false) {
            echo json_encode(['message' => "Email '{$data->pic_email}' sudah terdaftar."]); // Menggunakan pic_email
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
