<?php
// File: app/kitchen_gallery_manage.php
// Penjelasan: API baru untuk mengelola galeri dapur (upload, get, delete).
// Hanya bisa diakses oleh Administrator.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Pastikan hanya Administrator (role_id = 7) atau Yayasan (role_id = 4) yang bisa mengakses
if ($userData['role_id'] != 7 && $userData['role_id'] != 4) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$response = ['message' => 'Aksi tidak valid.'];
$status_code = 400;

try {
    if ($method === 'GET') {
        $sql = "SELECT id, image_path, caption, created_at FROM kitchen_gallery WHERE organization_id = ? ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        http_response_code(200);
        echo json_encode($result);
        exit();

    } elseif ($method === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' && isset($_FILES['image'])) {
            $caption = $_POST['caption'] ?? '';

            $target_dir = __DIR__ . "/../uploads/gallery/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }

            $imageFileType = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
            $unique_name = "gallery_" . $org_id . "_" . time() . "." . $imageFileType;
            $target_file = $target_dir . $unique_name;

            if ($_FILES["image"]["size"] > 5000000) throw new Exception("Ukuran file terlalu besar (maks 5MB).");
            if (!in_array($imageFileType, ['jpg', 'png', 'jpeg'])) throw new Exception("Hanya format JPG, JPEG, & PNG yang diizinkan.");

            if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                $image_path = "/uploads/gallery/" . $unique_name;
                $sql = "INSERT INTO kitchen_gallery (organization_id, image_path, caption) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iss", $org_id, $image_path, $caption);
                $stmt->execute();
                $stmt->close();
                
                $response = ['message' => 'Foto berhasil diunggah ke galeri.'];
                $status_code = 201;
            } else {
                throw new Exception("Gagal mengunggah file.");
            }

        } elseif ($action === 'delete') {
            // Untuk action delete, kita menerima JSON
            $data = json_decode(file_get_contents("php://input"));
            $item_id = $data->id ?? 0;

            $sqlSelect = "SELECT image_path FROM kitchen_gallery WHERE id = ? AND organization_id = ?";
            $stmtSelect = $conn->prepare($sqlSelect);
            $stmtSelect->bind_param("ii", $item_id, $org_id);
            $stmtSelect->execute();
            $result = $stmtSelect->get_result();
            if ($row = $result->fetch_assoc()) {
                $file_to_delete = __DIR__ . "/.." . $row['image_path'];
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                }
            }
            $stmtSelect->close();

            $sqlDelete = "DELETE FROM kitchen_gallery WHERE id = ? AND organization_id = ?";
            $stmtDelete = $conn->prepare($sqlDelete);
            $stmtDelete->bind_param("ii", $item_id, $org_id);
            $stmtDelete->execute();
            
            if ($stmtDelete->affected_rows > 0) {
                $response = ['message' => 'Foto berhasil dihapus dari galeri.'];
                $status_code = 200;
            } else {
                throw new Exception('Foto tidak ditemukan atau Anda tidak memiliki akses.', 404);
            }
            $stmtDelete->close();
        }
    }
} catch (Exception $e) {
    $response = ['message' => $e->getMessage()];
    $status_code = ($e->getCode() > 0) ? $e->getCode() : 500;
} finally {
    if (isset($conn)) $conn->close();
    http_response_code($status_code);
    echo json_encode($response);
}
?>
