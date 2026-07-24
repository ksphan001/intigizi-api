<?php
// File: app/ingredients_create.php
// PENJELASAN: Diperbarui untuk menyertakan data gizi 'fiber' (serat) dan 'bdd_percentage'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->name) || !isset($data->unit_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama dan unit_id bahan baku wajib diisi.']);
    exit();
}

$name = $conn->real_escape_string($data->name);
$unit_id = (int)$data->unit_id;
$latest_price = isset($data->latest_price) ? (float)$data->latest_price : 0.00;

$calories = isset($data->calories) ? (float)$data->calories : 0.00;
$protein = isset($data->protein) ? (float)$data->protein : 0.00;
$carbs = isset($data->carbohydrates) ? (float)$data->carbohydrates : 0.00;
$fat = isset($data->fat) ? (float)$data->fat : 0.00;
$fiber = isset($data->fiber) ? (float)$data->fiber : 0.00; 
// --- PERUBAHAN BARU ---
$bdd_percentage = isset($data->bdd_percentage) ? (float)$data->bdd_percentage : 1.00; // Default 1.00 (100%)

$conn->begin_transaction();

try {
    $sql = "INSERT INTO ingredients (organization_id, name, unit_id, latest_price) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isid", $org_id, $name, $unit_id, $latest_price);
    $stmt->execute();

    $ingredient_id = $conn->insert_id;
    if ($ingredient_id === 0) {
        if ($conn->errno == 1062) {
             throw new Exception("Nama bahan baku '{$name}' sudah ada di dapur Anda.", 409);
        }
        throw new Exception("Gagal mendapatkan ID untuk bahan baku yang baru dibuat.");
    }
    $stmt->close();

    // Query diperbarui untuk menyertakan 'fiber' dan 'bdd_percentage'
    $nutriSql = "INSERT INTO nutrition_data (organization_id, ingredient_id, calories, protein, carbohydrates, fat, fiber, bdd_percentage) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $nutriStmt = $conn->prepare($nutriSql);
    // bind_param diperbarui dengan tipe 'd' tambahan untuk bdd_percentage
    $nutriStmt->bind_param("iidddddd", $org_id, $ingredient_id, $calories, $protein, $carbs, $fat, $fiber, $bdd_percentage);
    $nutriStmt->execute();
    $nutriStmt->close();

    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Bahan baku berhasil ditambahkan.', 'id' => $ingredient_id]);

} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => "Nama bahan baku '{$name}' sudah ada di dapur Anda."]);
    } else {
        $errorCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        http_response_code($errorCode);
        echo json_encode(['message' => $e->getMessage()]);
    }

} finally {
    if (isset($conn)) $conn->close();
}
?>