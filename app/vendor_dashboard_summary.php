<?php
// File: app/vendor_dashboard_summary.php
// PENJELASAN: API baru ini khusus dibuat untuk dasbor vendor.
// API ini aman karena hanya mengambil data Purchase Order (PO)
// yang ditujukan untuk ID organisasi vendor yang sedang login.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$vendor_org_id = (int)$userData['org_id'];

// Keamanan: Pastikan hanya user dengan tipe organisasi 'Vendor' yang bisa mengakses
if ($userData['role_id'] != 5 || (isset($userData['org_type']) && $userData['org_type'] !== 'Vendor')) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    $response = [
        'total_revenue' => 0,
        'completed_orders' => 0,
        'pending_orders' => 0,
        'recent_orders' => []
    ];

    // 1. Menghitung Total Pendapatan (dari PO yang sudah 'Selesai')
    // --- PERBAIKAN: Pendapatan dihitung hanya dari PO yang statusnya 'Selesai' ---
    $revenueSql = "SELECT SUM(total_amount) as total FROM purchase_orders WHERE supplier_id = ? AND status = 'Selesai'";
    $stmt = $conn->prepare($revenueSql);
    $stmt->bind_param("i", $vendor_org_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $response['total_revenue'] = (float)($result['total'] ?? 0);
    $stmt->close();

    // 2. Menghitung Pesanan Selesai
    $completedSql = "SELECT COUNT(id) as count FROM purchase_orders WHERE supplier_id = ? AND status = 'Selesai'";
    $stmt = $conn->prepare($completedSql);
    $stmt->bind_param("i", $vendor_org_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $response['completed_orders'] = (int)($result['count'] ?? 0);
    $stmt->close();

    // 3. Menghitung Pesanan Menunggu Konfirmasi
    $pendingSql = "SELECT COUNT(id) as count FROM purchase_orders WHERE supplier_id = ? AND vendor_status = 'Menunggu Konfirmasi'";
    $stmt = $conn->prepare($pendingSql);
    $stmt->bind_param("i", $vendor_org_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $response['pending_orders'] = (int)($result['count'] ?? 0);
    $stmt->close();

    // 4. Mengambil 5 Pesanan Terbaru
    $recentSql = "SELECT 
                    po.id, po.po_code, o.name as kitchen_name, 
                    po.total_amount, po.vendor_status 
                  FROM purchase_orders po 
                  JOIN organizations o ON po.organization_id = o.id 
                  WHERE po.supplier_id = ? 
                  ORDER BY po.created_at DESC 
                  LIMIT 5";
    $stmt = $conn->prepare($recentSql);
    $stmt->bind_param("i", $vendor_org_id);
    $stmt->execute();
    $response['recent_orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mengambil data dasbor vendor.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

