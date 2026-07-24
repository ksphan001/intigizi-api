<?php
// File: app/distribution_points_delete.php
// PERBAIKAN KEAMANAN: Menambahkan pengecekan dependensi sebelum menghapus
// untuk mencegah data yatim (orphaned data) di tabel lain.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID titik distribusi wajib diisi.']);
    exit();
}

$id = (int)$data->id;

$conn->begin_transaction();
try {
    // --- PENGECEKAN DEPENDENSI BARU ---
    // 1. Cek apakah titik ini digunakan di tabel 'beneficiaries'
    $checkBeneficiarySql = "SELECT id FROM beneficiaries WHERE distribution_point_id = ? AND organization_id = ? LIMIT 1";
    $stmt = $conn->prepare($checkBeneficiarySql);
    $stmt->bind_param("ii", $id, $org_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception("Titik distribusi ini tidak dapat dihapus karena masih terhubung dengan data penerima manfaat.", 409); // 409 Conflict
    }
    $stmt->close();

    // 2. Cek apakah titik ini digunakan di tabel 'distribution_reports'
    $checkReportSql = "SELECT id FROM distribution_reports WHERE distribution_point_id = ? AND organization_id = ? LIMIT 1";
    $stmt = $conn->prepare($checkReportSql);
    $stmt->bind_param("ii", $id, $org_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception("Titik distribusi ini tidak dapat dihapus karena sudah memiliki riwayat laporan distribusi.", 409);
    }
    $stmt->close();
    // --- AKHIR PENGECEKAN ---

    // 3. Jika aman, lanjutkan penghapusan
    $sql = "DELETE FROM distribution_points WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $org_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $conn->commit();
            http_response_code(200);
            echo json_encode(['message' => 'Titik distribusi berhasil dihapus.']);
        } else {
            throw new Exception('Titik distribusi tidak ditemukan atau Anda tidak memiliki akses.', 404);
        }
    } else {
        throw new Exception('Gagal menghapus titik distribusi: ' . $stmt->error, 500);
    }
    $stmt->close();

} catch (Exception $e) {
    $conn->rollback();
    $code = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
