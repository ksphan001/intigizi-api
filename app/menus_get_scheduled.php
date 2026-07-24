<?php
// File: app/menus_get_scheduled.php
// Penjelasan: PERBAIKAN KRITIS. Menambahkan filter organization_id untuk keamanan dan akurasi data.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$filter_date = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : '';

if (empty($filter_date)) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter tanggal (date) wajib diisi.']);
    exit();
}

// --- PERBAIKAN: Query ditambahkan `AND p.organization_id = ?` ---
$sql = "SELECT
            m.id,
            m.menu_name
        FROM proposal_menus pm
        JOIN menus m ON pm.menu_id = m.id
        JOIN proposals p ON pm.proposal_id = p.id
        WHERE
            pm.serving_date = ? AND p.status = 'Disetujui' AND p.organization_id = ?
        GROUP BY m.id, m.menu_name
        LIMIT 1"; // Asumsi hanya ada satu menu per hari per dapur

$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $filter_date, $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $menu = $result->fetch_assoc();
    // Mengembalikan null jika tidak ada menu, yang akan ditangani oleh frontend.
    echo json_encode($menu); 
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Query ke database gagal.']);
}

$stmt->close();
$conn->close();
?>
