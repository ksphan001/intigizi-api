<?php
// File: app/users_get.php
// Penjelasan: Diperbarui untuk SaaS, hanya menampilkan user dari organisasi yang sama.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    $sql = "SELECT 
                u.id, u.username, u.full_name, u.email, u.phone_number,
                u.role_id, r.role_name, u.is_active
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.organization_id = ?
            ORDER BY u.full_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $users = $result->fetch_all(MYSQLI_ASSOC);

    http_response_code(200);
    echo json_encode($users);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi error internal pada server.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $stmt->close();
        $conn->close();
    }
}
