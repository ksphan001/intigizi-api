<?php
// File: app/suppliers_get.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id dan perhitungan jarak ke Dapur Utama.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// 1. Ambil koordinat Dapur Utama
$kitchenSql = "SELECT latitude, longitude FROM distribution_points WHERE organization_id = ? AND is_main_kitchen = 1 LIMIT 1";
$kStmt = $conn->prepare($kitchenSql);
$kStmt->bind_param("i", $org_id);
$kStmt->execute();
$kitchen = $kStmt->get_result()->fetch_assoc();
$kStmt->close();

$k_lat = $kitchen ? (float)$kitchen['latitude'] : null;
$k_lng = $kitchen ? (float)$kitchen['longitude'] : null;

// Helper Haversine Distance
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return null;
    if ($lat1 == 0 || $lon1 == 0 || $lat2 == 0 || $lon2 == 0) return null;
    
    $earth_radius = 6371; // km
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
         
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($earth_radius * $c, 2);
}

$sql = "SELECT
            s.id, s.supplier_name, s.address, s.contact_person, s.coverage_radius_km, s.coverage_area_desc, s.latitude, s.longitude, s.phone_number as supplier_phone, s.is_verified, s.marketplace_id, s.is_local_override,
            s.average_rating, s.review_count, s.sla_score, s.avg_process_time_hours,
            u.id as user_id, u.username, u.email, u.phone_number
        FROM suppliers s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.organization_id = ?
        ORDER BY s.supplier_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $suppliers = $result->fetch_all(MYSQLI_ASSOC);
    
    // Hitung jarak untuk setiap supplier
    foreach ($suppliers as &$sup) {
        $sup_lat = $sup['latitude'] ? (float)$sup['latitude'] : null;
        $sup_lng = $sup['longitude'] ? (float)$sup['longitude'] : null;
        
        $distance = calculate_distance($k_lat, $k_lng, $sup_lat, $sup_lng);
        $sup['distance_km'] = $distance;
        
        $radius = (float)($sup['coverage_radius_km'] ?? 15.00);
        $sup['is_out_of_range'] = ($distance !== null && $distance > $radius) ? 1 : 0;
    }
    unset($sup);
    
    echo json_encode($suppliers);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Query ke database gagal.']);
}

$stmt->close();
$conn->close();
?>
