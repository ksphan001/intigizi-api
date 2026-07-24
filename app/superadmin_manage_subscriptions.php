<?php
// File: app/superadmin_manage_subscriptions.php
// Penjelasan: API endpoint untuk Super Admin mengelola pengaturan langganan.
// Mendukung metode GET (ambil data) dan POST (simpan data).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin (role_id = 8) yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Mengambil semua pengaturan dari database
        $sql = "SELECT setting_key, setting_value FROM subscription_settings";
        $result = $conn->query($sql);
        
        $settings = [];
        while($row = $result->fetch_assoc()) {
            // Decode JSON untuk paket dan rekening bank
            if ($row['setting_key'] === 'subscription_packages' || $row['setting_key'] === 'bank_accounts') {
                $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
            } else {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        
        http_response_code(200);
        echo json_encode($settings);

    } elseif ($method === 'POST') {
        // Menyimpan pengaturan yang dikirim dari frontend
        $data = json_decode(file_get_contents("php://input"), true);
        
        $conn->begin_transaction();
        
        // Query untuk update atau insert (UPSERT)
        $sql = "INSERT INTO subscription_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $conn->prepare($sql);
        
        // 1. Simpan Free Trial Days
        $stmt->bind_param("ss", $key_trial, $value_trial);
        $key_trial = 'free_trial_days';
        $value_trial = (int)$data['free_trial_days'];
        $stmt->execute();
        
        // 2. Simpan Paket Berlangganan (sebagai JSON)
        $stmt->bind_param("ss", $key_packages, $value_packages);
        $key_packages = 'subscription_packages';
        $value_packages = json_encode($data['packages']);
        $stmt->execute();

        // 3. Simpan Rekening Bank (sebagai JSON)
        $stmt->bind_param("ss", $key_accounts, $value_accounts);
        $key_accounts = 'bank_accounts';
        $value_accounts = json_encode($data['bank_accounts']);
        $stmt->execute();
        
        $stmt->close();
        $conn->commit();
        
        http_response_code(200);
        echo json_encode(['message' => 'Pengaturan langganan berhasil disimpan.']);
    } else {
        http_response_code(405);
        echo json_encode(['message' => 'Metode tidak diizinkan.']);
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>

