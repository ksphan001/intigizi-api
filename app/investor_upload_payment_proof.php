<?php
// File: app/investor_upload_payment_proof.php
// Penjelasan: API endpoint BARU untuk investor mengunggah bukti pembayaran.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$user_role = (int)$userData['role_id'];

// Keamanan: Hanya Investor (role_id = 9)
if ($user_role !== 9) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

if (!isset($_POST['investment_id']) || !isset($_FILES['payment_proof'])) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Investasi dan file bukti pembayaran wajib diisi.']);
    exit();
}

$investment_id = (int)$_POST['investment_id'];
$file = $_FILES['payment_proof'];

$conn->begin_transaction();

try {
    // 1. Verifikasi kepemilikan investasi dan status
    $sql_check = "SELECT id, campaign_id, status FROM investments WHERE id = ? AND user_id = ? FOR UPDATE";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("ii", $investment_id, $user_id);
    $stmt_check->execute();
    $investment = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if (!$investment) {
        throw new Exception("Investasi tidak ditemukan atau Anda tidak memiliki akses.", 404);
    }
    if ($investment['status'] !== 'pending') {
        throw new Exception("Bukti bayar tidak dapat diunggah karena investasi ini sudah diproses.", 409);
    }

    // 2. Proses unggah file
    $target_dir = __DIR__ . "/../uploads/investment_proofs/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_filename = "proof_invest_{$investment_id}_" . time() . "." . $file_ext;
    $target_file = $target_dir . $new_filename;
    $file_path = "/uploads/investment_proofs/" . $new_filename;

    if (!move_uploaded_file($file['tmp_name'], $target_file)) {
        throw new Exception("Gagal memindahkan file yang diunggah.");
    }

    // 3. Update database
    $sql_update = "UPDATE investments SET payment_proof_path = ? WHERE id = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("si", $file_path, $investment_id);
    $stmt_update->execute();
    $stmt_update->close();

    // 4. Kirim notifikasi ke Super Admin
    $superAdminSql = "SELECT id, organization_id FROM users WHERE role_id = 8 AND is_active = 1";
    $superAdminsResult = $conn->query($superAdminSql);
    if ($superAdminsResult) {
        $superAdmins = $superAdminsResult->fetch_all(MYSQLI_ASSOC);
        foreach ($superAdmins as $admin) {
            send_notification(
                $conn, 
                $admin['organization_id'],
                $admin['id'],
                "Bukti Bayar Investasi Diterima",
                "Investor telah mengunggah bukti bayar untuk Investasi ID: {$investment_id}. Mohon segera verifikasi.", 
                "/app/admin/investment-verification"
            );
        }
    }
    
    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Bukti pembayaran berhasil diunggah dan akan segera diverifikasi.']);

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