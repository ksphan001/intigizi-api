<?php
// File: app/superadmin_manage_beneficiary_categories.php
// Deskripsi: API endpoint BARU untuk Super Admin mengelola kategori penerima manfaat.
// Mendukung GET, POST (create/update), dan DELETE.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin (role_id = 8) yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

try {
    if ($method === 'GET') {
        $sql = "SELECT id, name, sort_order FROM beneficiary_categories ORDER BY sort_order ASC";
        $result = $conn->query($sql);
        $categories = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($categories);

    } elseif ($method === 'POST') {
        if (!isset($data->name) || empty(trim($data->name))) {
            throw new Exception('Nama kategori wajib diisi.', 400);
        }
        $name = $conn->real_escape_string($data->name);
        $sort_order = isset($data->sort_order) ? (int)$data->sort_order : 0;

        if (isset($data->id)) { // Update
            $id = (int)$data->id;
            $sql = "UPDATE beneficiary_categories SET name = ?, sort_order = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sii", $name, $sort_order, $id);
            $stmt->execute();
            echo json_encode(['message' => 'Kategori berhasil diperbarui.']);
        } else { // Create
            $sql = "INSERT INTO beneficiary_categories (name, sort_order) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $name, $sort_order);
            $stmt->execute();
            http_response_code(201);
            echo json_encode(['message' => 'Kategori berhasil ditambahkan.', 'id' => $conn->insert_id]);
        }
        $stmt->close();
        
    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) throw new Exception('ID kategori tidak valid.', 400);

        // Cek dependensi
        $checkSql = "SELECT COUNT(*) as count FROM distribution_point_counts WHERE category_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->fetch_assoc()['count'] > 0) {
            throw new Exception('Kategori ini tidak dapat dihapus karena sedang digunakan di Titik Distribusi.', 409);
        }
        $checkStmt->close();
        
        $sql = "DELETE FROM beneficiary_categories WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['message' => 'Kategori berhasil dihapus.']);
        } else {
            throw new Exception('Kategori tidak ditemukan.', 404);
        }
        $stmt->close();
    }

} catch (Throwable $e) {
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => 'Nama kategori sudah ada.']);
    } else {
        http_response_code($code);
        echo json_encode(['message' => $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>
