<?php
// File: app/reports_get_distribution.php
// Penjelasan: Dikembalikan ke versi yang lebih sederhana dan stabil untuk mengatasi error 500.
// Query ini menghitung total penerima manfaat dengan subquery yang andal.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : null;
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : null;

if (!$start_date || !$end_date) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter start_date dan end_date wajib diisi.']);
    exit();
}

try {
    // Query yang disederhanakan untuk stabilitas
    $sql = "SELECT 
                dp.name AS distribution_point_name,
                (SELECT SUM(dpc.count) FROM distribution_point_counts dpc WHERE dpc.distribution_point_id = dp.id) AS beneficiary_count,
                SUM(dr.quantity_sent) AS total_sent,
                SUM(dr.quantity_received) AS total_received,
                SUM(CASE WHEN dr.status = 'Gagal' THEN 1 ELSE 0 END) AS total_failed,
                SUM(CASE WHEN dr.status = 'Sebagian Diterima' THEN 1 ELSE 0 END) AS total_partial,
                (SUM(dr.quantity_received) / NULLIF(SUM(dr.quantity_sent), 0)) * 100 AS success_rate
            FROM 
                distribution_reports dr
            JOIN 
                distribution_points dp ON dr.distribution_point_id = dp.id
            WHERE 
                dr.organization_id = ? 
                AND dr.distribution_date BETWEEN ? AND ?
            GROUP BY 
                dp.id, dp.name
            ORDER BY 
                dp.name ASC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $report_data = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($report_data);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi kesalahan saat mengambil data laporan.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        if (isset($stmt)) $stmt->close();
        $conn->close();
    }
}
?>

