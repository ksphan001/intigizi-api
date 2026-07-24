<?php
// File: app/yayasan_get_sppgs.php
// Penjelasan: Mengambil daftar SPPG/Dapur di bawah naungan Yayasan induk.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Yayasan (Role ID 4) yang diizinkan mengakses
if (!isset($userData['role_id']) || (int)$userData['role_id'] !== 4) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Fitur ini hanya untuk Yayasan.']);
    exit();
}

$org_id = (int)$userData['org_id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Ambil daftar organisasi anak (SPPG) beserta data PIC, login, dan alamat
        $sql = "SELECT 
                    o.id, 
                    o.name, 
                    o.slug, 
                    o.director_name, 
                    o.pic_name, 
                    o.pic_whatsapp, 
                    o.province_id, 
                    o.regency_id, 
                    o.is_active, 
                    o.created_at,
                    dp.address,
                    o.latitude,
                    o.longitude,
                    u.email as pic_email,
                    u.username
                FROM organizations o
                LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
                LEFT JOIN users u ON o.id = u.organization_id AND u.role_id = 7
                WHERE o.parent_organization_id = ? 
                ORDER BY o.name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $sppgs = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode($sppgs);

    } elseif ($method === 'POST') {
        // Untuk Yayasan menambahkan/mendaftarkan SPPG baru di bawah naungan mereka secara mandiri
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Validasi input wajib
        $required_fields = ['name', 'director_name', 'pic_name', 'pic_email', 'pic_whatsapp', 'username', 'password', 'address', 'province_id', 'regency_id'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                throw new Exception("Field '{$field}' wajib diisi.", 400);
            }
        }

        $lat = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : null;
        $lng = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : null;

        $conn->begin_transaction();
        
        // 1. Cek duplikasi username atau email di users
        $checkSql = "SELECT username, email FROM users WHERE username = ? OR email = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("ss", $data['username'], $data['pic_email']);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $checkStmt->close();
            throw new Exception("Username atau Email PIC sudah terdaftar di sistem.", 409);
        }
        $checkStmt->close();

        // 2. Buat slug unik untuk SPPG baru
        require_once __DIR__ . '/slug_helper.php';
        $slug = generate_unique_slug($data['name'], $conn);

        // 3. Simpan organisasi anak
        $trial_until = date('Y-m-d', strtotime("+30 days")); // default trial 30 hari
        $is_active = 1; // Otomatis aktif karena didaftarkan oleh Yayasan resmi yang sudah diverifikasi

        $orgSql = "INSERT INTO organizations (name, organization_type, director_name, pic_name, pic_whatsapp, registration_type, is_active, province_id, regency_id, subscription_status, subscription_until, slug, latitude, longitude, parent_organization_id) VALUES (?, 'Dapur/SPPG', ?, ?, ?, 'Mitra Dapur', ?, ?, ?, 'trial', ?, ?, ?, ?, ?)";
        $orgStmt = $conn->prepare($orgSql);
        
        $orgStmt->bind_param("ssssissssddi", 
            $data['name'], 
            $data['director_name'], 
            $data['pic_name'], 
            $data['pic_whatsapp'], 
            $is_active, 
            $data['province_id'], 
            $data['regency_id'], 
            $trial_until, 
            $slug, 
            $lat, 
            $lng,
            $org_id
        );
        $orgStmt->execute();
        $new_org_id = $conn->insert_id;
        $orgStmt->close();

        // 4. Buat akun Administrator (role 7) untuk SPPG tersebut
        $hashed_password = password_hash($data['password'], PASSWORD_BCRYPT);
        $userSql = "INSERT INTO users (organization_id, full_name, username, email, phone_number, password, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 7, 1)";
        $userStmt = $conn->prepare($userSql);
        $userStmt->bind_param("isssss", $new_org_id, $data['pic_name'], $data['username'], $data['pic_email'], $data['pic_whatsapp'], $hashed_password);
        $userStmt->execute();
        $userStmt->close();

        // 4b. Buat akun-akun staff default secara kolektif (Ahli Gizi, Kepala Dapur, Akuntan, Distribusi)
        $staff_roles = [
            1 => ['title' => 'Ahli Gizi', 'prefix' => 'gizi'],
            2 => ['title' => 'Kepala Dapur', 'prefix' => 'chef'],
            3 => ['title' => 'Akuntan Dapur', 'prefix' => 'akuntan'],
            6 => ['title' => 'Staff Distribusi', 'prefix' => 'kurir']
        ];
        
        $staffSql = "INSERT INTO users (organization_id, full_name, username, email, phone_number, password, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
        $staffStmt = $conn->prepare($staffSql);
        
        foreach ($staff_roles as $roleId => $roleInfo) {
            $staffUsername = $roleInfo['prefix'] . '_' . $slug;
            $staffEmail = $roleInfo['prefix'] . '_' . $slug . '@intigizi.com';
            $staffName = $roleInfo['title'] . ' ' . $data['name'];
            $staffPhone = $data['pic_whatsapp'];
            
            $staffStmt->bind_param("isssssi", $new_org_id, $staffName, $staffUsername, $staffEmail, $staffPhone, $hashed_password, $roleId);
            $staffStmt->execute();
        }
        $staffStmt->close();

        // 5. Simpan detail dapur utama ke tabel distribution_points
        $kitchenSql = "INSERT INTO distribution_points (organization_id, name, address, latitude, longitude, is_main_kitchen) VALUES (?, ?, ?, ?, ?, 1)";
        $kitchenStmt = $conn->prepare($kitchenSql);
        $kitchenStmt->bind_param("issdd", $new_org_id, $data['name'], $data['address'], $lat, $lng);
        $kitchenStmt->execute();
        $kitchenStmt->close();

        $conn->commit();
        echo json_encode(['message' => 'Unit SPPG baru berhasil didaftarkan.', 'id' => $new_org_id]);

    } elseif ($method === 'PUT') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $sppg_id = (int)($data['id'] ?? 0);
        if ($sppg_id <= 0) {
            throw new Exception("ID SPPG tidak valid.", 400);
        }

        // Pastikan SPPG ini milik yayasan yang login
        $checkOwnerSql = "SELECT parent_organization_id FROM organizations WHERE id = ?";
        $checkOwnerStmt = $conn->prepare($checkOwnerSql);
        $checkOwnerStmt->bind_param("i", $sppg_id);
        $checkOwnerStmt->execute();
        $ownerResult = $checkOwnerStmt->get_result()->fetch_assoc();
        $checkOwnerStmt->close();

        if (!$ownerResult || (int)$ownerResult['parent_organization_id'] !== $org_id) {
            throw new Exception("Akses ditolak. Anda tidak memiliki wewenang untuk mengubah unit ini.", 403);
        }

        // Validasi input wajib (password dikecualikan karena opsional saat edit)
        $required_fields = ['name', 'director_name', 'pic_name', 'pic_email', 'pic_whatsapp', 'username', 'address', 'province_id', 'regency_id'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                throw new Exception("Field '{$field}' wajib diisi.", 400);
            }
        }

        $conn->begin_transaction();

        // 1. Dapatkan user admin SPPG saat ini
        $userSql = "SELECT id FROM users WHERE organization_id = ? AND role_id = 7 LIMIT 1";
        $userStmt = $conn->prepare($userSql);
        $userStmt->bind_param("i", $sppg_id);
        $userStmt->execute();
        $userResult = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$userResult) {
            throw new Exception("User admin untuk SPPG ini tidak ditemukan.", 404);
        }
        $user_id = (int)$userResult['id'];

        // 2. Cek duplikasi username atau email (kecuali user admin ini sendiri)
        $dupSql = "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?";
        $dupStmt = $conn->prepare($dupSql);
        $dupStmt->bind_param("ssi", $data['username'], $data['pic_email'], $user_id);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            $dupStmt->close();
            throw new Exception("Username atau Email PIC sudah digunakan oleh pengguna lain.", 409);
        }
        $dupStmt->close();

        $lat = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : null;
        $lng = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : null;

        // 3. Update organisasi anak
        $orgUpdateSql = "UPDATE organizations SET name = ?, director_name = ?, pic_name = ?, pic_whatsapp = ?, province_id = ?, regency_id = ?, latitude = ?, longitude = ? WHERE id = ?";
        $orgUpdateStmt = $conn->prepare($orgUpdateSql);
        $orgUpdateStmt->bind_param("ssssssddi", 
            $data['name'], 
            $data['director_name'], 
            $data['pic_name'], 
            $data['pic_whatsapp'], 
            $data['province_id'], 
            $data['regency_id'], 
            $lat,
            $lng,
            $sppg_id
        );
        $orgUpdateStmt->execute();
        $orgUpdateStmt->close();

        // 4. Update user admin SPPG
        if (!empty($data['password'])) {
            $hashed_password = password_hash($data['password'], PASSWORD_BCRYPT);
            $userUpdateSql = "UPDATE users SET full_name = ?, username = ?, email = ?, phone_number = ?, password = ? WHERE id = ?";
            $userUpdateStmt = $conn->prepare($userUpdateSql);
            $userUpdateStmt->bind_param("sssssi", $data['pic_name'], $data['username'], $data['pic_email'], $data['pic_whatsapp'], $hashed_password, $user_id);
        } else {
            $userUpdateSql = "UPDATE users SET full_name = ?, username = ?, email = ?, phone_number = ? WHERE id = ?";
            $userUpdateStmt = $conn->prepare($userUpdateSql);
            $userUpdateStmt->bind_param("ssssi", $data['pic_name'], $data['username'], $data['pic_email'], $data['pic_whatsapp'], $user_id);
        }
        $userUpdateStmt->execute();
        $userUpdateStmt->close();

        // 5. Update detail dapur utama di distribution_points
        $kitchenUpdateSql = "UPDATE distribution_points SET name = ?, address = ?, latitude = ?, longitude = ? WHERE organization_id = ? AND is_main_kitchen = 1";
        $kitchenUpdateStmt = $conn->prepare($kitchenUpdateSql);
        $kitchenUpdateStmt->bind_param("ssddi", $data['name'], $data['address'], $lat, $lng, $sppg_id);
        $kitchenUpdateStmt->execute();
        $kitchenUpdateStmt->close();

        $conn->commit();
        echo json_encode(['message' => 'Unit SPPG berhasil diperbarui.']);
    } else {
        http_response_code(405);
        echo json_encode(['message' => 'Metode tidak diizinkan.']);
    }
} catch (Throwable $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>
