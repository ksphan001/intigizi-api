<?php
// File: app/public_get_distribution_events.php
// Deskripsi: API publik untuk mengambil tanggal-tanggal di mana sebuah dapur
// memiliki data distribusi. Digunakan untuk menandai kalender di frontend.

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$org_id = isset($_GET['org_id']) ? (int) $_GET['org_id'] : 0;
$start_date = isset($_GET['start']) ? $_GET['start'] : '';
$end_date = isset($_GET['end']) ? $_GET['end'] : '';

if ($org_id <= 0 || empty($start_date) || empty($end_date)) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter org_id, start, dan end wajib diisi.']);
    exit();
}

try {
    // Query untuk mengambil tanggal unik dari laporan distribusi
    // Query untuk mengambil tanggal unik dari laporan distribusi (Standard & Quick)
    $sql = "SELECT distribution_date FROM distribution_reports
            WHERE organization_id = ? 
            AND distribution_date BETWEEN ? AND ?
            UNION
            SELECT distribution_date FROM quick_distributions
            WHERE organization_id = ? 
            AND distribution_date BETWEEN ? AND ?
            AND (status = 'Dikirim' OR status = 'Diterima' OR status = 'Terjadwal')";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ississ", $org_id, $start_date, $end_date, $org_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $event_dates = [];
    while ($row = $result->fetch_assoc()) {
        $event_dates[] = $row['distribution_date'];
    }

    $stmt->close();

    http_response_code(200);
    echo json_encode($event_dates);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mengambil data event kalender.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>