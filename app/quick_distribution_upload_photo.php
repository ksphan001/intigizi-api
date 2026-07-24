<?php
// app/quick_distribution_upload_photo.php
// Penjelasan: API upload foto dokumentasi distribusi cepat.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int) $userData['org_id'];

if (empty($_POST['id']) || empty($_FILES['photo'])) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Distribusi dan file foto wajib diunggah.']);
    exit();
}

$id = (int) $_POST['id'];
$caption = isset($_POST['caption']) ? $conn->real_escape_string($_POST['caption']) : '';

try {
    // Verifikasi kepemilikan
    $check = $conn->prepare("SELECT id FROM quick_distributions WHERE id = ? AND organization_id = ?");
    $check->bind_param("ii", $id, $org_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        throw new Exception("Data tidak ditemukan atau akses ditolak.", 403);
    }
    $check->close();

    // Upload File
    $uploadDir = __DIR__ . '/../uploads/distribution_photos/';
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0755, true);

    $file = $_FILES['photo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception("Hanya file gambar (JPG, PNG, WEBP) yang diperbolehkan.");
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'quick_' . $id . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $dbPath = '/uploads/distribution_photos/' . $filename;

        $stmt = $conn->prepare("INSERT INTO quick_distribution_photos (quick_distribution_id, image_path, caption) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $id, $dbPath, $caption);

        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(['message' => 'Foto berhasil diunggah.', 'path' => $dbPath]);
        } else {
            throw new Exception("Gagal menyimpan ke database.");
        }
    } else {
        throw new Exception("Gagal mengunggah file.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => $e->getMessage()]);
}
?>