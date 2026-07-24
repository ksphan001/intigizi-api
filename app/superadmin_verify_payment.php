<?php
// File: app/superadmin_verify_payment.php
// Penjelasan: API endpoint untuk Super Admin mengonfirmasi pembayaran
// dan mengaktifkan langganan sebuah organisasi.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$admin_id = (int)$userData['id'];

// Keamanan: Hanya Super Admin yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->invoice_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Invoice wajib diisi.']);
    exit();
}

$invoice_id = (int)$data->invoice_id;

$conn->begin_transaction();

try {
    // 1. Ambil detail invoice dan pastikan statusnya 'pending'
    $invoiceSql = "SELECT organization_id, duration_days FROM subscription_invoices WHERE id = ? AND status = 'pending' FOR UPDATE";
    $stmt = $conn->prepare($invoiceSql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        throw new Exception("Invoice tidak ditemukan atau sudah diproses.", 404);
    }

    $org_id = $invoice['organization_id'];
    $duration_days = $invoice['duration_days'];

    // 2. Update status invoice menjadi 'paid'
    $updateInvoiceSql = "UPDATE subscription_invoices SET status = 'paid', paid_at = NOW(), verified_by = ? WHERE id = ?";
    $stmt = $conn->prepare($updateInvoiceSql);
    $stmt->bind_param("ii", $admin_id, $invoice_id);
    $stmt->execute();
    $stmt->close();

    // 3. Update status dan masa aktif langganan organisasi
    // Logika: Jika langganan sebelumnya sudah ada dan masih aktif, perpanjang dari tanggal 'subscription_until'.
    // Jika tidak, perpanjang dari hari ini.
    $updateOrgSql = "UPDATE organizations SET 
                        subscription_status = 'active', 
                        subscription_until = IF(subscription_until IS NOT NULL AND subscription_until > CURDATE(), 
                                                DATE_ADD(subscription_until, INTERVAL ? DAY), 
                                                DATE_ADD(CURDATE(), INTERVAL ? DAY))
                    WHERE id = ?";
    $stmt = $conn->prepare($updateOrgSql);
    $stmt->bind_param("iii", $duration_days, $duration_days, $org_id);
    $stmt->execute();
    $stmt->close();

    // 4. Kirim notifikasi ke administrator organisasi tersebut
    $adminOrgSql = "SELECT id FROM users WHERE organization_id = ? AND role_id = 7 LIMIT 1";
    $stmt = $conn->prepare($adminOrgSql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $org_admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($org_admin) {
        send_notification(
            $conn,
            $org_id,
            $org_admin['id'],
            "Langganan Anda Telah Aktif",
            "Pembayaran Anda telah diverifikasi. Terima kasih telah berlangganan.",
            "/app/subscription"
        );
    }

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Pembayaran berhasil diverifikasi dan langganan telah diaktifkan.']);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Gagal memverifikasi pembayaran.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
