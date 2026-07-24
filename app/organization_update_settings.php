<?php
// File: app/organization_update_settings.php
// PERBAIKAN: Mengganti !empty() dengan is_numeric() untuk koordinat 0.
// PERBAIKAN: Menambahkan 'kitchen_name' ke dalam logika update/insert.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/slug_helper.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Hanya Administrator (role_id = 7) atau Yayasan (role_id = 4) yang bisa mengakses
if ($userData['role_id'] != 7 && $userData['role_id'] != 4) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = $_POST;

// --- PERBAIKAN: Validasi kitchen_name ---
if (!isset($data['kitchen_address']) || !isset($data['kitchen_name']) || empty(trim($data['kitchen_name']))) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama Dapur dan Alamat Dapur wajib diisi.']);
    exit();
}

$conn->begin_transaction();

try {
    // Ambil data path foto profil yang ada saat ini
    $currentSettingsSql = "SELECT profile_picture, name, director_name, pic_name, pic_whatsapp FROM organizations WHERE id = ?";
    $currentSettingsStmt = $conn->prepare($currentSettingsSql);
    $currentSettingsStmt->bind_param("i", $org_id);
    $currentSettingsStmt->execute();
    $currentSettings = $currentSettingsStmt->get_result()->fetch_assoc();
    $currentSettingsStmt->close();
    
    $new_profile_picture_path = $currentSettings['profile_picture'] ?? null; 

    // 1. Data Pengaturan Dapur
    $kitchen_name = $data['kitchen_name'];
    $kitchen_address = $data['kitchen_address'];
    
    $latitude = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : null;
    $longitude = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : null;

    // 2. Data Pengaturan Profil Publik & Yayasan
    $slug = $data['slug'] ?? '';
    $public_description = $data['public_description'] ?? null;
    
    $name = $data['name'] ?? $currentSettings['name'];
    $director_name = $data['director_name'] ?? ($currentSettings['director_name'] ?? null);
    $pic_name = $data['pic_name'] ?? ($currentSettings['pic_name'] ?? null);
    $pic_whatsapp = $data['pic_whatsapp'] ?? ($currentSettings['pic_whatsapp'] ?? null);

    // 3. Validasi Keunikan Slug
    if (!empty($slug)) {
        $checkSlugSql = "SELECT id FROM organizations WHERE slug = ? AND id != ?";
        $checkStmt = $conn->prepare($checkSlugSql);
        $checkStmt->bind_param("si", $slug, $org_id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception("Link profil (slug) '{$slug}' sudah digunakan oleh dapur lain.", 409);
        }
        $checkStmt->close();
    } else {
        $slug = generate_unique_slug($kitchen_name, $conn);
    }
    
    // 4. Proses Upload Foto Profil jika ada
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        
        $target_dir = __DIR__ . "/../uploads/profiles/";
        if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true)) {
            throw new Exception("Gagal membuat direktori untuk unggahan file.");
        }

        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $new_filename = "profile_{$org_id}_" . time() . "." . $file_ext;
        $target_file = $target_dir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            $new_profile_picture_path = "/uploads/profiles/" . $new_filename;
        } else {
            throw new Exception("Gagal memindahkan file yang diunggah.");
        }
    }

    // 5. Update tabel organizations
    $orgSql = "UPDATE organizations SET slug = ?, public_description = ?, profile_picture = ?, name = ?, director_name = ?, pic_name = ?, pic_whatsapp = ? WHERE id = ?";
    $orgStmt = $conn->prepare($orgSql);
    $orgStmt->bind_param("sssssssi", $slug, $public_description, $new_profile_picture_path, $name, $director_name, $pic_name, $pic_whatsapp, $org_id);
    $orgStmt->execute();
    $orgStmt->close();

    // 6. Update atau Insert data dapur utama (logika UPSERT)
    $checkSql = "SELECT id FROM distribution_points WHERE organization_id = ? AND is_main_kitchen = 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $org_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $checkStmt->close();

    if ($result->num_rows > 0) {
        // --- PERBAIKAN: Tambahkan 'name = ?' ke UPDATE ---
        $pointSql = "UPDATE distribution_points SET name = ?, address = ?, latitude = ?, longitude = ? WHERE organization_id = ? AND is_main_kitchen = 1";
        $pointStmt = $conn->prepare($pointSql);
        $pointStmt->bind_param("ssddi", $kitchen_name, $kitchen_address, $latitude, $longitude, $org_id);
    } else {
        // --- PERBAIKAN: Gunakan '$kitchen_name' saat INSERT ---
        $pointSql = "INSERT INTO distribution_points (organization_id, name, address, latitude, longitude, is_main_kitchen) VALUES (?, ?, ?, ?, ?, 1)";
        $pointStmt = $conn->prepare($pointSql);
        $pointStmt->bind_param("issdd", $org_id, $kitchen_name, $kitchen_address, $latitude, $longitude);
    }
    $pointStmt->execute();
    $pointStmt->close();
    
    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Pengaturan berhasil diperbarui.']);

} catch (Exception $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Gagal memperbarui pengaturan.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>