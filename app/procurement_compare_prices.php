<?php
// File: app/procurement_compare_prices.php
// Penjelasan: API untuk membandingkan harga bahan baku dari supplier-supplier terhubung.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // Ambil daftar relasi bahan baku ke supplier dari supplier_ingredients
    // Join dengan suppliers dan ingredients
    $sql = "SELECT 
                si.ingredient_id,
                si.base_price,
                si.daily_capacity,
                s.id as supplier_id,
                s.supplier_name,
                s.is_verified,
                s.marketplace_id
            FROM supplier_ingredients si
            JOIN suppliers s ON si.supplier_id = s.id
            WHERE s.organization_id = ?
            ORDER BY si.base_price ASC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Group by ingredient_id
    $comparison = [];
    foreach ($result as $row) {
        $comparison[$row['ingredient_id']][] = [
            'supplier_id' => (int)$row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'price' => (float)$row['base_price'],
            'capacity' => (float)$row['daily_capacity'],
            'is_verified' => (int)$row['is_verified'],
            'marketplace_id' => $row['marketplace_id']
        ];
    }
    
    http_response_code(200);
    echo json_encode($comparison);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memuat perbandingan harga.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
