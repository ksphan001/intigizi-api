<?php
// File: app/proposal_menus_remove.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID jadwal menu wajib diisi.']);
    exit();
}

$id = (int)$data->id;

$checkSql = "SELECT p.id FROM proposals p JOIN proposal_menus pm ON p.id = pm.proposal_id WHERE pm.id = ? AND p.status = 'Draft' AND p.organization_id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("ii", $id, $org_id);
$checkStmt->execute();
if ($checkStmt->get_result()->num_rows == 0) {
    http_response_code(403);
    echo json_encode(['message' => 'Tidak dapat menghapus jadwal karena proposal tidak ditemukan atau statusnya bukan Draft.']);
    $checkStmt->close();
    $conn->close();
    exit();
}
$checkStmt->close();

$sql = "DELETE FROM proposal_menus WHERE id = ? AND organization_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $id, $org_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['message' => 'Jadwal menu berhasil dihapus.']);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Jadwal menu tidak ditemukan.']);
    }
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menghapus jadwal menu: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
