<?php
// File: app/ingredients_update.php
// PENJELASAN: Diperbarui untuk menyertakan data gizi 'fiber' (serat) dan 'bdd_percentage'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id) || !isset($data->name) || !isset($data->unit_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID, nama, dan unit_id bahan baku wajib diisi.']);
    exit();
}

$id = (int)$data->id;
$name = $conn->real_escape_string($data->name);
$unit_id = (int)$data->unit_id;
$latest_price = isset($data->latest_price) ? (float)$data->latest_price : 0.00;

// Data gizi
$calories = isset($data->calories) ? (float)$data->calories : 0.00;
$protein = isset($data->protein) ? (float)$data->protein : 0.00;
$carbs = isset($data->carbohydrates) ? (float)$data->carbohydrates : 0.00;
$fat = isset($data->fat) ? (float)$data->fat : 0.00;
$fiber = isset($data->fiber) ? (float)$data->fiber : 0.00; 
// --- PERUBAHAN BARU ---
$bdd_percentage = isset($data->bdd_percentage) ? (float)$data->bdd_percentage : 1.00; // Default 1.00 (100%)

$conn->begin_transaction();

try {
    $qc_parameters = isset($data->qc_parameters) ? $conn->real_escape_string($data->qc_parameters) : null;

    // 1. Update tabel ingredients
    $sql = "UPDATE ingredients SET name = ?, unit_id = ?, latest_price = ?, qc_parameters = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sidsii", $name, $unit_id, $latest_price, $qc_parameters, $id, $org_id);
    $stmt->execute();
    $stmt->close();
    
    // 2. Update atau Insert tabel nutrition_data (termasuk fiber dan bdd_percentage)
    $nutriSql = "INSERT INTO nutrition_data (organization_id, ingredient_id, calories, protein, carbohydrates, fat, fiber, bdd_percentage) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                 calories = VALUES(calories), protein = VALUES(protein), 
                 carbohydrates = VALUES(carbohydrates), fat = VALUES(fat), fiber = VALUES(fiber), bdd_percentage = VALUES(bdd_percentage)";
    $nutriStmt = $conn->prepare($nutriSql);
    // bind_param diperbarui dengan tipe 'd' tambahan untuk bdd_percentage
    $nutriStmt->bind_param("iidddddd", $org_id, $id, $calories, $protein, $carbs, $fat, $fiber, $bdd_percentage);
    $nutriStmt->execute();
    $nutriStmt->close();

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Bahan baku dan data gizinya berhasil diperbarui.']);

} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => "Nama bahan baku '{$name}' sudah ada."]);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui bahan baku.', 'error' => $e->getMessage()]);
    }
} finally {
    if(isset($conn)) $conn->close();
}
?>