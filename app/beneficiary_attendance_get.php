<?php
// File: app/beneficiary_attendance_get.php
// Penjelasan: Mengambil status kehadiran makan anak pada tanggal tertentu.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$date = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : date('Y-m-d');

try {
    $sql = "SELECT beneficiary_id, status FROM beneficiary_meals_served WHERE organization_id = ? AND served_date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $org_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode($data);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data absensi.', 'error' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>
