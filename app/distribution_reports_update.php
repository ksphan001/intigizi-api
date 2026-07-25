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
$reported_by = isset($data['reported_by']) && !empty($data['reported_by']) ? (int)$data['reported_by'] : null;


// --- VALIDASI GEOFENCING GPS ---
if (($status === 'Diterima' || $status === 'Sebagian Diterima') && isset($data['latitude']) && isset($data['longitude'])) {
    $user_lat = (float)$data['latitude'];
    $user_lng = (float)$data['longitude'];

    if ($user_lat !== 0.0 && $user_lng !== 0.0) {
        $point_sql = "SELECT dp.latitude, dp.longitude, dp.name 
                      FROM distribution_reports dr
                      JOIN distribution_points dp ON dr.distribution_point_id = dp.id
                      WHERE dr.id = ? LIMIT 1";
        $point_stmt = $conn->prepare($point_sql);
        $point_stmt->bind_param("i", $id);
        $point_stmt->execute();
        $point = $point_stmt->get_result()->fetch_assoc();
        $point_stmt->close();

        if ($point && !empty($point['latitude']) && !empty($point['longitude'])) {
            $dest_lat = (float)$point['latitude'];
            $dest_lng = (float)$point['longitude'];

            // Rumus Haversine
            $earth_radius = 6371000; // meter
            $dLat = deg2rad($dest_lat - $user_lat);
            $dLng = deg2rad($dest_lng - $user_lng);
            $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($user_lat)) * cos(deg2rad($dest_lat)) * sin($dLng/2) * sin($dLng/2);
            $c = 2 * atan2(sqrt($a), sqrt(1-$a));
            $distance = $earth_radius * $c;

            // Toleransi 150 meter
            if ($distance > 150.0) {
                http_response_code(400);
                echo json_encode(['message' => "Anda terlalu jauh (" . round($distance) . "m) dari titik '{$point['name']}' untuk mengonfirmasi penerimaan!"]);
                exit();
            }
        }
    }
}

$conn->begin_transaction();
try {
    // 1. Update data utama laporan distribusi
    $sql = "UPDATE distribution_reports SET quantity_received = ?, status = ?, notes = ?, delivery_time = ?, total_beneficiaries = ?, reported_by = COALESCE(?, reported_by) WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssiiii", $quantity_received, $status, $notes, $delivery_time, $total_beneficiaries, $reported_by, $id, $org_id);
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
                
                // BATASAN EKSTENSI KEAMANAN
                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($file_ext, $allowed_exts)) {
                    throw new Exception("Ekstensi file '{$file_ext}' tidak diizinkan. Hanya menerima JPG, JPEG, PNG, WEBP.");
                }

                // BATASAN UKURAN 5MB
                if ($_FILES['photos']['size'][$key] > 5 * 1024 * 1024) {
                    throw new Exception("Ukuran berkas '{$original_name}' melebihi batas maksimal 5MB.");
                }

                // Membuat nama file yang unik secara acak
                $new_filename = "dist_photo_{$id}_" . time() . "_{$key}." . $file_ext;
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
    http_response_code(400); // Bad Request untuk error validasi upload file
    echo json_encode(['message' => $e->getMessage()]);
}

$conn->close();
?>
