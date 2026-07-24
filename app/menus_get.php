<?php
// File: app/menus_get.php
// Penjelasan: API Diperbarui untuk selalu menyertakan menu global "-- HARI LIBUR --"
// bersama dengan menu-menu yang spesifik milik organisasi yang sedang login.
// --- PERBAIKAN: Menambahkan JOIN ke tabel users dan mengambil created_at ---

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // Query ini akan mengambil:
    // 1. Semua menu yang dibuat oleh organisasi saat ini (organization_id = ?).
    // 2. Menu global "-- HARI LIBUR --" (organization_id IS NULL).
    // 3. Nama pembuat menu (u.full_name) dan tanggal dibuat (m.created_at).
    $sql = "SELECT
                m.id,
                m.menu_name,
                m.created_at,
                u.full_name as created_by_name
            FROM
                menus m
            LEFT JOIN
                users u ON m.created_by = u.id
            WHERE 
                (m.organization_id = ? OR m.organization_id IS NULL)
            ORDER BY 
                m.menu_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        $menus = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($menus);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Query ke database gagal.']);
    }
    
    $stmt->close();
    $conn->close();

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
}
?>
