<?php
// File: app/distribution_points_get.php
// Penjelasan: API ini mengambil semua titik distribusi untuk sebuah organisasi,
// dan juga menggabungkan (JOIN) data jumlah penerima per kategori.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // Query utama mengambil data dari distribution_points
    // Subquery dengan JSON_ARRAYAGG digunakan untuk mengumpulkan semua
    // data kategori dan jumlahnya menjadi satu field JSON.
    $sql = "SELECT 
                dp.id, 
                dp.name, 
                dp.address, 
                dp.pic_name, 
                dp.pic_phone, 
                dp.latitude, 
                dp.longitude,
                COALESCE((
                    SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('category_id', dpc.category_id, 'count', dpc.count)
                    ) 
                    FROM distribution_point_counts dpc 
                    WHERE dpc.distribution_point_id = dp.id
                ), JSON_ARRAY()) as category_counts
            FROM distribution_points dp
            WHERE dp.organization_id = ? AND dp.is_main_kitchen = 0 
            ORDER BY dp.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $points = [];
    while($row = $result->fetch_assoc()) {
        // Decode string JSON menjadi array PHP agar bisa dibaca oleh frontend
        $row['category_counts'] = json_decode($row['category_counts']);
        $points[] = $row;
    }
    
    http_response_code(200);
    echo json_encode($points);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memuat data titik distribusi.', 'error' => $e->getMessage()]);
} finally {
    if (isset($stmt)) $stmt->close();
    if (isset($conn)) $conn->close();
}
?>
