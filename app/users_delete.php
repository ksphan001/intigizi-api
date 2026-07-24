<?php
// File: app/users_delete.php
// PENYEMPURNAAN: Menambahkan pengecekan untuk mencegah penghapusan user yang terhubung ke supplier.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID pengguna wajib diisi.']);
    exit();
}

$id = (int)$data->id;

if ($id == $userData['id']) {
    http_response_code(403);
    echo json_encode(['message' => 'Anda tidak dapat menghapus akun Anda sendiri.']);
    exit();
}

$conn->begin_transaction();
try {
    // 1. Cek apakah user ini terhubung ke data supplier
    $checkSql = "SELECT supplier_name FROM suppliers WHERE user_id = ? AND organization_id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $id, $org_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        throw new Exception("Pengguna ini tidak dapat dihapus karena terhubung dengan data Supplier '{$row['supplier_name']}'. Hapus data supplier terlebih dahulu atau ganti user yang terhubung.", 409); // 409 Conflict
    }
    $checkStmt->close();

    // 2. Lanjutkan penghapusan jika tidak ada dependensi
    $deleteSql = "DELETE FROM users WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($deleteSql);
    $stmt->bind_param("ii", $id, $org_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $conn->commit();
            http_response_code(200);
            echo json_encode(['message' => 'Pengguna berhasil dihapus.']);
        } else {
            throw new Exception('Pengguna tidak ditemukan atau Anda tidak memiliki akses.', 404);
        }
    } else {
        throw new Exception('Gagal menghapus pengguna: ' . $stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    $conn->rollback();
    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($errorCode);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>

