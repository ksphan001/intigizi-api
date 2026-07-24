<?php
// File: app/roles_get.php
// Penjelasan: Filter dikembalikan untuk menyembunyikan role_id 8 bagi non-Super Admin.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();

header('Content-Type: application/json');

try {
    // PERBAIKAN: Super Admin kembali menggunakan role_id 8
    $isSuperAdmin = isset($userData['role_id']) && $userData['role_id'] == 8;

    // Jika bukan Super Admin, jangan tampilkan peran Super Admin di dropdown
    if (!$isSuperAdmin) {
        // PERBAIKAN: Sembunyikan peran Super Admin (ID 8)
        $sql = "SELECT id, role_name FROM roles WHERE id != 8 ORDER BY role_name ASC";
        $stmt = $conn->prepare($sql);
    } else {
        $sql = "SELECT id, role_name FROM roles ORDER BY role_name ASC";
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $roles = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($roles);

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
?>

