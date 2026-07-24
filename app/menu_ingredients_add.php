<?php
// File: app/menu_ingredients_add.php
// Penjelasan: Validasi diperketat untuk memastikan data porsi yang dikirim adalah objek yang benar.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

// PERBAIKAN: Validasi diubah untuk memastikan quantity_per_portion adalah sebuah objek.
if (!isset($data->menu_id) || !isset($data->ingredient_id) || !isset($data->quantity_per_portion) || !is_object($data->quantity_per_portion)) {
    http_response_code(400);
    echo json_encode(['message' => 'Data menu, bahan, dan porsi per kategori wajib diisi dengan benar.']);
    exit();
}

$menu_id = (int)$data->menu_id;
$ingredient_id = (int)$data->ingredient_id;
// PERBAIKAN: Data porsi (objek) akan di-encode menjadi string JSON.
$quantity_per_portion_json = json_encode($data->quantity_per_portion);

// Cek duplikat dalam organisasi yang sama
$checkSql = "SELECT id FROM menu_ingredients WHERE menu_id = ? AND ingredient_id = ? AND organization_id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("iii", $menu_id, $ingredient_id, $org_id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
if ($checkResult->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['message' => 'Bahan ini sudah ada di dalam resep.']);
    $checkStmt->close();
    $conn->close();
    exit();
}
$checkStmt->close();

$sql = "INSERT INTO menu_ingredients (organization_id, menu_id, ingredient_id, quantity_per_portion) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
// PERBAIKAN: Bind parameter sebagai string 's' untuk data JSON.
$stmt->bind_param("iiis", $org_id, $menu_id, $ingredient_id, $quantity_per_portion_json);

if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode(['message' => 'Bahan berhasil ditambahkan ke resep.', 'id' => $conn->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menambahkan bahan ke resep: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>

