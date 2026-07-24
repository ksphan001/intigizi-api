<?php
// app/quick_distribution_update_status.php
// Penjelasan: API untuk update status distribusi cepat.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int) $userData['org_id'];

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['id']) || empty($data['status'])) {
    http_response_code(400);
    echo json_encode(['message' => 'ID dan Status wajib diisi.']);
    exit();
}

$id = (int) $data['id'];
$status = $conn->real_escape_string($data['status']);
$valid_statuses = ['Terjadwal', 'Dikirim', 'Diterima', 'Dibatalkan'];

if (!in_array($status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['message' => 'Status tidak valid.']);
    exit();
}

try {
    // Verifikasi kepemilikan
    $check = $conn->prepare("SELECT id FROM quick_distributions WHERE id = ? AND organization_id = ?");
    $check->bind_param("ii", $id, $org_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        throw new Exception("Data tidak ditemukan atau akses ditolak.", 403);
    }
    $check->close();

    $delivery_time_sql = "";
    $param_types = "si";
    $params = [$status, $id];

    if ($status === 'Dikirim' || $status === 'Diterima') {
        // Set delivery_time to NOW if not set (or typically meaningful for 'Dikirim')
        // For simplicity, we update delivery_time on status change if relevant
        $delivery_time_sql = ", delivery_time = NOW()";
    }

    $sql = "UPDATE quick_distributions SET status = ? $delivery_time_sql WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$params);

    if ($stmt->execute()) {
        echo json_encode(['message' => 'Status berhasil diperbarui.']);
    } else {
        throw new Exception("Gagal update status: " . $stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['message' => $e->getMessage()]);
}

$conn->close();
?>