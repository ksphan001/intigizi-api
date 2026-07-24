<?php
// File: app/beneficiaries_get_bmi_history.php
// API baru untuk mengambil semua riwayat pengukuran BMI untuk satu penerima manfaat.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$beneficiary_id = isset($_GET['beneficiary_id']) ? (int)$_GET['beneficiary_id'] : 0;

if ($beneficiary_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Penerima Manfaat wajib diisi.']);
    exit();
}

try {
    $sql = "SELECT 
                h.id, h.measurement_date, h.weight_kg, h.height_cm, h.bmi, 
                u.full_name as recorded_by_name
            FROM beneficiary_bmi_history h
            JOIN users u ON h.recorded_by_user_id = u.id
            WHERE h.beneficiary_id = ? AND h.organization_id = ?
            ORDER BY h.measurement_date DESC, h.id DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $beneficiary_id, $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($history);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil riwayat BMI.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>