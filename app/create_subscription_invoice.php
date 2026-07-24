<?php
// File: app/create_subscription_invoice.php
// Penjelasan: API endpoint yang dipanggil saat pengguna memilih paket berlangganan.
// Fungsinya adalah membuat "tagihan" di database dengan status 'pending'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

// Validasi input
if (!isset($data->package_name) || !isset($data->price) || !isset($data->duration_days)) {
    http_response_code(400);
    echo json_encode(['message' => 'Data paket tidak lengkap.']);
    exit();
}

$package_name = $data->package_name;
$price = (float)$data->price;
$duration_days = (int)$data->duration_days;

$conn->begin_transaction();

try {
    // 1. Cek apakah sudah ada invoice yang 'pending'
    $checkSql = "SELECT id FROM subscription_invoices WHERE organization_id = ? AND status = 'pending'";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $org_id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        throw new Exception("Anda sudah memiliki tagihan yang menunggu pembayaran. Silakan selesaikan pembayaran atau hubungi admin.", 409);
    }
    $checkStmt->close();

    // 2. Buat invoice baru
    $sql = "INSERT INTO subscription_invoices (organization_id, package_name, amount, duration_days, status) VALUES (?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isdi", $org_id, $package_name, $price, $duration_days);
    $stmt->execute();
    $invoice_id = $conn->insert_id;
    $stmt->close();

    // 3. Kirim notifikasi ke semua Super Admin
    $orgNameSql = "SELECT name FROM organizations WHERE id = ?";
    $orgNameStmt = $conn->prepare($orgNameSql);
    $orgNameStmt->bind_param("i", $org_id);
    $orgNameStmt->execute();
    $org_name = $orgNameStmt->get_result()->fetch_assoc()['name'] ?? 'Sebuah Organisasi';
    $orgNameStmt->close();
    
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn,
                $admin['organization_id'],
                $admin['id'],
                "Permintaan Langganan Baru",
                "Organisasi '{$org_name}' telah memilih paket '{$package_name}' dan menunggu verifikasi pembayaran.",
                "/app/admin/subscription-verification" // (Halaman ini akan dibuat di Tahap 3)
            );
        }
    }

    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Permintaan langganan Anda telah dicatat. Silakan lakukan pembayaran.', 'invoice_id' => $invoice_id]);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Gagal membuat permintaan langganan.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
