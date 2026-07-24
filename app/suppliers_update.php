<?php
// File: app/suppliers_update.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id) || !isset($data->supplier_name) || !isset($data->user_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID, nama supplier, dan user_id wajib diisi.']);
    exit();
}

$id = (int)$data->id;
$supplier_name = $conn->real_escape_string($data->supplier_name);
$user_id = (int)$data->user_id;
$address = isset($data->address) ? $conn->real_escape_string($data->address) : null;
$contact_person = isset($data->contact_person) ? $conn->real_escape_string($data->contact_person) : null;

$coverage_radius_km = isset($data->coverage_radius_km) ? (int)$data->coverage_radius_km : 15;
$coverage_area_desc = isset($data->coverage_area_desc) ? $conn->real_escape_string($data->coverage_area_desc) : null;
$latitude = isset($data->latitude) ? $conn->real_escape_string($data->latitude) : null;
$longitude = isset($data->longitude) ? $conn->real_escape_string($data->longitude) : null;
$phone_number = isset($data->phone_number) ? $conn->real_escape_string($data->phone_number) : null;

try {
    $sql = "UPDATE suppliers SET supplier_name = ?, user_id = ?, address = ?, contact_person = ?, coverage_radius_km = ?, coverage_area_desc = ?, latitude = ?, longitude = ?, phone_number = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sisssissssi", $supplier_name, $user_id, $address, $contact_person, $coverage_radius_km, $coverage_area_desc, $latitude, $longitude, $phone_number, $id, $org_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['message' => 'Data supplier berhasil diperbarui.']);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Supplier tidak ditemukan atau tidak ada data yang berubah.']);
    }
    $stmt->close();
} catch (Throwable $e) {
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => 'Akun pengguna ini sudah terhubung dengan data supplier lain.']);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui data supplier: ' . $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>

