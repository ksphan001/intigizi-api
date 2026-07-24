<?php
// File: app/funding_campaign_update_status.php
// Penjelasan: API baru untuk Super Admin menyetujui atau menolak
// sebuah pengajuan pendanaan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$user_role = (int)$userData['role_id'];

if ($user_role !== 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->campaign_id) || !isset($data->new_status)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Kampanye dan status baru wajib diisi.']);
    exit();
}

$campaign_id = (int)$data->campaign_id;
$new_status = $data->new_status;
$rejection_reason = isset($data->rejection_reason) ? $conn->real_escape_string($data->rejection_reason) : null;
$valid_statuses = ['active', 'rejected'];

if (!in_array($new_status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['message' => 'Status baru tidak valid.']);
    exit();
}

$conn->begin_transaction();

try {
    // Ambil info kampanye untuk notifikasi
    $info_sql = "SELECT title, user_id, organization_id FROM funding_campaigns WHERE id = ?";
    $info_stmt = $conn->prepare($info_sql);
    $info_stmt->bind_param("i", $campaign_id);
    $info_stmt->execute();
    $campaign_info = $info_stmt->get_result()->fetch_assoc();
    $info_stmt->close();

    if (!$campaign_info) {
        throw new Exception("Kampanye tidak ditemukan.", 404);
    }

    // Update status kampanye
    $sql = "UPDATE funding_campaigns SET status = ?, rejection_reason = ? WHERE id = ? AND status = 'pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $new_status, $rejection_reason, $campaign_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Kirim notifikasi ke pemohon
            $notif_title = $new_status === 'active' ? "Pengajuan Pendanaan Disetujui" : "Pengajuan Pendanaan Ditolak";
            $notif_message = $new_status === 'active' 
                ? "Selamat! Pengajuan Anda '{$campaign_info['title']}' telah disetujui dan akan segera tampil."
                : "Mohon maaf, pengajuan '{$campaign_info['title']}' ditolak. Alasan: " . ($rejection_reason ?: 'Tidak ada alasan spesifik.');
            
            send_notification(
                $conn, 
                $campaign_info['organization_id'], 
                $campaign_info['user_id'],
                $notif_title,
                $notif_message,
                "/app/funding/apply" // Link kembali ke halaman pengajuan
            );

            $conn->commit();
            http_response_code(200);
            echo json_encode(['message' => 'Status pengajuan berhasil diperbarui.']);
        } else {
            throw new Exception("Tidak ada perubahan, pengajuan mungkin sudah diproses atau tidak ditemukan.", 400);
        }
    } else {
        throw new Exception("Gagal memperbarui status pengajuan.");
    }
    $stmt->close();

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
