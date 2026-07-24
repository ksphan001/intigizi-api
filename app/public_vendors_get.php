<?php
// File: app/public_vendors_get.php
// Penjelasan: PERBAIKAN DEFINITIF. Menggunakan query asli sebagai dasar,
// dan hanya menambahkan kolom yang diperlukan untuk rating dan foto profil.

require_once __DIR__ . '/config.php';

try {
    // --- PERBAIKAN: Menggunakan query asli dan menambahkan kolom rating/foto ---
    $sql = "SELECT 
                o.id, 
                o.name, 
                o.vendor_description,
                o.vendor_address,
                o.province,
                o.profile_picture,
                o.average_rating,
                o.review_count,
                vc.name as category_name
            FROM organizations o
            LEFT JOIN vendor_categories vc ON o.vendor_category_id = vc.id
            WHERE o.registration_type = 'Vendor' AND o.is_active = 1
            ORDER BY o.name ASC";
            
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $vendors = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($vendors);

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

