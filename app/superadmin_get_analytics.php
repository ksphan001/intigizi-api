<?php
// File: app/superadmin_get_analytics.php
// Penjelasan: API disempurnakan untuk menyediakan data analitik yang lebih kaya dan lengkap bagi Super Admin.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

// Ambil parameter tanggal dan filter dapur
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$org_id = isset($_GET['org_id']) && !empty($_GET['org_id']) ? (int)$_GET['org_id'] : null;

try {
    $response = [
        'kpi' => [
            'active_kitchens' => 0,
            'total_vendors' => 0,
            'pending_registrations' => 0,
            'total_revenue' => 0,
        ],
        'daily_chart' => [],
        'subscription_summary' => [],
        'province_summary' => [],
        'kitchen_list' => [],
    ];

    // --- Helper function ---
    function execute_query($conn, $sql, $types = null, $params = []) {
        $stmt = $conn->prepare($sql);
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    // 1. Ambil daftar dapur untuk filter
    $response['kitchen_list'] = execute_query($conn, "SELECT id, name FROM organizations WHERE registration_type != 'Vendor' AND is_active = 1 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

    // --- Bangun klausa filter org_id ---
    $org_filter_clauses = [ 'pl' => '', 'dr' => '', 'po' => '', 'o' => '' ];
    $org_params = [];
    $org_types = '';
    if ($org_id) {
        $org_filter_clauses['pl'] = ' AND pl.organization_id = ? ';
        $org_filter_clauses['dr'] = ' AND dr.organization_id = ? ';
        $org_filter_clauses['po'] = ' AND po.organization_id = ? ';
        $org_filter_clauses['o'] = ' AND o.id = ? ';
        $org_params[] = $org_id;
        $org_types = 'i';
    }

    // 2. KPI Utama (KPIs that are not date-filtered)
    $response['kpi']['active_kitchens'] = execute_query($conn, "SELECT COUNT(id) as count FROM organizations WHERE registration_type != 'Vendor' AND is_active = 1 {$org_filter_clauses['o']}", $org_types, $org_params)->fetch_assoc()['count'] ?? 0;
    $response['kpi']['total_vendors'] = execute_query($conn, "SELECT COUNT(id) as count FROM organizations WHERE registration_type = 'Vendor' AND is_active = 1")->fetch_assoc()['count'] ?? 0;
    $response['kpi']['pending_registrations'] = execute_query($conn, "SELECT COUNT(id) as count FROM organizations WHERE is_active = 0")->fetch_assoc()['count'] ?? 0;
    $response['kpi']['total_revenue'] = execute_query($conn, "SELECT SUM(amount) as total FROM subscription_invoices WHERE status = 'paid'")->fetch_assoc()['total'] ?? 0;
    
    // 3. Data Grafik Harian (Produksi & Distribusi)
    $daily_sql = "
        SELECT 
            d.date,
            COALESCE(prod.total, 0) as total_production,
            COALESCE(dist.total, 0) as total_distribution
        FROM 
            (SELECT ADDDATE(?, a.i + b.i * 10) as date FROM (SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a, (SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b WHERE ADDDATE(?, a.i + b.i * 10) <= ?) d
        LEFT JOIN 
            (SELECT pl.production_date as date, SUM(p.target_recipients) as total FROM production_logs pl JOIN proposals p ON pl.proposal_id = p.id WHERE 1=1 {$org_filter_clauses['pl']} GROUP BY date) prod ON d.date = prod.date
        LEFT JOIN 
            (SELECT dr.distribution_date as date, SUM(dr.quantity_received) as total FROM distribution_reports dr WHERE 1=1 {$org_filter_clauses['dr']} GROUP BY date) dist ON d.date = dist.date
        WHERE d.date BETWEEN ? AND ?
        ORDER BY d.date ASC
    ";
    $all_params = array_merge([$start_date, $start_date, $end_date], $org_params, $org_params, [$start_date, $end_date]);
    $all_types = "sss" . $org_types . $org_types . "ss";
    $response['daily_chart'] = execute_query($conn, $daily_sql, $all_types, $all_params)->fetch_all(MYSQLI_ASSOC);

    // 4. Ringkasan Status Langganan (tidak difilter)
    $response['subscription_summary'] = execute_query($conn, "SELECT subscription_status as status, COUNT(id) as count FROM organizations WHERE registration_type != 'Vendor' GROUP BY subscription_status")->fetch_all(MYSQLI_ASSOC);
    
    // 5. Ringkasan Mitra per Provinsi (tidak difilter)
    $response['province_summary'] = execute_query($conn, "SELECT p.name as province_name, COUNT(o.id) as count FROM organizations o JOIN provinces p ON o.province_id = p.id WHERE o.registration_type != 'Vendor' AND o.is_active = 1 GROUP BY p.name HAVING count > 0 ORDER BY count DESC")->fetch_all(MYSQLI_ASSOC);

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server saat mengambil data analitik.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

