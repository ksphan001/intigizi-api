<?php
// File: app/distribution_reports_create.php
// Penjelasan: API untuk membuat laporan distribusi baru.
// PERBAIKAN: Status default diubah menjadi 'Terjadwal'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

$data = $_POST;

// Validasi input dasar
if (!isset($data['distribution_date']) || !isset($data['distribution_point_id']) || !isset($data['quantity_sent'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Tanggal, titik distribusi, dan jumlah kirim wajib diisi.']);
    exit();
}

if (empty($data['menu_id'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Tidak ada menu yang terjadwal untuk tanggal yang dipilih. Laporan tidak dapat dibuat.']);
    exit();
}

$distribution_date = $data['distribution_date'];
$distribution_point_id = (int)$data['distribution_point_id'];
$menu_id = (int)$data['menu_id'];
$quantity_sent = (int)$data['quantity_sent'];
$notes = isset($data['notes']) ? $conn->real_escape_string($data['notes']) : null;
$delivery_time = isset($data['delivery_time']) && !empty($data['delivery_time']) ? $conn->real_escape_string($data['delivery_time']) : null;
$total_beneficiaries = isset($data['total_beneficiaries']) ? (int)$data['total_beneficiaries'] : null;
$status = 'Terjadwal'; // Default status saat dijadwalkan

$reported_by = isset($data['reported_by']) && !empty($data['reported_by']) ? (int)$data['reported_by'] : $user_id;

$conn->begin_transaction();
try {
    // Validasi Duplikasi
    $dupCheckSql = "SELECT id FROM distribution_reports WHERE organization_id = ? AND distribution_date = ? AND distribution_point_id = ?";
    $dupStmt = $conn->prepare($dupCheckSql);
    $dupStmt->bind_param("isi", $org_id, $distribution_date, $distribution_point_id);
    $dupStmt->execute();
    if ($dupStmt->get_result()->num_rows > 0) {
        throw new Exception("Laporan distribusi untuk lokasi dan tanggal ini sudah pernah dibuat.", 409);
    }
    $dupStmt->close();

    // Query INSERT
    $sql = "INSERT INTO distribution_reports (organization_id, distribution_date, distribution_point_id, menu_id, quantity_sent, notes, reported_by, status, delivery_time, total_beneficiaries) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isiiissssi", $org_id, $distribution_date, $distribution_point_id, $menu_id, $quantity_sent, $notes, $reported_by, $status, $delivery_time, $total_beneficiaries);

    if ($stmt->execute()) {
        $conn->commit();
        http_response_code(201);
        echo json_encode(['message' => 'Jadwal pengiriman berhasil dibuat (Status: Terjadwal).', 'id' => $conn->insert_id]);
    } else {
        if ($conn->errno == 1452) { 
            throw new Exception("Gagal menyimpan: Pastikan semua data yang dipilih valid.", 400);
        }
        throw new Exception('Gagal menyimpan laporan ke database: ' . $stmt->error);
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