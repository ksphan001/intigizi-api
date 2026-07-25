<?php
// File: app/procurement_compare_prices.php
// Penjelasan: API untuk membandingkan harga bahan baku dari supplier-supplier terhubung.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    if (empty($lat1) || empty($lon1) || empty($lat2) || empty($lon2)) return null;
    $earthRadius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($earthRadius * $c, 1); // km
}

try {
    // Ambil koordinat dapur
    $orgSql = "SELECT latitude, longitude FROM organizations WHERE id = ? LIMIT 1";
    $orgStmt = $conn->prepare($orgSql);
    $orgStmt->bind_param("i", $org_id);
    $orgStmt->execute();
    $org_coords = $orgStmt->get_result()->fetch_assoc();
    $orgStmt->close();
    $dapur_lat = $org_coords ? $org_coords['latitude'] : null;
    $dapur_lng = $org_coords ? $org_coords['longitude'] : null;

    // Ambil daftar relasi bahan baku ke supplier dari supplier_ingredients
    // Join dengan suppliers dan ingredients
    $sql = "SELECT 
                si.ingredient_id,
                si.base_price,
                si.daily_capacity,
                s.id as supplier_id,
                s.supplier_name,
                s.is_verified,
                s.marketplace_id,
                s.latitude,
                s.longitude
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
        $distance = null;
        if ($dapur_lat && $dapur_lng && $row['latitude'] && $row['longitude']) {
            $distance = calculateDistance((float)$dapur_lat, (float)$dapur_lng, (float)$row['latitude'], (float)$row['longitude']);
        }
        $comparison[$row['ingredient_id']][] = [
            'supplier_id' => (int)$row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'price' => (float)$row['base_price'],
            'capacity' => (float)$row['daily_capacity'],
            'is_verified' => (int)$row['is_verified'],
            'marketplace_id' => $row['marketplace_id'],
            'distance_km' => $distance
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
