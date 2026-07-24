<?php
// File: app/superadmin_verify_investment.php
// API BARU: Untuk Super Admin menyetujui atau menolak pembayaran investasi.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$admin_id = (int)$userData['id'];

// Keamanan: Hanya Super Admin (role_id = 8)
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->investment_id) || !isset($data->action)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Investasi dan Aksi (approve/reject) wajib diisi.']);
    exit();
}

$investment_id = (int)$data->investment_id;
$action = $data->action; // 'approve' or 'reject'
$new_status = $action === 'approve' ? 'paid' : 'cancelled';

$conn->begin_transaction();

try {
    // 1. Ambil info investasi untuk notifikasi
    $infoSql = "SELECT 
                    i.user_id, i.total_investment,
                    fc.title as campaign_title,
                    u.full_name as investor_name, u.email as investor_email
                FROM investments i
                JOIN funding_campaigns fc ON i.campaign_id = fc.id
                JOIN users u ON i.user_id = u.id
                WHERE i.id = ? AND i.status = 'pending' FOR UPDATE";
    $stmt = $conn->prepare($infoSql);
    $stmt->bind_param("i", $investment_id);
    $stmt->execute();
    $investment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$investment) {
        throw new Exception("Investasi tidak ditemukan atau sudah diproses.", 404);
    }

    // 2. Update status investasi
    $updateSql = "UPDATE investments SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param("si", $new_status, $investment_id);
    $stmt->execute();
    $stmt->close();

    // 3. Kirim notifikasi ke Investor
    $notif_title = $action === 'approve' ? "Investasi Dikonfirmasi" : "Investasi Ditolak";
    $notif_message = $action === 'approve' 
        ? "Selamat! Pembayaran Anda sebesar " . number_format($investment['total_investment'], 0, ',', '.') . " untuk proyek '{$investment['campaign_title']}' telah dikonfirmasi."
        : "Mohon maaf, pembayaran investasi Anda untuk proyek '{$investment['campaign_title']}' ditolak. Silakan hubungi admin.";
    
    // Kirim notifikasi in-app
    send_notification(
        $conn,
        null, // organization_id (investor tidak punya)
        $investment['user_id'],
        $notif_title,
        $notif_message,
        "/app/investor/dashboard"
    );
    
    // Kirim notifikasi email
    send_direct_email(
        $investment['investor_email'],
        $investment['investor_name'],
        $notif_title,
        $notif_message
    );

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Status investasi berhasil diperbarui.']);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Gagal memverifikasi investasi.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>