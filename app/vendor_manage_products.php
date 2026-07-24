<?php
// File: app/vendor_manage_products.php
// API untuk mengelola (Tambah, Edit, Hapus) produk vendor.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Hanya Vendor (role_id = 5) yang bisa mengakses
if ($userData['role_id'] != 5) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));
$action = $data->action ?? '';
$response = ['message' => 'Aksi tidak valid.'];
$status_code = 400;

try {
    $conn->begin_transaction();

    if ($action === 'add' || $action === 'update') {
        if (!isset($data->product_name) || !isset($data->unit_id) || !isset($data->price_per_unit)) {
            throw new Exception('Nama produk, satuan, dan harga wajib diisi.');
        }

        $product_name = $conn->real_escape_string($data->product_name);
        $unit_id = (int)$data->unit_id;
        $price_per_unit = (float)$data->price_per_unit;
        $description = isset($data->description) ? $conn->real_escape_string($data->description) : null;

        if ($action === 'add') {
            $sql = "INSERT INTO vendor_products (organization_id, product_name, unit_id, price_per_unit, description) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isids", $org_id, $product_name, $unit_id, $price_per_unit, $description);
            $response['message'] = 'Produk berhasil ditambahkan.';
            $status_code = 201;
        } else { // update
            $product_id = (int)($data->id ?? 0);
            $sql = "UPDATE vendor_products SET product_name = ?, unit_id = ?, price_per_unit = ?, description = ? WHERE id = ? AND organization_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sidsii", $product_name, $unit_id, $price_per_unit, $description, $product_id, $org_id);
            $response['message'] = 'Produk berhasil diperbarui.';
            $status_code = 200;
        }
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'delete') {
        $product_id = (int)($data->id ?? 0);
        $sql = "DELETE FROM vendor_products WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $product_id, $org_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $response['message'] = 'Produk berhasil dihapus.';
            $status_code = 200;
        } else {
            throw new Exception('Produk tidak ditemukan atau Anda tidak memiliki akses.', 404);
        }
        $stmt->close();
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    $response = ['message' => $e->getMessage()];
    $status_code = ($e->getCode() > 0) ? $e->getCode() : 500;
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    http_response_code($status_code);
    echo json_encode($response);
}
?>
