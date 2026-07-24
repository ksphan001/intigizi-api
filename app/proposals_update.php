<?php
// File: app/proposals_update.php
// Penjelasan: Diperbarui untuk mengizinkan Administrator, Akuntan, dan Kepala Dapur
// mengedit proposal yang sudah disetujui, selama belum ada PO yang dibuat.
// Juga menambahkan pencatatan siapa yang terakhir kali mengedit.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];
$role_id = (int)$userData['role_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id) || !isset($data->start_date) || !isset($data->end_date) || !isset($data->target_recipients)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID, tanggal mulai, tanggal akhir, dan target penerima wajib diisi.']);
    exit();
}

// Peran yang diizinkan untuk mengedit proposal (baik Draft maupun Disetujui)
$allowed_roles = [2, 3, 7]; // Kepala Dapur, Akuntan, Administrator
if (!in_array($role_id, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['message' => 'Anda tidak memiliki izin untuk mengedit proposal.']);
    exit();
}

$id = (int)$data->id;
$start_date = $data->start_date;
$end_date = $data->end_date;
$target_recipients = (int)$data->target_recipients;

$conn->begin_transaction();
try {
    // 1. Ambil status proposal saat ini
    $checkSql = "SELECT status FROM proposals WHERE id = ? AND organization_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $id, $org_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Proposal tidak ditemukan atau Anda tidak memiliki akses.", 404);
    }
    $proposal = $result->fetch_assoc();
    $checkStmt->close();

    // 2. Jika status bukan 'Draft', periksa dependensi PO
    if ($proposal['status'] !== 'Draft') {
        $poCheckSql = "SELECT id FROM purchase_orders WHERE proposal_id = ? LIMIT 1";
        $poCheckStmt = $conn->prepare($poCheckSql);
        $poCheckStmt->bind_param("i", $id);
        $poCheckStmt->execute();
        if ($poCheckStmt->get_result()->num_rows > 0) {
            throw new Exception("Proposal tidak dapat diedit karena sudah ada Purchase Order yang dibuat untuk proposal ini.", 403); // 403 Forbidden
        }
        $poCheckStmt->close();
    }
    
    // 3. Lanjutkan proses update
    $sql = "UPDATE proposals SET start_date = ?, end_date = ?, target_recipients = ?, last_edited_by = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssiiii", $start_date, $end_date, $target_recipients, $user_id, $id, $org_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $conn->commit();
            http_response_code(200);
            echo json_encode(['message' => 'Proposal berhasil diperbarui.']);
        } else {
            $conn->commit(); // Commit even if no rows changed, maybe user saved same data
            http_response_code(200);
            echo json_encode(['message' => 'Tidak ada perubahan data.']);
        }
    } else {
        throw new Exception('Gagal memperbarui proposal: ' . $stmt->error);
    }
    $stmt->close();
} catch (Exception $e) {
    $conn->rollback();
    $errorCode = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($errorCode);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>

