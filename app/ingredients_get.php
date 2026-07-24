<?php
// File: app/ingredients_get.php
// PENJELASAN: Diperbarui untuk mengambil data 'fiber' dan 'bdd_percentage'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // Query diperbarui untuk menyertakan 'nd.fiber' dan 'nd.bdd_percentage'
    $sql = "SELECT
                i.id,
                i.name,
                i.latest_price,
                u.id as unit_id,
                u.name as unit_name,
                u.symbol as unit_symbol,
                nd.calories,
                nd.protein,
                nd.carbohydrates,
                nd.fat,
                nd.fiber,
                nd.bdd_percentage,
                i.qc_parameters
            FROM
                ingredients i
            JOIN
                units u ON i.unit_id = u.id
            LEFT JOIN 
                nutrition_data nd ON i.id = nd.ingredient_id AND nd.organization_id = i.organization_id
            WHERE
                i.organization_id = ?
            ORDER BY
                i.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $ingredients = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($ingredients);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi error internal pada server.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        if(isset($stmt)) $stmt->close();
        $conn->close();
    }
}
?>