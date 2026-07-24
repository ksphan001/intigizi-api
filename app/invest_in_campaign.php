<?php
// File: app/invest_in_campaign.php
// Penjelasan: API terproteksi untuk investor melakukan pembelian lot
// PERBAIKAN:
// 1. Mengganti status dari 'paid' menjadi 'pending'.
// 2. Mengambil dan mengembalikan daftar rekening bank untuk pembayaran.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$user_role = (int)$userData['role_id'];

// Keamanan: Hanya Investor (role_id = 9) yang bisa berinvestasi
if ($user_role !== 9) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Hanya investor yang dapat melakukan aksi ini.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->campaign_id) || !isset($data->lot_count) || (int)$data->lot_count <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Kampanye dan jumlah lot yang valid wajib diisi.']);
    exit();
}

$campaign_id = (int)$data->campaign_id;
$lot_count = (int)$data->lot_count; // Ini adalah variabel $lot_count dari frontend

$conn->begin_transaction();

try {
    // 1. Ambil detail kampanye untuk validasi dan kalkulasi
    $campaignSql = "SELECT title, lot_price, status, user_id, organization_id, organization_id as kitchen_org_id FROM funding_campaigns WHERE id = ? FOR UPDATE";
    $campaignStmt = $conn->prepare($campaignSql);
    $campaignStmt->bind_param("i", $campaign_id);
    $campaignStmt->execute();
    $campaign = $campaignStmt->get_result()->fetch_assoc();
    $campaignStmt->close();

    if (!$campaign) {
        throw new Exception("Proyek pendanaan tidak ditemukan.", 404);
    }
    if ($campaign['status'] !== 'active') {
        throw new Exception("Investasi tidak dapat dilakukan karena proyek ini tidak lagi aktif.", 403);
    }

    // 2. Hitung total investasi
    $lot_price = (float)$campaign['lot_price'];
    $total_investment = $lot_count * $lot_price;
    $kitchen_org_id = $campaign['kitchen_org_id'];

    // 3. Simpan transaksi investasi ke database dengan status 'pending'
    $investSql = "INSERT INTO investments (campaign_id, user_id, kitchen_org_id, lots_purchased, lot_price, total_investment, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')";
    $investStmt = $conn->prepare($investSql);
    $investStmt->bind_param("iiiidd", $campaign_id, $user_id, $kitchen_org_id, $lot_count, $lot_price, $total_investment);
    $investStmt->execute();
    $investment_id = $conn->insert_id;
    $investStmt->close();
    
    // 4. Kirim notifikasi ke pemohon/pemilik proyek
    send_notification(
        $conn,
        $campaign['organization_id'],
        $campaign['user_id'],
        "Investasi Baru Diterima!",
        "Selamat! Proyek '{$campaign['title']}' Anda baru saja menerima investasi sebesar " . number_format($total_investment, 0, ',', '.') . ". Menunggu pembayaran.",
        "/app/funding/dashboard" // Arahkan kembali ke dasbor Calon Mitra
    );
    
    // 5. Kirim notifikasi ke Super Admin
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn, 
                $admin['organization_id'], $admin['id'],
                "Verifikasi Investasi Baru",
                "Investasi baru sebesar " . number_format($total_investment, 0, ',', '.') . " untuk '{$campaign['title']}' menunggu verifikasi pembayaran Anda.",
                "/app/admin/investment-verification" // Link ke halaman verifikasi baru
            );
        }
    }
    
    // 6. Ambil data rekening bank untuk dikirim kembali ke frontend
    $settingsSql = "SELECT setting_value FROM subscription_settings WHERE setting_key = 'bank_accounts' LIMIT 1";
    $settingsResult = $conn->query($settingsSql);
    $bank_accounts = json_decode($settingsResult->fetch_assoc()['setting_value'], true) ?? [];

    $conn->commit();
    http_response_code(201);
    echo json_encode([
        'message' => 'Permintaan investasi Anda telah dicatat. Silakan lakukan pembayaran.',
        'investment_id' => $investment_id,
        'total_investment' => $total_investment,
        'bank_accounts' => $bank_accounts
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>