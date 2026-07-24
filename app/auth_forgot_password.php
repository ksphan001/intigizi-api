<?php
// File: app/auth_forgot_password.php
// Penjelasan: API endpoint untuk menangani permintaan reset password.
// PERBAIKAN: Menggunakan variabel environment APP_URL yang lebih spesifik untuk membuat link reset.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_engine.php';

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->email) || !filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['message' => 'Alamat email yang valid wajib diisi.']);
    exit();
}

$email = $conn->real_escape_string($data->email);

$conn->begin_transaction();

try {
    // 1. Cek apakah email terdaftar di tabel users
    $userSql = "SELECT id, full_name FROM users WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($userSql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $userResult = $stmt->get_result();

    if ($userResult->num_rows === 0) {
        // Untuk keamanan, kita tidak memberitahu jika email tidak ada.
        // Cukup kirim respons sukses palsu agar tidak bisa digunakan untuk menebak email.
        http_response_code(200);
        echo json_encode(['message' => 'Jika email Anda terdaftar, kami telah mengirimkan tautan reset password.']);
        exit();
    }
    $user = $userResult->fetch_assoc();
    $stmt->close();

    // 2. Buat token reset yang aman dan unik
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token berlaku selama 1 jam

    // 3. Simpan token ke database
    $resetSql = "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($resetSql);
    $stmt->bind_param("sss", $email, $token, $expires_at);
    $stmt->execute();
    $stmt->close();

    // 4. Kirim email ke pengguna
    // --- PERBAIKAN DI SINI ---
    // Menggunakan variabel APP_URL dari .env. Jika tidak ada, baru menggunakan fallback ke ALLOWED_ORIGINS.
    $app_url = $_ENV['APP_URL'] ?? rtrim(explode(',', $_ENV['ALLOWED_ORIGINS'])[0], '/');
    $reset_link = $app_url . '/reset-password?token=' . $token;
    
    $email_title = "Permintaan Reset Password Akun Anda";
    $email_message = "Kami menerima permintaan untuk mereset password akun Anda. Silakan klik tombol di bawah ini untuk melanjutkan.<br><br>" .
                     "<a href='{$reset_link}' style='background-color: #1A335A; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a>" .
                     "<br><br>Jika Anda tidak merasa meminta reset password, abaikan email ini. Tautan ini akan kedaluwarsa dalam 1 jam.";

    send_direct_email($email, $user['full_name'], $email_title, $email_message);

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Jika email Anda terdaftar, kami telah mengirimkan tautan reset password.']);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
