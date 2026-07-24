<?php
// File: app/stock_get.php
// PENYEMPURNAAN: Kuantitas sekarang dikonversi ke satuan pembelian (kg, liter) agar sesuai dengan label.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// --- PERBAIKAN DI SINI ---
// s.current_quantity dibagi dengan u.conversion_factor untuk mendapatkan nilai dalam satuan pembelian.
$sql = "SELECT
            i.id as ingredient_id, i.name as ingredient_name,
            COALESCE(s.current_quantity / u.conversion_factor, 0) as current_quantity,
            u.symbol as unit_symbol, s.last_updated
        FROM ingredients i
        LEFT JOIN stock s ON i.id = s.ingredient_id AND s.organization_id = ?
        JOIN units u ON i.unit_id = u.id
        WHERE i.organization_id = ?
        ORDER BY i.name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $org_id, $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $stock_levels = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($stock_levels);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Query ke database gagal.']);
}

$stmt->close();
$conn->close();
?>
