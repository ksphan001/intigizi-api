<?php
// File: app/get_user_session.php
// Penjelasan: Endpoint baru untuk mengambil data sesi lengkap pengguna,
// termasuk status langganan organisasi mereka.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userDataFromToken = verify_jwt_token();
$user_id = (int)$userDataFromToken['id'];

try {
    // Query untuk mengambil data lengkap pengguna dan organisasinya
    $sql = "SELECT 
                u.id, 
                u.username, 
                u.role_id, 
                u.organization_id,
                o.registration_type as org_type,
                o.subscription_status,
                o.subscription_until
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $sessionData = $result->fetch_assoc();
        http_response_code(200);
        echo json_encode($sessionData);
    } else {
        throw new Exception("Data pengguna tidak ditemukan.", 404);
    }
    
    $stmt->close();

} catch (Throwable $e) {
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Gagal mengambil data sesi.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
