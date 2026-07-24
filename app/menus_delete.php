<?php
// File: app/menus_delete.php
// Penjelasan: PENYEMPURNAAN FINAL - Menambahkan pengecekan dependensi sebelum menghapus.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID menu wajib diisi.']);
    exit();
}

$id = (int)$data->id;

$conn->begin_transaction();

try {
    // 1. Cek apakah menu ini digunakan di proposal_menus
    $checkSql = "SELECT p.proposal_code 
                 FROM proposal_menus pm
                 JOIN proposals p ON pm.proposal_id = p.id
                 WHERE pm.menu_id = ? AND pm.organization_id = ? 
                 LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $id, $org_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Jika digunakan, batalkan penghapusan dan beri pesan error spesifik
        throw new Exception("Menu ini tidak dapat dihapus karena sedang digunakan dalam Proposal '{$row['proposal_code']}'.", 409); // 409 Conflict
    }
    $checkStmt->close();

    // 2. Jika tidak digunakan, lanjutkan penghapusan
    $deleteSql = "DELETE FROM menus WHERE id = ? AND organization_id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->bind_param("ii", $id, $org_id);

    if ($deleteStmt->execute()) {
        if ($deleteStmt->affected_rows > 0) {
            $conn->commit();
            http_response_code(200);
            echo json_encode(['message' => 'Menu berhasil dihapus.']);
        } else {
            throw new Exception('Menu tidak ditemukan atau Anda tidak memiliki akses.', 404);
        }
    } else {
        throw new Exception('Gagal menjalankan query hapus: ' . $deleteStmt->error);
    }
    $deleteStmt->close();

} catch (Exception $e) {
    $conn->rollback();
    // Gunakan kode status dari Exception jika ada, jika tidak default ke 500
    $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($errorCode);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
