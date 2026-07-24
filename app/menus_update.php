<?php
// File: app/menus_update.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id) || !isset($data->menu_name)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID dan nama menu wajib diisi.']);
    exit();
}

$id = (int)$data->id;
$menu_name = $conn->real_escape_string($data->menu_name);

try {
    $sql = "UPDATE menus SET menu_name = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $menu_name, $id, $org_id);

    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        http_response_code(200);
        echo json_encode(['message' => 'Menu berhasil diperbarui.']);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Menu tidak ditemukan atau tidak ada data yang berubah.']);
    }
    $stmt->close();
} catch (Throwable $e) {
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => "Nama menu '{$menu_name}' sudah ada."]);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui menu: ' . $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>
