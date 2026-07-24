<?php
// File: app/superadmin_approve_registration.php
// Penjelasan: Pengecekan izin dikembalikan ke role_id 8.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();

// PERBAIKAN: Super Admin kembali menggunakan role_id 8
if ($userData['role_id'] != 8) {
    http_response_code(403); // Forbidden
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->registrant_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Pendaftar (registrant_id) wajib diisi.']);
    exit();
}

$registrant_id = (int)$data->registrant_id;

// Mulai transaksi untuk memastikan semua operasi berhasil
$conn->begin_transaction();

try {
    // Langkah 1: Aktifkan organisasi
    $sql_org = "UPDATE organizations SET is_active = 1 WHERE id = ?";
    $stmt_org = $conn->prepare($sql_org);
    $stmt_org->bind_param("i", $registrant_id);
    $stmt_org->execute();
    
    if ($stmt_org->affected_rows == 0) {
        throw new Exception("Pendaftar dengan ID tersebut tidak ditemukan atau sudah aktif.", 404);
    }
    $stmt_org->close();

    // Langkah 2: Aktifkan SEMUA pengguna yang terkait dengan organisasi tersebut
    $sql_users = "UPDATE users SET is_active = 1 WHERE organization_id = ?";
    $stmt_users = $conn->prepare($sql_users);
    $stmt_users->bind_param("i", $registrant_id);
    $stmt_users->execute();
    $stmt_users->close();

    // Jika semua berhasil, commit transaksi
    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Pendaftaran telah berhasil disetujui dan diaktifkan.']);

} catch (Exception $e) {
    $conn->rollback(); // Batalkan semua perubahan jika terjadi error
    $errorCode = $e->getCode() ?: 500;
    http_response_code($errorCode);
    echo json_encode(['message' => 'Gagal menyetujui pendaftaran.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>

