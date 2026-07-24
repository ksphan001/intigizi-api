<?php
// File: app/superadmin_dashboard_summary.php
// Penjelasan: API baru khusus untuk menyediakan data ringkasan bagi Dasbor Super Admin.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    $response = [];

    // Helper function untuk query COUNT
    function get_count($conn, $sql, $params = null) {
        $stmt = $conn->prepare($sql);
        if ($params) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($result['count'] ?? 0);
    }

    // 1. Menghitung KPI Utama
    $response['total_mitra'] = get_count($conn, "SELECT COUNT(id) as count FROM organizations WHERE registration_type != 'Vendor' AND is_active = 1");
    $response['total_vendors'] = get_count($conn, "SELECT COUNT(id) as count FROM organizations WHERE registration_type = 'Vendor' AND is_active = 1");
    $response['total_users'] = get_count($conn, "SELECT COUNT(id) as count FROM users WHERE is_active = 1");
    $response['pending_registrations'] = get_count($conn, "SELECT COUNT(id) as count FROM organizations WHERE is_active = 0");

    // 2. Mengambil 5 Pendaftar Terbaru
    $recentSql = "SELECT id, name, registration_type, created_at FROM organizations WHERE is_active = 0 ORDER BY created_at DESC LIMIT 5";
    $recentResult = $conn->query($recentSql);
    $response['recent_registrants'] = $recentResult->fetch_all(MYSQLI_ASSOC);

    // 3. Mengambil Semua Lokasi Dapur Utama untuk Peta Nasional
    $mapSql = "SELECT o.id, o.name, dp.latitude, dp.longitude 
               FROM organizations o
               JOIN distribution_points dp ON o.id = dp.organization_id
               WHERE dp.is_main_kitchen = 1 AND o.is_active = 1";
    $mapResult = $conn->query($mapSql);
    $response['kitchen_locations'] = $mapResult->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data dasbor Super Admin.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
