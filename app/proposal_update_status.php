<?php
// File: app/proposal_update_status.php
// Penjelasan: Validasi kelengkapan jadwal yang kaku telah DIHAPUS.
// Logika ini sekarang sepenuhnya ditangani di frontend untuk memberikan fleksibilitas.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id) || !isset($data->status)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID proposal dan status baru wajib diisi.']);
    exit();
}

$id = (int)$data->id;
$new_status = $data->status;
$valid_statuses = ['Diajukan', 'Ditolak', 'Disetujui'];

if (!in_array($new_status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['message' => 'Status tidak valid.']);
    exit();
}

$prop_info_sql = "SELECT proposal_code, created_by, organization_id FROM proposals WHERE id = ?";
$prop_stmt = $conn->prepare($prop_info_sql);
$prop_stmt->bind_param("i", $id);
$prop_stmt->execute();
$proposal = $prop_stmt->get_result()->fetch_assoc();
$prop_stmt->close();

if (!$proposal) {
    http_response_code(404);
    echo json_encode(['message' => 'Proposal tidak ditemukan.']);
    exit();
}

$user_id = (int)$userData['id'];
$org_id_proposal = (int)$proposal['organization_id'];

if ($userData['org_id'] != $org_id_proposal) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Anda tidak memiliki izin untuk proposal ini.']);
    exit();
}

$stmt = null;
if ($new_status == 'Diajukan') {
    // --- PERBAIKAN: Validasi ketat di backend dihapus ---
    // Logika untuk mengecek kelengkapan jadwal sekarang ada di frontend
    // untuk memberikan fleksibilitas kepada pengguna dalam menandai hari libur.
    $sql = "UPDATE proposals SET status = ? WHERE id = ? AND status = 'Draft' AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $new_status, $id, $org_id_proposal);
} else {
    $sql = "UPDATE proposals SET status = ?, approved_by = ? WHERE id = ? AND status = 'Diajukan' AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("siii", $new_status, $user_id, $id, $org_id_proposal);
}

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        $link = "/app/proposals/" . $id;
        
        if ($new_status == 'Diajukan') {
            $yayasan_sql = "SELECT id FROM users WHERE role_id = 4 AND organization_id = ?";
            $yayasan_stmt = $conn->prepare($yayasan_sql);
            $yayasan_stmt->bind_param("i", $org_id_proposal);
            $yayasan_stmt->execute();
            $yayasan_users = $yayasan_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $yayasan_stmt->close();

            foreach ($yayasan_users as $user) {
                send_notification($conn, $org_id_proposal, $user['id'], "Proposal Baru Diajukan", "Proposal {$proposal['proposal_code']} telah diajukan.", $link);
            }
        } elseif ($new_status == 'Disetujui') {
            send_notification($conn, $org_id_proposal, $proposal['created_by'], "Proposal Disetujui", "Proposal {$proposal['proposal_code']} Anda telah disetujui.", $link);
        } elseif ($new_status == 'Ditolak') {
            send_notification($conn, $org_id_proposal, $proposal['created_by'], "Proposal Ditolak", "Proposal {$proposal['proposal_code']} Anda telah ditolak.", $link);
        }
        
        http_response_code(200);
        echo json_encode(['message' => 'Status proposal berhasil diperbarui.']);
    } else {
        http_response_code(403);
        echo json_encode(['message' => 'Gagal memperbarui status. Kondisi tidak terpenuhi atau Anda tidak memiliki akses.']);
    }
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memperbarui status proposal: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>

