<?php
// File: app/funding_application_create.php
// PERBAIKAN: Memperbaiki tipe data di bind_param (baris 63).
// String tipe yang salah '...ddsis...' diubah menjadi '...ddsid...'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_engine.php';
require_once __DIR__ . '/auth_middleware.php'; // 1. Proteksi endpoint

$userData = verify_jwt_token(); // 2. Verifikasi token
$org_id = (int)$userData['org_id']; // 3. Ambil org_id dari token

// Data formulir ada di $_POST karena kita menggunakan multipart/form-data
$data = $_POST;

// 4. Validasi input
$required_fields = [
    'pic_full_name' => 'Nama Lengkap PIC', 'pic_email' => 'Email PIC', 'pic_whatsapp' => 'WhatsApp PIC',
    'legal_entity_type' => 'Bentuk Badan Hukum', 'director_name' => 'Nama Pimpinan', 'legal_entity_name' => 'Nama Badan Hukum',
    'established_date' => 'Tanggal Berdiri', 'province_id' => 'Provinsi', 'regency_id' => 'Kabupaten/Kota',
    'kitchen_address' => 'Alamat Dapur', 'kitchen_name' => 'Nama Dapur', 'beneficiary_count' => 'Jumlah Penerima Manfaat',
    'target_amount' => 'Target Pendanaan',
    'land_status' => 'Status Kepemilikan Lahan'
];

foreach ($required_fields as $field => $label) {
    if (!isset($data[$field]) || empty(trim($data[$field]))) {
        http_response_code(400);
        echo json_encode(['message' => "Field '{$label}' wajib diisi."]);
        exit();
    }
}

$conn->begin_transaction();

try {
    // 5. Simpan data aplikasi utama
    $sql = "INSERT INTO funding_applications (
                organization_id, pic_full_name, pic_email, pic_whatsapp, 
                legal_entity_type, director_name, legal_entity_name, established_date, 
                province_id, regency_id, kitchen_address, latitude, longitude, 
                kitchen_name, beneficiary_count, target_amount, 
                bank_account_details, mbg_status, land_status, vendor_status, 
                profit_sharing_type, profit_sharing_value, public_description, 
                payout_frequency, platform_commission_rate, management_type, 
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sudah Diterima')";
    
    $stmt = $conn->prepare($sql);

    $lat = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : null;
    $lng = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : null;
    $bank_details = json_encode([
        'bank_name' => $data['bank_name'] ?? null,
        'account_number' => $data['account_number'] ?? null,
        'account_name' => $data['account_name'] ?? null
    ]);
    
    // 6. PERBAIKAN: String tipe bind_param diperbaiki
    $stmt->bind_param(
        "issssssssssddsidssssssssss", // <-- String yang benar (sebelumnya '...ddsis...')
        $org_id,
        $data['pic_full_name'], $data['pic_email'], $data['pic_whatsapp'], 
        $data['legal_entity_type'], $data['director_name'], $data['legal_entity_name'],
        $data['established_date'], $data['province_id'], $data['regency_id'],
        $data['kitchen_address'], $lat, $lng,
        $data['kitchen_name'], $data['beneficiary_count'], $data['target_amount'],
        $bank_details, $data['mbg_status'], $data['land_status'], $data['vendor_status'],
        $data['profit_sharing_type'], $data['profit_sharing_value'], $data['public_description'],
        $data['payout_frequency'], $data['platform_commission_rate'], $data['management_type']
    );

    if (!$stmt->execute()) {
         throw new Exception('Gagal menyimpan data pengajuan utama: ' . $stmt->error);
    }
    
    $application_id = $conn->insert_id;
    $stmt->close();
    
    // 7. Proses unggahan file
    if (isset($_FILES['legalitas'])) {
        $files = $_FILES['legalitas'];
        $upload_dir = __DIR__ . '/../uploads/funding_documents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $doc_sql = "INSERT INTO funding_application_documents (application_id, file_path, original_name) VALUES (?, ?, ?)";
        $doc_stmt = $conn->prepare($doc_sql);

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $files['tmp_name'][$i];
                $original_name = basename($files['name'][$i]);
                $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                $safe_name = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
                $new_filename = "fund_app_{$application_id}_" . time() . "_{$i}_{$safe_name}.{$file_ext}";
                
                if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                    $file_path = "/uploads/funding_documents/" . $new_filename;
                    $doc_stmt->bind_param("iss", $application_id, $file_path, $original_name);
                    $doc_stmt->execute();
                } else {
                    throw new Exception("Gagal memindahkan file '{$original_name}'.");
                }
            }
        }
        $doc_stmt->close();
    }
    
    // 8. Kirim notifikasi ke Super Admin
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn, 
                $admin['organization_id'], $admin['id'],
                "Pengajuan Pendanaan Baru Diterima",
                "Pengajuan pendanaan dari '{$data['legal_entity_name']}' telah diterima dan siap diverifikasi.",
                "/app/admin/funding-applications/{$application_id}"
            );
        }
    }
    
    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Pengajuan pendanaan Anda berhasil dikirim.', 'application_id' => $application_id]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>