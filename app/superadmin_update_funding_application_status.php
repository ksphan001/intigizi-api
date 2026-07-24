<?php
// File: app/superadmin_update_funding_application_status.php
// Penjelasan: API untuk Super Admin mengubah status pengajuan
// MODIFIKASI: Ditambahkan `rejection_reason` untuk alur penolakan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->application_id) || !isset($data->new_status)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Pengajuan dan status baru wajib diisi.']);
    exit();
}

$application_id = (int)$data->application_id;
$new_status = $data->new_status;
$rejection_reason = isset($data->rejection_reason) ? $conn->real_escape_string($data->rejection_reason) : null;
$valid_statuses = ['Sedang Diproses', 'Ditolak']; // 'Diterima' sekarang ditangani oleh 'publish'

if (!in_array($new_status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['message' => 'Status baru tidak valid. Gunakan "Sedang Diproses" atau "Ditolak".']);
    exit();
}

$conn->begin_transaction();

try {
    // Ambil info pengajuan untuk notifikasi
    $info_sql = "SELECT legal_entity_name, pic_email, pic_full_name, organization_id FROM funding_applications WHERE id = ?";
    $info_stmt = $conn->prepare($info_sql);
    $info_stmt->bind_param("i", $application_id);
    $info_stmt->execute();
    $app_info = $info_stmt->get_result()->fetch_assoc();
    $info_stmt->close();

    if (!$app_info) {
        throw new Exception("Pengajuan tidak ditemukan.", 404);
    }

    // Update status pengajuan dan alasan penolakan
    $sql = "UPDATE funding_applications SET status = ?, rejection_reason = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $new_status, $rejection_reason, $application_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Kirim notifikasi email ke pemohon
            $notif_title = "Update Status Pengajuan Pendanaan Anda";
            $notif_message = "Status pengajuan pendanaan Anda untuk '{$app_info['legal_entity_name']}' telah diperbarui menjadi: <b>{$new_status}</b>.";
            
            if ($new_status === 'Ditolak' && $rejection_reason) {
                $notif_message .= "<br><br><b>Alasan:</b> " . htmlspecialchars($rejection_reason);
            }
            
            send_direct_email(
                $app_info['pic_email'], 
                $app_info['pic_full_name'],
                $notif_title,
                $notif_message
            );
            
            // Kirim notifikasi in-app
            $userSql = "SELECT id FROM users WHERE organization_id = ? AND role_id = 10 LIMIT 1";
            $userStmt = $conn->prepare($userSql);
            $userStmt->bind_param("i", $app_info['organization_id']);
            $userStmt->execute();
            $user_pic = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if ($user_pic) {
                send_notification(
                    $conn, 
                    $app_info['organization_id'],
                    $user_pic['id'],
                    $notif_title, 
                    "Status pengajuan '{$app_info['legal_entity_name']}' diubah menjadi: {$new_status}.",
                    "/app/funding/apply"
                );
            }


            $conn->commit();
            http_response_code(200);
            echo json_encode(['message' => 'Status pengajuan berhasil diperbarui dan notifikasi telah dikirim.']);
        } else {
            throw new Exception("Tidak ada perubahan, status mungkin sudah sama.", 400);
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