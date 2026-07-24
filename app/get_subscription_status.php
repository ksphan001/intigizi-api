<?php
// File: app/get_subscription_status.php
// Penjelasan: API endpoint untuk pengguna (Mitra Dapur) mengambil
// status langganan mereka saat ini, serta opsi paket dan rekening bank yang tersedia.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Hanya untuk non-Super Admin
if ($userData['role_id'] == 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak untuk peran ini.']);
    exit();
}

try {
    $response = [
        'organization' => null,
        'packages' => [],
        'bank_accounts' => [],
        'pending_invoice' => null
    ];

    // 1. Ambil status langganan organisasi saat ini
    $orgSql = "SELECT name, subscription_status, subscription_until FROM organizations WHERE id = ?";
    $stmt = $conn->prepare($orgSql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $response['organization'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 2. Ambil semua data pengaturan dari database
    $settingsSql = "SELECT setting_key, setting_value FROM subscription_settings";
    $settingsResult = $conn->query($settingsSql);
    $settings = [];
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
    }
    
    $response['packages'] = $settings['subscription_packages'] ?? [];
    $response['bank_accounts'] = $settings['bank_accounts'] ?? [];

    // 3. Cek apakah ada invoice yang masih menunggu pembayaran untuk organisasi ini
    $invoiceSql = "SELECT id, package_name, amount, created_at FROM subscription_invoices WHERE organization_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1";
    $stmt = $conn->prepare($invoiceSql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $invoiceResult = $stmt->get_result();
    if ($invoiceResult->num_rows > 0) {
        $response['pending_invoice'] = $invoiceResult->fetch_assoc();
    }
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memuat status langganan.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
