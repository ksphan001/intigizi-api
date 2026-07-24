<?php
// File: app/public_get_featured_kitchens.php
// PENJELASAN: Logika PHP yang membuat URL absolut telah DIHAPUS.
// Backend sekarang konsisten HANYA mengirim path relatif (misal: /uploads/profiles/foto.png).
// --- PERBAIKAN: Mengambil dp.name sebagai kitchen_name ---

require_once __DIR__ . '/config.php';

try {
    $sql = "SELECT
                o.id,
                o.slug,
                o.public_description,
                o.profile_picture,
                dp.name as kitchen_name,
                r.name as regency_name,
                p.name as province_name
            FROM organizations o
            LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
            LEFT JOIN regencies r ON o.regency_id = r.id
            LEFT JOIN provinces p ON o.province_id = p.id
            WHERE
                o.is_active = 1
                AND o.registration_type = 'Mitra Dapur'
                AND o.slug IS NOT NULL 
                AND o.slug != ''
            ORDER BY o.created_at DESC
            LIMIT 3";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan query: " . $conn->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $kitchens = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($kitchens);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mengambil data dapur unggulan.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>