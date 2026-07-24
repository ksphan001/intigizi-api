<?php
// app/quick_distribution_create.php
// Penjelasan: API untuk membuat laporan distribusi cepat (manual).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int) $userData['org_id'];
$user_id = (int) $userData['id'];

// Get POST data
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    // Handle form-data fallback if not JSON
    $data = $_POST;
}

// Validasi Input
if (empty($data['distribution_point_id']) || empty($data['distribution_date']) || empty($data['menu_name']) || empty($data['portion_count'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Data tidak lengkap. Titik distribusi, tanggal, nama menu, dan jumlah porsi wajib diisi.']);
    exit();
}

$point_id = (int) $data['distribution_point_id'];
$date = $conn->real_escape_string($data['distribution_date']);
$menu_name = $conn->real_escape_string($data['menu_name']);
$portion_count = (int) $data['portion_count'];
$notes = isset($data['notes']) ? $conn->real_escape_string($data['notes']) : null;

// Nutrition Info (JSON)
$nutrition = [
    'calories' => isset($data['nutrition_calories']) ? (float) $data['nutrition_calories'] : 0,
    'protein' => isset($data['nutrition_protein']) ? (float) $data['nutrition_protein'] : 0,
    'fat' => isset($data['nutrition_fat']) ? (float) $data['nutrition_fat'] : 0,
    'carbs' => isset($data['nutrition_carbs']) ? (float) $data['nutrition_carbs'] : 0
];
$nutrition_json = json_encode($nutrition);

$status = 'Terjadwal';

try {
    $stmt = $conn->prepare("INSERT INTO quick_distributions (organization_id, distribution_point_id, distribution_date, menu_name, portion_count, nutrition_info, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iississs", $org_id, $point_id, $date, $menu_name, $portion_count, $nutrition_json, $notes, $status);

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['message' => 'Distribusi cepat berhasil dijadwalkan.', 'id' => $conn->insert_id]);
    } else {
        throw new Exception("Gagal menyimpan data: " . $stmt->error);
    }
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => $e->getMessage()]);
}

$conn->close();
?>