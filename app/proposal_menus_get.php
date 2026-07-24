<?php
// File: app/proposal_menus_get.php
// Penjelasan: Disederhanakan untuk hanya mengambil data jadwal menu dasar.
// Kalkulasi gizi yang kompleks telah dipindahkan ke proposal_calculate.php untuk sentralisasi.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$proposal_id = isset($_GET['proposal_id']) ? (int)$_GET['proposal_id'] : 0;

if ($proposal_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID proposal wajib disertakan.']);
    exit();
}

// Query disederhanakan, tidak lagi menghitung gizi di sini.
$sql = "SELECT
            pm.id, 
            pm.serving_date, 
            pm.menu_id, 
            m.menu_name
        FROM 
            proposal_menus pm
        LEFT JOIN 
            menus m ON pm.menu_id = m.id
        WHERE 
            pm.proposal_id = ? AND pm.organization_id = ?
        ORDER BY 
            pm.serving_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $proposal_id, $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $schedule = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode($schedule);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Query ke database gagal: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>
