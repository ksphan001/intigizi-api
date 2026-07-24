<?php
// File: app/vendor_manage_portfolio.php
// Penjelasan: API untuk mengelola item portofolio (tambah dengan upload gambar & hapus).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Pastikan hanya Vendor (role_id = 5) yang bisa mengakses
if ($userData['role_id'] != 5) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$action = $_POST['action'] ?? '';
$response = ['message' => 'Aksi tidak valid.'];
$status_code = 400;

try {
    if ($action === 'add' && isset($_FILES['image'])) {
        $title = $_POST['title'] ?? 'Tanpa Judul';
        $description = $_POST['description'] ?? '';

        $target_dir = __DIR__ . "/../uploads/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        $imageFileType = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
        $unique_name = "portfolio_" . $org_id . "_" . time() . "." . $imageFileType;
        $target_file = $target_dir . $unique_name;

        // Validasi file
        if ($_FILES["image"]["size"] > 2000000) throw new Exception("Ukuran file terlalu besar (maks 2MB).");
        if (!in_array($imageFileType, ['jpg', 'png', 'jpeg', 'gif'])) throw new Exception("Hanya format JPG, JPEG, PNG & GIF yang diizinkan.");

        if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
            $image_path = "/uploads/" . $unique_name;
            $sql = "INSERT INTO portfolio_items (organization_id, title, description, image_path) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isss", $org_id, $title, $description, $image_path);
            $stmt->execute();
            $stmt->close();
            
            $response = ['message' => 'Item portofolio berhasil ditambahkan.'];
            $status_code = 201;
        } else {
            throw new Exception("Gagal mengunggah file.");
        }

    } elseif ($action === 'delete') {
        $data = json_decode(file_get_contents("php://input"));
        $item_id = $data->id ?? 0;

        // Ambil path file untuk dihapus dari server
        $sqlSelect = "SELECT image_path FROM portfolio_items WHERE id = ? AND organization_id = ?";
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

        // Hapus record dari database
        $sqlDelete = "DELETE FROM portfolio_items WHERE id = ? AND organization_id = ?";
        $stmtDelete = $conn->prepare($sqlDelete);
        $stmtDelete->bind_param("ii", $item_id, $org_id);
        $stmtDelete->execute();
        
        if ($stmtDelete->affected_rows > 0) {
            $response = ['message' => 'Item portofolio berhasil dihapus.'];
            $status_code = 200;
        } else {
            $response = ['message' => 'Item tidak ditemukan atau Anda tidak memiliki akses.'];
            $status_code = 404;
        }
        $stmtDelete->close();
    }
} catch (Exception $e) {
    $response = ['message' => $e->getMessage()];
    $status_code = 500;
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    http_response_code($status_code);
    echo json_encode($response);
}
?>

