<?php
// File: app/auth_reset_password.php
// Penjelasan: API endpoint untuk memvalidasi token dan mereset password pengguna.

require_once __DIR__ . '/config.php';

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->token) || !isset($data->password) || empty($data->password)) {
    http_response_code(400);
    echo json_encode(['message' => 'Token dan password baru wajib diisi.']);
    exit();
}

$token = $conn->real_escape_string($data->token);
$new_password = $data->password;

if (strlen($new_password) < 6) {
    http_response_code(400);
    echo json_encode(['message' => 'Password minimal harus 6 karakter.']);
    exit();
}

$conn->begin_transaction();

try {
    // 1. Cari token di database dan pastikan belum kedaluwarsa
    $resetSql = "SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1";
    $stmt = $conn->prepare($resetSql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resetResult = $stmt->get_result();

    if ($resetResult->num_rows === 0) {
        throw new Exception("Token reset tidak valid atau sudah kedaluwarsa.", 400);
    }
    $resetData = $resetResult->fetch_assoc();
    $email = $resetData['email'];
    $stmt->close();

    // 2. Hash password baru
    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

    // 3. Update password di tabel users
    $updateSql = "UPDATE users SET password = ? WHERE email = ?";
    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param("ss", $hashed_password, $email);
    $stmt->execute();
    $stmt->close();

    // 4. Hapus token yang sudah digunakan dari tabel password_resets
    $deleteSql = "DELETE FROM password_resets WHERE email = ?";
    $stmt = $conn->prepare($deleteSql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Password Anda telah berhasil diperbarui.']);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
