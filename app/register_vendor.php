<?php
// File: app/register_vendor.php
// Penjelasan: Menangani pendaftaran Vendor baru, termasuk membuat slug unik
// dan menyimpan data lokasi dari peta. Menghapus 'is_hipmi_member'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_engine.php';
require_once __DIR__ . '/slug_helper.php';

$data = json_decode(file_get_contents("php://input"));

// 1. Validasi input (is_hipmi_member dihapus)
$required_fields = [
    'org_name' => 'Nama Usaha',
    'vendor_category_id' => 'Kategori Usaha',
    'province_id' => 'Provinsi',
    'regency_id' => 'Kabupaten/Kota',
    'vendor_address' => 'Alamat Lengkap',
    'pic_name' => 'Nama PIC',
    'pic_email' => 'Email PIC',
    'pic_whatsapp' => 'WhatsApp PIC',
    'username' => 'Username',
    'password' => 'Password'
];
foreach ($required_fields as $field => $label) {
    if (!isset($data->$field) || empty(trim($data->$field))) {
        http_response_code(400);
        echo json_encode(['message' => "Field '{$label}' wajib diisi."]);
        exit();
    }
}

$conn->begin_transaction();

try {
    // 2. Cek duplikasi username atau email
    $checkSql = "SELECT username, email FROM users WHERE username = ? OR email = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ss", $data->username, $data->pic_email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        throw new Exception("Username atau Email PIC sudah terdaftar.", 409);
    }
    $checkStmt->close();

    // 3. Ambil nama provinsi untuk disimpan
    $provinceName = '';
    $provSql = "SELECT name FROM provinces WHERE id = ? LIMIT 1";
    $provStmt = $conn->prepare($provSql);
    $provStmt->bind_param("s", $data->province_id);
    $provStmt->execute();
    $provResult = $provStmt->get_result();
    if ($provRow = $provResult->fetch_assoc()) {
        $provinceName = $provRow['name'];
    }
    $provStmt->close();

    // 4. Vendor tidak memiliki sistem langganan, set 'active' dan 'until' yang jauh
    $subscription_status = 'active';
    $subscription_until = date('Y-m-d', strtotime("+100 years"));
    
    // 5. Buat slug unik
    $slug = generate_unique_slug($data->org_name, $conn);

    // 6. Simpan data organisasi vendor (is_hipmi_member dihapus)
    $orgSql = "INSERT INTO organizations (name, registration_type, is_active, vendor_category_id, province_id, regency_id, province, vendor_address, latitude, longitude, pic_name, pic_whatsapp, subscription_status, subscription_until, slug) VALUES (?, 'Vendor', 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $orgStmt = $conn->prepare($orgSql);
    
    $lat = isset($data->latitude) ? (float)$data->latitude : null;
    $lng = isset($data->longitude) ? (float)$data->longitude : null;

    $orgStmt->bind_param(
        "sissssddsssss",
        $data->org_name,
        $data->vendor_category_id,
        $data->province_id,
        $data->regency_id,
        $provinceName,
        $data->vendor_address,
        $lat,
        $lng,
        $data->pic_name,
        $data->pic_whatsapp,
        $subscription_status,
        $subscription_until,
        $slug
    );
    $orgStmt->execute();
    $org_id = $conn->insert_id;
    if ($org_id == 0) throw new Exception("Gagal menyimpan data organisasi vendor.");
    $orgStmt->close();

    // 7. Simpan data pengguna (PIC vendor)
    $hashed_password = password_hash($data->password, PASSWORD_BCRYPT);
    $userSql = "INSERT INTO users (organization_id, full_name, username, email, phone_number, password, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 5, 0)";
    $userStmt = $conn->prepare($userSql);
    $userStmt->bind_param("isssss", $org_id, $data->pic_name, $data->username, $data->pic_email, $data->pic_whatsapp, $hashed_password);
    $userStmt->execute();
    $user_id = $conn->insert_id;
    if ($user_id == 0) throw new Exception("Gagal membuat akun user untuk vendor.");
    $userStmt->close();
    
    // 8. Kirim notifikasi ke Super Admin
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn, 
                $admin['organization_id'],
                $admin['id'],
                "Vendor Baru Mendaftar", 
                "Vendor baru '{$data->org_name}' menunggu persetujuan Anda.", 
                "/app/admin/pending-registrations"
            );
        }
    }

    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Pendaftaran vendor berhasil! Akun Anda akan aktif setelah disetujui oleh administrator.']);

} catch (Throwable $e) {
    $conn->rollback();
    $error_code = $e->getCode() > 0 && $e->getCode() < 599 ? $e->getCode() : 500;
    http_response_code($error_code);
    echo json_encode(['message' => 'Terjadi kesalahan saat pendaftaran.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>