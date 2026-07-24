<?php
// File: app/vendor_update_profile.php
// Penjelasan: Dirombak untuk menangani FormData, termasuk unggahan file foto profil,
// dan semua field dari kode asli.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

if ($userData['role_id'] != 5) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

// Membaca data dari $_POST karena menggunakan FormData
$name = $_POST['name'] ?? '';
$description = $_POST['vendor_description'] ?? null;
$address = $_POST['vendor_address'] ?? null;
$website = $_POST['vendor_website'] ?? null;
$category_id = isset($_POST['vendor_category_id']) ? (int)$_POST['vendor_category_id'] : null;


if (empty($name)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama vendor wajib diisi.']);
    exit();
}

$conn->begin_transaction();
try {
    $sql = "UPDATE organizations SET 
                name = ?, 
                vendor_description = ?, 
                vendor_address = ?, 
                vendor_website = ?,
                vendor_category_id = ?";
    
    $params = [$name, $description, $address, $website, $category_id];
    $types = "sssii";

    // Menangani unggahan file foto
    if (isset($_FILES['profile_picture'])) {
        $file = $_FILES['profile_picture'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $target_dir = __DIR__ . "/../uploads/profiles/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $new_filename = "profile_{$org_id}_" . time() . "." . $file_ext;

            if (move_uploaded_file($file['tmp_name'], $target_dir . $new_filename)) {
                $sql .= ", profile_picture = ?";
                $params[] = "/uploads/profiles/" . $new_filename;
                $types .= "s";
            } else {
                throw new Exception("Gagal memindahkan file yang diunggah.");
            }
        }
    }

    $sql .= " WHERE id = ?";
    $params[] = $org_id;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        $conn->commit();
        http_response_code(200);
        echo json_encode(['message' => 'Profil berhasil diperbarui.']);
    } else {
        throw new Exception("Gagal memperbarui profil: " . $stmt->error);
    }
    $stmt->close();

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>

