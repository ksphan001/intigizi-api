<?php
// File: app/suppliers_get.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$sql = "SELECT
            s.id, s.supplier_name, s.address, s.contact_person, s.coverage_radius_km, s.coverage_area_desc, s.latitude, s.longitude, s.phone_number as supplier_phone, s.is_verified, s.marketplace_id,
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
    echo json_encode($suppliers);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Query ke database gagal.']);
}

$stmt->close();
$conn->close();
?>
