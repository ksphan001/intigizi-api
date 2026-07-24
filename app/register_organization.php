<?php
// File: app/register_organization.php
// Penjelasan: Backend pendaftaran Mitra Dapur.
// PERBAIKAN: Mengoreksi jumlah karakter bind_param dari "ssssssisssdd" menjadi "ssssssissssdd" (13 karakter untuk 13 variabel).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_engine.php';
require_once __DIR__ . '/slug_helper.php';

// Menerima input JSON dari frontend React
$data = json_decode(file_get_contents("php://input"), true);

// 1. Validasi semua input yang diperlukan
$registration_type = $data['registration_type'] ?? 'Mitra Dapur';
$required_fields = ['org_name', 'pic_name', 'pic_email', 'pic_whatsapp', 'username', 'password', 'kitchen_name', 'kitchen_address', 'province_id', 'regency_id'];
if ($registration_type === 'Mitra Dapur' || $registration_type === 'Yayasan / Pengelola SPPG') {
    $required_fields[] = 'director_name';
}

foreach ($required_fields as $field) {
    if (!isset($data[$field]) || (is_string($data[$field]) && empty(trim($data[$field])))) {
        http_response_code(400);
        echo json_encode(['message' => "Field '{$field}' wajib diisi."]);
        exit();
    }
}

$director_name = $data['director_name'] ?? null;
$lat = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : null;
$lng = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : null;


$conn->begin_transaction();

try {
    // 2. Cek duplikasi username atau email
    $checkSql = "SELECT username, email FROM users WHERE username = ? OR email = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ss", $data['username'], $data['pic_email']);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows > 0) {
        $existing = $result->fetch_assoc();
        if ($existing['username'] === $data['username']) {
            throw new Exception("Username '{$data['username']}' sudah digunakan.", 409);
        }
        if ($existing['email'] === $data['pic_email']) {
            throw new Exception("Email PIC '{$data['pic_email']}' sudah terdaftar.", 409);
        }
    }
    $checkStmt->close();

    // 3. Ambil pengaturan free trial dari database
    $trialDaysSql = "SELECT setting_value FROM subscription_settings WHERE setting_key = 'free_trial_days' LIMIT 1";
    $trialDaysResult = $conn->query($trialDaysSql);
    $free_trial_days = $trialDaysResult->fetch_assoc()['setting_value'] ?? 0;
    
    $trial_until = date('Y-m-d', strtotime("+$free_trial_days days"));
    $is_active = 0; // Pendaftaran baru selalu tidak aktif, menunggu persetujuan admin

    // 4. Buat slug yang unik dari NAMA DAPUR
    $slug = generate_unique_slug($data['kitchen_name'], $conn);

    // 5. Simpan data organisasi utama
    $orgSql = "INSERT INTO organizations (name, organization_type, director_name, pic_name, pic_whatsapp, registration_type, is_active, province_id, regency_id, subscription_status, subscription_until, slug, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'trial', ?, ?, ?, ?)";
    $orgStmt = $conn->prepare($orgSql);
    $org_type = $data['org_type'] ?? 'Yayasan';
    
    // PERBAIKAN UTAMA DI SINI: "ssssssissssdd" (Total 13 karakter tipe data)
    // Urutan: org_name(s), org_type(s), director_name(s), pic_name(s), pic_whatsapp(s), registration_type(s), is_active(i), province_id(s), regency_id(s), trial_until(s), slug(s), lat(d), lng(d)
    $orgStmt->bind_param("ssssssissssdd", $data['org_name'], $org_type, $director_name, $data['pic_name'], $data['pic_whatsapp'], $registration_type, $is_active, $data['province_id'], $data['regency_id'], $trial_until, $slug, $lat, $lng);
    
    $orgStmt->execute();
    $org_id = $conn->insert_id;
    if ($org_id == 0) throw new Exception("Gagal menyimpan data organisasi.");
    $orgStmt->close();

    // 6. Buat akun pengguna (PIC/Yayasan) untuk organisasi tersebut
    $hashed_password = password_hash($data['password'], PASSWORD_BCRYPT);
    $userSql = "INSERT INTO users (organization_id, full_name, username, email, phone_number, password, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 4, ?)";
    $userStmt = $conn->prepare($userSql);
    $userStmt->bind_param("isssssi", $org_id, $data['pic_name'], $data['username'], $data['pic_email'], $data['pic_whatsapp'], $hashed_password, $is_active);
    $userStmt->execute();
    $userStmt->close();

    // 7. Simpan detail dapur utama ke tabel distribution_points
    $kitchenSql = "INSERT INTO distribution_points (organization_id, name, address, latitude, longitude, is_main_kitchen) VALUES (?, ?, ?, ?, ?, 1)";
    $kitchenStmt = $conn->prepare($kitchenSql);
    $kitchenStmt->bind_param("issdd", $org_id, $data['kitchen_name'], $data['kitchen_address'], $lat, $lng);
    $kitchenStmt->execute();
    $kitchenStmt->close();
    
    // 8. Kirim email notifikasi ke pendaftar
    $title_to_registrant = "Pendaftaran Berhasil!";
    $msg_to_registrant = "Pendaftaran Anda untuk '{$data['org_name']}' telah berhasil. Akun Anda akan segera ditinjau oleh administrator untuk aktivasi.";
    send_direct_email($data['pic_email'], $data['pic_name'], $title_to_registrant, $msg_to_registrant);

    // 9. Kirim notifikasi ke semua Super Admin
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn, 
                $admin['organization_id'],
                $admin['id'],
                "Pendaftar Baru", 
                "Pendaftar baru '{$data['org_name']}' menunggu persetujuan Anda.", 
                "/app/admin/pending-registrations"
            );
        }
    }

    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Pendaftaran berhasil! Akun Anda akan aktif setelah disetujui oleh admin.']);

} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        if (strpos($e->getMessage(), 'username') !== false) {
            echo json_encode(['message' => "Username '{$data['username']}' sudah digunakan."]);
        } elseif (strpos($e->getMessage(), 'email') !== false) {
            echo json_encode(['message' => "Email PIC '{$data['pic_email']}' sudah terdaftar."]);
        } else {
            echo json_encode(['message' => 'Terjadi error duplikasi data.']);
        }
    } else {
        $error_code = $e->getCode() > 0 && $e->getCode() < 599 ? $e->getCode() : 500;
        http_response_code($error_code);
        echo json_encode(['message' => $e->getMessage()]);
    }
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>