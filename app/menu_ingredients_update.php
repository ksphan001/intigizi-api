<?php
// File: app/menu_ingredients_update.php
// PENJELASAN: Diperbarui untuk menerima `quantity_per_portion` sebagai objek JSON.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

// Validasi input dasar, quantity_per_portion sekarang adalah object
if (!isset($data->id) || !isset($data->quantity_per_portion) || !is_object($data->quantity_per_portion)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID resep dan data porsi (quantity_per_portion) wajib diisi.']);
    exit();
}

$id = (int)$data->id;
// Encode objek porsi menjadi string JSON untuk disimpan di database
$quantity_json = json_encode($data->quantity_per_portion);

$sql = "UPDATE menu_ingredients SET quantity_per_portion = ? WHERE id = ? AND organization_id = ?";
$stmt = $conn->prepare($sql);
// 's' untuk string karena kita mengirim data JSON
$stmt->bind_param("sii", $quantity_json, $id, $org_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['message' => 'Jumlah bahan dalam resep berhasil diperbarui.']);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Resep tidak ditemukan atau tidak ada data yang berubah.']);
    }
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memperbarui resep: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
