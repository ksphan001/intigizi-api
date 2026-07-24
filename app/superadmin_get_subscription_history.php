<?php
// File: app/superadmin_get_subscription_history.php
// Penjelasan: API endpoint baru untuk Super Admin mengambil data riwayat
// semua transaksi langganan yang telah berhasil (dibayar).

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
    $response = [
        'total_revenue' => 0,
        'transactions' => []
    ];

    // 1. Hitung total pendapatan dari semua invoice yang sudah dibayar
    $revenueSql = "SELECT SUM(amount) as total FROM subscription_invoices WHERE status = 'paid'";
    $revenueResult = $conn->query($revenueSql);
    $response['total_revenue'] = (float)($revenueResult->fetch_assoc()['total'] ?? 0);

    // 2. Ambil daftar lengkap semua transaksi yang sudah dibayar
    $historySql = "SELECT 
                        si.id,
                        si.package_name,
                        si.amount,
                        si.paid_at,
                        o.name as organization_name,
                        o.subscription_until,
                        u.full_name as verified_by_name
                   FROM subscription_invoices si
                   JOIN organizations o ON si.organization_id = o.id
                   LEFT JOIN users u ON si.verified_by = u.id
                   WHERE si.status = 'paid'
                   ORDER BY si.paid_at DESC";

    $historyResult = $conn->query($historySql);
    $response['transactions'] = $historyResult->fetch_all(MYSQLI_ASSOC);

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memuat riwayat langganan.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
