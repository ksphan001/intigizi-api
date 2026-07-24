<?php
// File: app/distribution_photo_delete.php
// Deskripsi: API endpoint baru untuk menghapus satu foto dokumentasi.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->photo_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID foto wajib diisi.']);
    exit();
}

$photo_id = (int)$data->photo_id;

$conn->begin_transaction();
try {
    // 1. Ambil path file dan verifikasi kepemilikan
    $sql_select = "SELECT dp.image_path 
                   FROM distribution_photos dp
                   JOIN distribution_reports dr ON dp.report_id = dr.id
                   WHERE dp.id = ? AND dr.organization_id = ?";
    $stmt_select = $conn->prepare($sql_select);
    $stmt_select->bind_param("ii", $photo_id, $org_id);
    $stmt_select->execute();
    $result = $stmt_select->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Foto tidak ditemukan atau Anda tidak memiliki akses.", 404);
    }
    $photo_data = $result->fetch_assoc();
    $stmt_select->close();

    // 2. Hapus file dari server
    $file_path = __DIR__ . '/..' . $photo_data['image_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }

    // 3. Hapus record dari database
    $sql_delete = "DELETE FROM distribution_photos WHERE id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("i", $photo_id);
    $stmt_delete->execute();
    $stmt_delete->close();

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Foto berhasil dihapus.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(['message' => $e->getMessage()]);
}

$conn->close();
?>
