<?php
// File: app/beneficiary_attendance_log.php
// Penjelasan: Endpoint untuk mencatat kehadiran absensi makan harian penerima manfaat (anak).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->served_date) || !isset($data->attendance_list) || !is_array($data->attendance_list)) {
    http_response_code(400);
    echo json_encode(['message' => 'Tanggal (served_date) dan daftar absensi (attendance_list) wajib diisi.']);
    exit();
}

$served_date = $conn->real_escape_string($data->served_date);
$attendance_list = $data->attendance_list;

$conn->begin_transaction();

try {
    // Siapkan query insert / update on duplicate key
    $sql = "INSERT INTO beneficiary_meals_served (organization_id, beneficiary_id, served_date, status) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE status = VALUES(status)";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($attendance_list as $row) {
        if (!isset($row->beneficiary_id) || !isset($row->status)) {
            continue;
        }
        $beneficiary_id = (int)$row->beneficiary_id;
        $status = $conn->real_escape_string($row->status); // 'served' atau 'absent'
        
        $stmt->bind_param("iiss", $org_id, $beneficiary_id, $served_date, $status);
        $stmt->execute();
    }
    
    $stmt->close();
    $conn->commit();
    
    http_response_code(200);
    echo json_encode(['message' => 'Absensi makan harian berhasil disimpan.']);
    
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menyimpan absensi makan.', 'error' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>
