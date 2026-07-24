<?php
// File: app/financials_manage_categories.php
// Penjelasan: API endpoint BARU untuk Super Admin mengelola kategori biaya operasional.
// Mendukung metode GET, POST (untuk create/update), dan DELETE.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

try {
    if ($method === 'GET') {
        // Ambil semua kategori global
        $sql = "SELECT id, name FROM expense_categories WHERE organization_id IS NULL ORDER BY name ASC";
        $result = $conn->query($sql);
        $categories = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($categories);

    } elseif ($method === 'POST') {
        if (!isset($data->name) || empty(trim($data->name))) {
            throw new Exception('Nama kategori wajib diisi.', 400);
        }
        $name = $conn->real_escape_string($data->name);

        if (isset($data->id)) { // Update
            $id = (int)$data->id;
            $sql = "UPDATE expense_categories SET name = ? WHERE id = ? AND organization_id IS NULL";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $name, $id);
            $stmt->execute();
            echo json_encode(['message' => 'Kategori berhasil diperbarui.']);
        } else { // Create
            $sql = "INSERT INTO expense_categories (name, created_by) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $name, $userData['id']);
            $stmt->execute();
            http_response_code(201);
            echo json_encode(['message' => 'Kategori berhasil ditambahkan.', 'id' => $conn->insert_id]);
        }
        $stmt->close();
        
    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            throw new Exception('ID kategori tidak valid.', 400);
        }

        // Cek dependensi sebelum hapus
        $checkSql = "SELECT COUNT(*) as count FROM operational_expenses WHERE category_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->fetch_assoc()['count'] > 0) {
            throw new Exception('Kategori ini tidak dapat dihapus karena sedang digunakan dalam pencatatan biaya.', 409);
        }
        $checkStmt->close();
        
        $sql = "DELETE FROM expense_categories WHERE id = ? AND organization_id IS NULL";
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
        $code = 409; // Conflict
        echo json_encode(['message' => 'Nama kategori sudah ada.']);
    } else {
        http_response_code($code);
        echo json_encode(['message' => $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>
