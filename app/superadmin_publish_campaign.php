<?php
// File: app/superadmin_publish_campaign.php
// PERBAIKAN BESAR:
// Skrip ini sekarang menangani logika "UPSERT" (Update atau Insert).
// 1. Cek apakah kampanye sudah ada.
// 2. Jika sudah ada (UPDATE), perbarui data yang ada.
// 3. Jika belum ada (INSERT), buat kampanye baru dan update status pengajuan.
// Ini mencegah pembuatan proyek duplikat.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

// Data berasal dari FormData, jadi kita baca dari $_POST
$data = $_POST;

// 1. Validasi input
$application_id = isset($data['application_id']) ? (int)$data['application_id'] : 0;
if ($application_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Pengajuan wajib diisi.']);
    exit();
}

$conn->begin_transaction();

try {
    // 2. Ambil data asli dari pengajuan
    $sql_app = "SELECT * FROM funding_applications WHERE id = ? LIMIT 1";
    $stmt_app = $conn->prepare($sql_app);
    $stmt_app->bind_param("i", $application_id);
    $stmt_app->execute();
    $application = $stmt_app->get_result()->fetch_assoc();
    $stmt_app->close();

    if (!$application) {
        throw new Exception("Data pengajuan tidak ditemukan.", 404);
    }

    // --- LOGIKA BARU: Cek apakah kampanye sudah ada ---
    $existing_campaign_id = null;
    $sql_check = "SELECT id FROM funding_campaigns WHERE funding_application_id = ? LIMIT 1";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $application_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    if ($row = $result_check->fetch_assoc()) {
        $existing_campaign_id = $row['id'];
    }
    $stmt_check->close();
    // --- AKHIR LOGIKA BARU ---

    // 3. Proses upload Cover Image jika ada file baru
    $cover_image_path = null;
    $new_image_uploaded = false;
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['cover_image'];
        $target_dir = __DIR__ . "/../uploads/campaign_covers/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $new_filename = "cover_{$application_id}_" . time() . "." . $file_ext;

        if (move_uploaded_file($file['tmp_name'], $target_dir . $new_filename)) {
            $cover_image_path = "/uploads/campaign_covers/" . $new_filename;
            $new_image_uploaded = true;
        } else {
            throw new Exception("Gagal mengunggah gambar cover.");
        }
    }

    // 4. Siapkan data untuk dimasukkan/diperbarui
    $title = $data['title_override'] ?? $application['kitchen_name'];
    $description = $data['description_override'] ?? $application['public_description'];
    $target_amount = (float)($data['target_amount'] ?? 0);
    $lot_price = (float)($data['lot_price'] ?? 0);
    $profit_share = (float)($data['profit_sharing_value'] ?? $application['profit_sharing_value']);

    $terms_override = json_encode([
        'profit_sharing_type' => $data['profit_sharing_type'] ?? $application['profit_sharing_type'],
        'profit_sharing_value' => $profit_share,
        'payout_frequency' => $data['payout_frequency'] ?? $application['payout_frequency'],
        'management_type' => $data['management_type'] ?? $application['management_type'],
        'platform_commission_rate' => (float)($data['platform_commission_rate'] ?? $application['platform_commission_rate'])
    ]);

    $user_sql = "SELECT id FROM users WHERE organization_id = ? AND role_id = 10 LIMIT 1";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $application['organization_id']);
    $user_stmt->execute();
    $user_pic_id = $user_stmt->get_result()->fetch_assoc()['id'];
    $user_stmt->close();
    if (!$user_pic_id) throw new Exception("User PIC Calon Mitra tidak ditemukan.");

    $distribution_points_count = 1; // Asumsi 1 titik dapur
    $message = '';

    // --- LOGIKA BARU: Tentukan UPDATE atau INSERT ---
    if ($existing_campaign_id) {
        // --- JALUR UPDATE (Kampanye sudah ada) ---
        $sql_update = "UPDATE funding_campaigns SET 
                            title = ?, description = ?, target_amount = ?, lot_price = ?, 
                            profit_share = ?, location_address = ?, latitude = ?, longitude = ?, 
                            beneficiaries_count = ?, distribution_points_count = ?, terms_override = ?";

        $types = "ssdddsddiis";
        $params = [
            $title,
            $description,
            $target_amount,
            $lot_price,
            $profit_share,
            $application['kitchen_address'],
            $application['latitude'],
            $application['longitude'],
            $application['beneficiary_count'],
            $distribution_points_count,
            $terms_override
        ];

        // Hanya perbarui gambar jika ada file baru yang diunggah
        if ($new_image_uploaded) {
            $sql_update .= ", cover_image_path = ?";
            $types .= "s";
            $params[] = $cover_image_path;
        }

        $sql_update .= " WHERE id = ?";
        $types .= "i";
        $params[] = $existing_campaign_id;

        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param($types, ...$params);

        if (!$stmt_update->execute()) {
            throw new Exception("Gagal memperbarui kampanye: " . $stmt_update->error);
        }
        $stmt_update->close();
        $message = 'Kampanye berhasil diperbarui.';
    } else {
        // --- JALUR INSERT (Kampanye baru) ---
        $sql_insert = "INSERT INTO funding_campaigns (
                            organization_id, user_id, funding_application_id, title, 
                            description, cover_image_path,
                            target_amount, lot_price, profit_share, 
                            location_address, latitude, longitude,
                            beneficiaries_count, distribution_points_count,
                            terms_override, status
                       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param(
            "iiisssdddsddiis",
            $application['organization_id'],
            $user_pic_id,
            $application_id,
            $title,
            $description,
            $cover_image_path,
            $target_amount,
            $lot_price,
            $profit_share,
            $application['kitchen_address'],
            $application['latitude'],
            $application['longitude'],
            $application['beneficiary_count'],
            $distribution_points_count,
            $terms_override
        );

        if (!$stmt_insert->execute()) {
            throw new Exception("Gagal menerbitkan kampanye: " . $stmt_insert->error);
        }
        $stmt_insert->close();

        // 6. UPDATE status di `funding_applications` (Hanya saat INSERT baru)
        $sql_update_app = "UPDATE funding_applications SET status = 'Diterbitkan' WHERE id = ?";
        $stmt_update_app = $conn->prepare($sql_update_app);
        $stmt_update_app->bind_param("i", $application_id);
        $stmt_update_app->execute();
        $stmt_update_app->close();

        // 7. Kirim notifikasi ke Calon Mitra (Hanya saat INSERT baru)
        send_notification(
            $conn,
            $application['organization_id'],
            $user_pic_id,
            "Pengajuan Anda Diterbitkan!",
            "Selamat! Pengajuan Anda '{$title}' telah disetujui dan diterbitkan di halaman pendanaan.",
            "/app/funding/dashboard"
        );

        $message = 'Kampanye berhasil diterbitkan.';
    }
    // --- AKHIR LOGIKA BARU ---

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => $message]);
} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
