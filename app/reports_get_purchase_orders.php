<?php
// File: app/reports_get_purchase_orders.php
// PENJELASAN: API ini dirombak untuk menggabungkan data pembelian dari
// Supplier internal dan Vendor eksternal agar laporan menjadi akurat.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Ambil filter tanggal dari query string
$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : null;
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : null;

if (!$start_date || !$end_date) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter start_date dan end_date wajib diisi.']);
    exit();
}

try {
    // --- PERBAIKAN UTAMA DI SINI ---
    // Query menggunakan LEFT JOIN ke kedua tabel (suppliers dan organizations)
    // dan COALESCE untuk mendapatkan nama pemasok yang benar.
    $sql = "SELECT 
                COALESCE(s.supplier_name, o.name) AS supplier_name,
                COUNT(po.id) AS po_count,
                SUM(po.total_amount) AS total_purchase_value
            FROM 
                purchase_orders po
            LEFT JOIN 
                suppliers s ON po.supplier_id = s.id AND s.organization_id = po.organization_id
            LEFT JOIN
                organizations o ON po.supplier_id = o.id AND o.registration_type = 'Vendor'
            WHERE 
                po.organization_id = ? 
                AND DATE(po.created_at) BETWEEN ? AND ?
                AND po.supplier_id IS NOT NULL
                AND COALESCE(s.supplier_name, o.name) IS NOT NULL
            GROUP BY 
                COALESCE(s.supplier_name, o.name)
            ORDER BY 
                total_purchase_value DESC";
            
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
