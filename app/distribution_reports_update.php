<?php
// File: app/distribution_reports_update.php
// Deskripsi: Dirombak total untuk menangani multipart/form-data (update data + upload foto).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Karena kita menggunakan FormData, data ada di $_POST
$data = $_POST;

if (!isset($data['id']) || !isset($data['status']) || !isset($data['quantity_received'])) {
    http_response_code(400);
    echo json_encode(['message' => 'ID laporan, status, dan jumlah diterima wajib diisi.']);
    exit();
}

$id = (int)$data['id'];
$status = $conn->real_escape_string($data['status']);
$quantity_received = (int)$data['quantity_received'];
$notes = isset($data['notes']) ? $conn->real_escape_string($data['notes']) : null;
// --- DATA BARU ---
$delivery_time = isset($data['delivery_time']) && !empty($data['delivery_time']) ? $conn->real_escape_string($data['delivery_time']) : null;
$total_beneficiaries = isset($data['total_beneficiaries']) ? (int)$data['total_beneficiaries'] : null;


$conn->begin_transaction();
try {
    // 1. Update data utama laporan distribusi
    $sql = "UPDATE distribution_reports SET quantity_received = ?, status = ?, notes = ?, delivery_time = ?, total_beneficiaries = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssiii", $quantity_received, $status, $notes, $delivery_time, $total_beneficiaries, $id, $org_id);
    $stmt->execute();
    $stmt->close();

    // 2. Proses upload foto jika ada
    if (isset($_FILES['photos'])) {
        $upload_dir = __DIR__ . '/../uploads/distribution_photos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true);
        }

        $photo_sql = "INSERT INTO distribution_photos (report_id, image_path) VALUES (?, ?)";
        $photo_stmt = $conn->prepare($photo_sql);

        foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                $original_name = basename($_FILES['photos']['name'][$key]);
                $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                $safe_name = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
                
                // Membuat nama file yang unik
                $new_filename = "dist_photo_{$id}_" . time() . "_{$key}.{$file_ext}";
                $target_file = $upload_dir . $new_filename;

                if (move_uploaded_file($tmp_name, $target_file)) {
                    $file_path = "/uploads/distribution_photos/" . $new_filename;
                    $photo_stmt->bind_param("is", $id, $file_path);
                    $photo_stmt->execute();
                }
            }
        }
        $photo_stmt->close();
    }

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Laporan distribusi berhasil diperbarui.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memperbarui laporan: ' . $e->getMessage()]);
}

$conn->close();
?>
