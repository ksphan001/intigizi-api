<?php
// File: app/funding_campaign_create.php
// Penjelasan: API endpoint diperbarui untuk menangani `multipart/form-data`
// yang mencakup data formulir (dari $_POST) dan unggahan file (dari $_FILES).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];
$user_role = (int)$userData['role_id'];

// Keamanan: Hanya Administrator (7) atau Ketua Yayasan (4) yang bisa mengajukan.
if ($user_role !== 7 && $user_role !== 4) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Anda tidak memiliki izin untuk mengajukan pendanaan.']);
    exit();
}

// Karena kita menggunakan multipart/form-data, data ada di $_POST
$data = $_POST;

// Validasi input dasar
$required_fields = ['title', 'description', 'target_amount', 'lot_price', 'profit_share', 'location_address', 'beneficiaries_count', 'distribution_points_count'];
foreach ($required_fields as $field) {
    if (!isset($data[$field]) || empty(trim($data[$field]))) {
        http_response_code(400);
        echo json_encode(['message' => "Field '{$field}' wajib diisi."]);
        exit();
    }
}

$conn->begin_transaction();

try {
    // Siapkan data untuk dimasukkan ke database
    $title = $conn->real_escape_string($data['title']);
    $description = $conn->real_escape_string($data['description']);
    $target_amount = (float)$data['target_amount'];
    $lot_price = (float)$data['lot_price'];
    $profit_share = (float)$data['profit_share'];
    $location_address = $conn->real_escape_string($data['location_address']);
    $latitude = isset($data['latitude']) ? (float)$data['latitude'] : null;
    $longitude = isset($data['longitude']) ? (float)$data['longitude'] : null;
    $beneficiaries_count = (int)$data['beneficiaries_count'];
    $distribution_points_count = (int)$data['distribution_points_count'];

    // Query untuk memasukkan data kampanye baru
    $sql = "INSERT INTO funding_campaigns (organization_id, user_id, title, description, target_amount, lot_price, profit_share, location_address, latitude, longitude, beneficiaries_count, distribution_points_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisssddssddi", $org_id, $user_id, $title, $description, $target_amount, $lot_price, $profit_share, $location_address, $latitude, $longitude, $beneficiaries_count, $distribution_points_count);

    if ($stmt->execute()) {
        $campaign_id = $conn->insert_id;
        $stmt->close();
        
        // --- LOGIKA BARU: Proses Unggahan Dokumen ---
        if (isset($_FILES['documents'])) {
            $upload_dir = __DIR__ . '/../uploads/campaign_documents/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $doc_sql = "INSERT INTO campaign_documents (campaign_id, document_name, file_path) VALUES (?, ?, ?)";
            $doc_stmt = $conn->prepare($doc_sql);

            foreach ($_FILES['documents']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['documents']['error'][$key] === UPLOAD_ERR_OK) {
                    $original_name = basename($_FILES['documents']['name'][$key]);
                    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    $safe_name = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
                    $new_filename = "campaign_{$campaign_id}_{$key}_{$safe_name}.{$file_ext}";
                    
                    if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                        $file_path = "/uploads/campaign_documents/" . $new_filename;
                        $doc_stmt->bind_param("iss", $campaign_id, $original_name, $file_path);
                        $doc_stmt->execute();
                    }
                }
            }
            $doc_stmt->close();
        }
        
        // Kirim notifikasi ke semua Super Admin
        // (Logika notifikasi tetap sama)
        $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
        $superAdminsResult = $conn->query($superAdminSql);
        if ($superAdminsResult) {
            $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
            foreach ($superAdmins as $admin) {
                send_notification(
                    $conn, $admin['organization_id'], $admin['id'],
                    "Pengajuan Pendanaan Baru",
                    "Pengajuan pendanaan '{$title}' membutuhkan verifikasi Anda.",
                    "/app/admin/funding-verification"
                );
            }
        }
        
        $conn->commit();
        http_response_code(201);
        echo json_encode(['message' => 'Pengajuan pendanaan berhasil dikirim dan akan diverifikasi oleh Super Admin.', 'campaign_id' => $campaign_id]);
    } else {
        throw new Exception('Gagal menyimpan data pengajuan pendanaan: ' . $stmt->error);
    }

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>

