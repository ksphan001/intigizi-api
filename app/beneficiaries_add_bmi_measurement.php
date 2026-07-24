<?php
// File: app/beneficiaries_add_bmi_measurement.php
// API baru untuk menambahkan catatan riwayat BMI dan memperbarui data terbaru di tabel beneficiaries.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->beneficiary_id) || !isset($data->weight_kg) || !isset($data->height_cm) || !isset($data->measurement_date)) {
    http_response_code(400);
    echo json_encode(['message' => 'Data tidak lengkap. ID, berat, tinggi, dan tanggal wajib diisi.']);
    exit();
}

$beneficiary_id = (int)$data->beneficiary_id;
$weight_kg = (float)$data->weight_kg;
$height_cm = (float)$data->height_cm;
$measurement_date = $data->measurement_date;

if ($weight_kg <= 0 || $height_cm <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Berat dan tinggi harus lebih besar dari 0.']);
    exit();
}

// Hitung BMI
$height_m = $height_cm / 100;
$bmi = $weight_kg / ($height_m * $height_m);

$conn->begin_transaction();
try {
    // 1. Masukkan ke tabel riwayat
    $sql_history = "INSERT INTO beneficiary_bmi_history 
                        (organization_id, beneficiary_id, measurement_date, weight_kg, height_cm, bmi, recorded_by_user_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt_history = $conn->prepare($sql_history);
    $stmt_history->bind_param("iisdddi", $org_id, $beneficiary_id, $measurement_date, $weight_kg, $height_cm, $bmi, $user_id);
    $stmt_history->execute();
    $stmt_history->close();

    // 2. Update data terbaru di tabel beneficiaries
    $sql_update = "UPDATE beneficiaries SET 
                        current_weight_kg = ?, 
                        current_height_cm = ?, 
                        current_bmi = ? 
                   WHERE id = ? AND organization_id = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("dddii", $weight_kg, $height_cm, $bmi, $beneficiary_id, $org_id);
    $stmt_update->execute();
    $stmt_update->close();
    
    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Data pengukuran BMI berhasil disimpan.']);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menyimpan data pengukuran.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>