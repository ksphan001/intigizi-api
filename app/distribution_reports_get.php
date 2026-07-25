<?php
// File: app/distribution_reports_get.php
// Deskripsi: PERBAIKAN FINAL. Menghapus klausa GROUP BY yang menyebabkan 500 Internal Server Error.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : '';

if (empty($start_date) || empty($end_date)) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter start_date dan end_date wajib diisi.']);
    exit();
}

// PERBAIKAN: Menghapus "GROUP BY dr.id" yang menjadi penyebab utama error 500.
// LEFT JOIN tetap dipertahankan untuk ketahanan data.
$sql = "SELECT
            dr.id, dr.distribution_date, dr.distribution_point_id,
            dp.name as distribution_point_name, dr.menu_id, m.menu_name,
            dr.quantity_sent, dr.quantity_received, dr.status, dr.notes,
            dr.reported_by, u.full_name as reporter_name, u.phone_number as reporter_phone, dr.created_at,
            dr.delivery_time, dr.total_beneficiaries,
            COALESCE(
                (SELECT JSON_ARRAYAGG(
                    JSON_OBJECT('id', dp_photos.id, 'image_path', dp_photos.image_path)
                ) 
                FROM distribution_photos dp_photos 
                WHERE dp_photos.report_id = dr.id), 
            JSON_ARRAY()) as photos
        FROM distribution_reports dr
        LEFT JOIN distribution_points dp ON dr.distribution_point_id = dp.id
        LEFT JOIN menus m ON dr.menu_id = m.id
        LEFT JOIN users u ON dr.reported_by = u.id
        WHERE dr.organization_id = ? AND dr.distribution_date BETWEEN ? AND ?
        ORDER BY dr.distribution_date DESC, dp.name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $org_id, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $row['photos'] = json_decode($row['photos']);
        $reports[] = $row;
    }
    echo json_encode($reports);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Query ke database gagal: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>

