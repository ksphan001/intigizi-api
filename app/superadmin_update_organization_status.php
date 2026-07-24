<?php
// File: app/superadmin_update_organization_status.php
// Penjelasan: Logika disempurnakan dan izin dikembalikan ke role_id 8.

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

if (!isset($data->organization_id) || !isset($data->is_active)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID organisasi dan status baru wajib diisi.']);
    exit();
}

$organization_id = (int)$data->organization_id;
$is_active = (int)$data->is_active; // 0 atau 1

if ($is_active !== 0 && $is_active !== 1) {
    http_response_code(400);
    echo json_encode(['message' => 'Status tidak valid. Gunakan 0 atau 1.']);
    exit();
}

// Keamanan tambahan: Mencegah Super Admin menonaktifkan organisasinya sendiri (biasanya org_id = 1)
if ($organization_id === 1 && $is_active === 0) {
    http_response_code(403);
    echo json_encode(['message' => 'Tidak dapat menonaktifkan organisasi Super Admin utama.']);
    exit();
}

$conn->begin_transaction();

try {
    // Langkah 1: Update status organisasi
    $sql_org = "UPDATE organizations SET is_active = ? WHERE id = ?";
    $stmt_org = $conn->prepare($sql_org);
    $stmt_org->bind_param("ii", $is_active, $organization_id);

    if ($stmt_org->execute()) {
        $affected_rows = $stmt_org->affected_rows;
        $stmt_org->close();

        // Langkah 2: Update status SEMUA pengguna di dalam organisasi tersebut
        $sql_users = "UPDATE users SET is_active = ? WHERE organization_id = ?";
        $stmt_users = $conn->prepare($sql_users);
        $stmt_users->bind_param("ii", $is_active, $organization_id);
        $stmt_users->execute();
        $stmt_users->close();

        $conn->commit();
        
        if ($affected_rows > 0) {
            http_response_code(200);
            echo json_encode(['message' => 'Status organisasi dan semua penggunanya berhasil diperbarui.']);
        } else {
            http_response_code(200);
            echo json_encode(['message' => 'Tidak ada perubahan status yang dilakukan.']);
        }
    } else {
        throw new Exception('Gagal memperbarui status organisasi.');
    }
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>

