<?php
// File: app/superadmin_get_pending_investments.php
// API BARU: Untuk Super Admin mengambil daftar investasi yang menunggu verifikasi.
// MODIFIKASI: Menambahkan 'payment_proof_path'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin (role_id = 8)
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    $sql = "SELECT 
                i.id,
                i.investment_date,
                i.total_investment,
                i.lots_purchased,
                i.lot_price,
                i.payment_proof_path,
                u.full_name as investor_name,
                fc.title as campaign_title
            FROM investments i
            JOIN users u ON i.user_id = u.id
            JOIN funding_campaigns fc ON i.campaign_id = fc.id
            WHERE i.status = 'pending'
            ORDER BY i.investment_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $investments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode($investments);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memuat daftar verifikasi investasi.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>