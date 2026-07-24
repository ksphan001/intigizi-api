<?php
// File: app/menus_create.php
// PERBAIKAN: Menambahkan penanganan error duplikasi spesifik.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->menu_name)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama menu wajib diisi.']);
    exit();
}

$menu_name = $conn->real_escape_string($data->menu_name);

try {
    $sql = "INSERT INTO menus (organization_id, menu_name, created_by) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isi", $org_id, $menu_name, $user_id);

    $stmt->execute();
    
    http_response_code(201);
    echo json_encode(['message' => 'Menu berhasil dibuat.', 'id' => $conn->insert_id]);
    $stmt->close();

} catch (Throwable $e) {
    // Penanganan error duplikasi yang lebih robust
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409); // Conflict
        echo json_encode(['message' => "Nama menu '{$menu_name}' sudah ada di dapur Anda."]);
    } else {
        $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        http_response_code($code);
        echo json_encode(['message' => 'Gagal membuat menu: ' . $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>

