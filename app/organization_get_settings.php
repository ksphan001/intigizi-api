<?php
// File: app/organization_get_settings.php
// PENYEMPURNAAN: Logika yang membuat URL absolut telah DIHAPUS.
// PERBAIKAN: Menambahkan 'dp.name as kitchen_name' untuk konsistensi data.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

// Menambahkan header untuk mencegah browser caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Administrator (7), Yayasan (4), Tim Distribusi (6), SuperAdmin (8), AhliGizi (1), Kepala Dapur (2), Akuntan (3)
$allowed_roles = [1, 2, 3, 4, 6, 7, 8];
if (!in_array((int)$userData['role_id'], $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    $sql = "SELECT 
                o.name, 
                o.organization_type,
                o.slug,
                o.public_description,
                o.profile_picture,
                o.director_name,
                o.pic_name,
                o.pic_whatsapp,
                dp.name as kitchen_name, 
                dp.address as kitchen_address,
                COALESCE(dp.latitude, o.latitude) as latitude,
                COALESCE(dp.longitude, o.longitude) as longitude
            FROM organizations o
            LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
            WHERE o.id = ?
            LIMIT 1";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        
        // Jika 'kitchen_name' kosong (data lama), fallback ke nama organisasi
        if (empty($settings['kitchen_name'])) {
            $settings['kitchen_name'] = $settings['name'];
        }

        http_response_code(200);
        echo json_encode($settings);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Data organisasi tidak ditemukan.']);
    }
    
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>