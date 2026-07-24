<?php
// File: app/menu_ingredients_remove.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID resep wajib diisi.']);
    exit();
}

$id = (int)$data->id;

$sql = "DELETE FROM menu_ingredients WHERE id = ? AND organization_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $id, $org_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['message' => 'Bahan berhasil dihapus dari resep.']);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Resep tidak ditemukan atau Anda tidak memiliki akses.']);
    }
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menghapus bahan dari resep: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
