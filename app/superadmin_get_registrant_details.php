<?php
// File: app/superadmin_get_registrant_details.php
// PERBAIKAN: Menghapus kolom 'o.is_hipmi_member' dari query SELECT
// untuk menyesuaikan dengan migrasi database '03_update_funding_form.sql'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$org_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($org_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID pendaftar (organisasi) wajib diisi.']);
    exit();
}

try {
    // Query ini sekarang mengambil nama yayasan (o.name) dan nama dapur (dp.name)
    // dan MENGHAPUS 'o.is_hipmi_member'
    $sql = "SELECT 
                o.id,
                o.name as organization_name,
                dp.name as kitchen_name,
                o.registration_type,
                o.director_name,
                o.pic_name,
                o.pic_whatsapp,
                COALESCE(o.vendor_address, dp.address) as address,
                COALESCE(o.latitude, dp.latitude) as latitude,
                COALESCE(o.longitude, dp.longitude) as longitude,
                o.created_at,
                u.username,
                u.email as pic_email,
                vc.name as vendor_category_name,
                p.name as province_name,
                r.name as regency_name
            FROM organizations o
            LEFT JOIN users u ON o.id = u.organization_id
            LEFT JOIN vendor_categories vc ON o.vendor_category_id = vc.id
            LEFT JOIN provinces p ON o.province_id = p.id
            LEFT JOIN regencies r ON o.regency_id = r.id
            LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
            WHERE o.id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $details = $result->fetch_assoc();
        http_response_code(200);
        echo json_encode($details);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Detail pendaftar tidak ditemukan.']);
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