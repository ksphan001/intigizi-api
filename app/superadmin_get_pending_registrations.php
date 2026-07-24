<?php
// File: app/superadmin_get_pending_registrations.php
// Penjelasan: Pengecekan izin dikembalikan ke role_id 8.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();

// PERBAIKAN: Super Admin kembali menggunakan role_id 8
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    // PERBAIKAN: Query disempurnakan untuk mencegah duplikasi
    // dan hanya mengambil satu perwakilan (user PIC) per organisasi yang belum aktif.
    $sql = "SELECT 
                o.id as registrant_id,
                o.name as organization_name,
                o.registration_type,
                o.pic_name,
                o.created_at
            FROM organizations o
            WHERE o.is_active = 0
            GROUP BY o.id
            ORDER BY o.created_at ASC";
            
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $pending_registrations = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($pending_registrations);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi error internal pada server.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>

