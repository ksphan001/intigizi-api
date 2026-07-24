<?php
// File: app/suppliers_create.php
// PERBAIKAN KEAMANAN: Menambahkan validasi kepemilikan `user_id`
// sebelum membuat data supplier untuk mencegah pelanggaran isolasi data.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->supplier_name) || !isset($data->user_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama supplier dan user_id wajib diisi.']);
    exit();
}

$supplier_name = $conn->real_escape_string($data->supplier_name);
$user_id = (int)$data->user_id;
$address = isset($data->address) ? $conn->real_escape_string($data->address) : null;
$contact_person = isset($data->contact_person) ? $conn->real_escape_string($data->contact_person) : null;

try {
    // --- PERBAIKAN KEAMANAN DIMULAI DI SINI ---
    // Validasi bahwa user_id yang diberikan adalah milik organisasi ini dan memiliki peran Supplier.
    $checkUserSql = "SELECT id FROM users WHERE id = ? AND organization_id = ? AND role_id = 5";
    $checkUserStmt = $conn->prepare($checkUserSql);
    $checkUserStmt->bind_param("ii", $user_id, $org_id);
    $checkUserStmt->execute();
    if ($checkUserStmt->get_result()->num_rows === 0) {
        $checkUserStmt->close();
        throw new Exception("Akun pengguna tidak valid atau tidak memiliki peran sebagai Supplier di organisasi Anda.", 404);
    }
    $checkUserStmt->close();
    // --- PERBAIKAN KEAMANAN SELESAI ---

    $sql = "INSERT INTO suppliers (organization_id, supplier_name, user_id, address, contact_person) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isiss", $org_id, $supplier_name, $user_id, $address, $contact_person);

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['message' => 'Supplier berhasil ditambahkan.', 'id' => $conn->insert_id]);
    } else {
        if ($conn->errno == 1062) {
            throw new Exception('Akun pengguna ini sudah terhubung dengan data supplier lain.', 409);
        }
        throw new Exception('Gagal menambahkan supplier: ' . $stmt->error);
    }
    $stmt->close();
} catch (Throwable $e) {
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
