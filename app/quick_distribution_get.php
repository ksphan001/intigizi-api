<?php
// app/quick_distribution_get.php
// Penjelasan: API untuk mengambil daftar distribusi cepat.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int) $userData['org_id'];

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

try {
    $sql = "SELECT qd.*, dp.name as point_name 
            FROM quick_distributions qd
            JOIN distribution_points dp ON qd.distribution_point_id = dp.id
            WHERE qd.organization_id = ? AND qd.distribution_date BETWEEN ? AND ?
            ORDER BY qd.distribution_date DESC, qd.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Parse JSON nutrition
        $row['nutrition_info'] = json_decode($row['nutrition_info']);

        // Get Photos
        $photo_sql = "SELECT * FROM quick_distribution_photos WHERE quick_distribution_id = ?";
        $photo_stmt = $conn->prepare($photo_sql);
        $photo_stmt->bind_param("i", $row['id']);
        $photo_stmt->execute();
        $row['photos'] = $photo_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $photo_stmt->close();

        $data[] = $row;
    }

    echo json_encode($data);
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => $e->getMessage()]);
}

$conn->close();
?>