<?php
// File: app/reports_get_ingredient_usage.php
// PENYEMPURNAAN: Kuantitas sekarang dikonversi ke satuan pembelian (kg, liter) agar sesuai dengan label.

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
    // --- PERBAIKAN DI SINI ---
    // SUM(st.quantity) dibagi dengan u.conversion_factor untuk mendapatkan nilai dalam satuan pembelian.
    $sql = "SELECT 
                i.name AS ingredient_name,
                u.symbol AS unit_symbol,
                SUM(st.quantity / u.conversion_factor) AS total_quantity_used,
                SUM(st.quantity * (i.latest_price / u.conversion_factor)) AS estimated_cost
            FROM 
                stock_transactions st
            JOIN 
                ingredients i ON st.ingredient_id = i.id
            JOIN 
                units u ON i.unit_id = u.id
            WHERE 
                st.organization_id = ? 
                AND st.type = 'Keluar'
                AND DATE(st.transaction_date) BETWEEN ? AND ?
            GROUP BY 
                i.name, u.symbol, u.conversion_factor
            ORDER BY 
                estimated_cost DESC";
            
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
