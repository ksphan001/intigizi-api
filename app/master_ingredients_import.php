<?php
// File: app/master_ingredients_import.php
// PENJELASAN: Diperbarui untuk mengimpor data 'fiber' dan 'bdd_percentage'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->master_ids) || !is_array($data->master_ids) || empty($data->master_ids)) {
    http_response_code(400);
    echo json_encode(['message' => 'Tidak ada bahan baku yang dipilih untuk diimpor.']);
    exit();
}

$master_ids = array_map('intval', $data->master_ids);
$placeholders = implode(',', array_fill(0, count($master_ids), '?'));

$conn->begin_transaction();

try {
    // --- PERUBAHAN BARU ---
    // Ambil 'bdd_percentage' dari master
    $sql_master = "SELECT * FROM master_ingredients WHERE id IN ($placeholders)";
    $stmt_master = $conn->prepare($sql_master);
    $stmt_master->bind_param(str_repeat('i', count($master_ids)), ...$master_ids);
    $stmt_master->execute();
    $ingredients_to_import = $stmt_master->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_master->close();

    if (empty($ingredients_to_import)) {
        throw new Exception("Bahan baku yang dipilih tidak ditemukan di pustaka.", 404);
    }

    $imported_count = 0;
    $skipped_count = 0;

    $sql_ingredient = "INSERT INTO ingredients (organization_id, master_ingredient_id, name, unit_id, latest_price) VALUES (?, ?, ?, ?, ?)";
    $stmt_ingredient = $conn->prepare($sql_ingredient);
    
    // Query diperbarui untuk menyertakan 'fiber' dan 'bdd_percentage'
    $sql_nutrition = "INSERT INTO nutrition_data (organization_id, ingredient_id, calories, protein, carbohydrates, fat, fiber, bdd_percentage) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_nutrition = $conn->prepare($sql_nutrition);

    foreach ($ingredients_to_import as $master_ingredient) {
        $sql_check = "SELECT id FROM ingredients WHERE organization_id = ? AND name = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("is", $org_id, $master_ingredient['name']);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $stmt_check->close();

        if ($result_check->num_rows > 0) {
            $skipped_count++;
            continue;
        }

        $price_to_insert = $master_ingredient['estimated_price'] ?? 0.00;
        $default_unit_id = 2; // Asumsi default 'kg'

        $stmt_ingredient->bind_param("iisid", 
            $org_id, 
            $master_ingredient['id'], 
            $master_ingredient['name'], 
            $default_unit_id, 
            $price_to_insert
        );
        $stmt_ingredient->execute();
        $new_ingredient_id = $conn->insert_id;

        if ($new_ingredient_id > 0) {
            // --- PERUBAHAN BARU ---
            // Ambil bdd_percentage dari data master
            $bdd_to_insert = $master_ingredient['bdd_percentage'] ?? 1.00;
            
            // bind_param diperbarui dengan tipe 'd' tambahan untuk bdd_percentage
            $stmt_nutrition->bind_param("iidddddd", $org_id, $new_ingredient_id, $master_ingredient['calories'], $master_ingredient['protein'], $master_ingredient['carbohydrates'], $master_ingredient['fat'], $master_ingredient['fiber'], $bdd_to_insert);
            $stmt_nutrition->execute();
            $imported_count++;
        }
    }
    
    $stmt_ingredient->close();
    $stmt_nutrition->close();
    
    $conn->commit();

    $message = "{$imported_count} bahan baku berhasil ditambahkan.";
    if ($skipped_count > 0) {
        $message .= " {$skipped_count} dilewati karena namanya sudah ada.";
    }

    http_response_code(201);
    echo json_encode(['message' => $message]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan saat mengimpor bahan baku.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>