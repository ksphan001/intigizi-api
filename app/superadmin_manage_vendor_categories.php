<?php
// File: app/superadmin_manage_vendor_categories.php
// Penjelasan: Pengecekan izin dikembalikan ke role_id 8.
// PERBAIKAN: Metode DELETE diseragamkan menggunakan JSON body.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();

// PERBAIKAN: Super Admin kembali menggunakan role_id 8
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $sql = "SELECT id, name FROM vendor_categories ORDER BY name ASC";
        $result = $conn->query($sql);
        $categories = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($categories);
    } 
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        
        // --- PERBAIKAN DI SINI: Logika delete dipindah ke sini ---
        if (isset($data->action) && $data->action === 'delete') {
            if (!isset($data->id)) {
                http_response_code(400);
                echo json_encode(['message' => 'ID kategori wajib diisi untuk dihapus.']);
                exit();
            }
            $id = (int)$data->id;
            $sql = "DELETE FROM vendor_categories WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode(['message' => 'Kategori berhasil dihapus.']);
                } else {
                    http_response_code(404);
                    echo json_encode(['message' => 'Kategori tidak ditemukan.']);
                }
            } else {
                throw new Exception('Gagal menghapus kategori.');
            }
            $stmt->close();
            exit(); // Keluar setelah selesai delete
        }
        
        // Logika untuk Create dan Update
        if (!isset($data->name) || empty(trim($data->name))) {
            http_response_code(400);
            echo json_encode(['message' => 'Nama kategori wajib diisi.']);
            exit();
        }
        
        $name = $conn->real_escape_string($data->name);

        if (isset($data->id)) { // Update
            $id = (int)$data->id;
            $sql = "UPDATE vendor_categories SET name = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $name, $id);
            if ($stmt->execute()) {
                echo json_encode(['message' => 'Kategori berhasil diperbarui.']);
            } else {
                throw new Exception('Gagal memperbarui kategori.');
            }
        } else { // Create
            $sql = "INSERT INTO vendor_categories (name) VALUES (?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(['message' => 'Kategori berhasil ditambahkan.', 'id' => $conn->insert_id]);
            } else {
                throw new Exception('Gagal menambahkan kategori.');
            }
        }
        $stmt->close();
    }
    // --- Method DELETE tidak lagi digunakan secara langsung ---
    /*
    elseif ($method === 'DELETE') {
        // Logika ini dipindahkan ke dalam POST dengan action=delete
    }
    */
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
