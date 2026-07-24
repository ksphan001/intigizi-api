<?php
// File: app/public_get_courier_location.php
// Penjelasan: Mengambil lokasi TERAKHIR dari SETIAP kurir yang aktif.
// Mendukung multi-kurir (3 akun berbeda) dalam satu dapur.

require_once __DIR__ . '/config.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$org_id = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;

if ($org_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter org_id wajib diisi.']);
    exit();
}

try {
    // QUERY BARU: Menggunakan teknik Groupwise Max (mengambil data terbaru per user)
    // Kita mencari semua user (kurir) yang punya aktivitas di tabel tracking 30 menit terakhir.
    $sql = "SELECT 
                t1.latitude, 
                t1.longitude, 
                t1.recorded_at,
                u.full_name as courier_name,
                u.id as user_id
            FROM distributor_tracking t1
            JOIN (
                -- Subquery untuk mendapatkan waktu tracking terakhir per user
                SELECT user_id, MAX(recorded_at) as max_time
                FROM distributor_tracking
                WHERE organization_id = ? 
                AND recorded_at >= NOW() - INTERVAL 30 MINUTE
                GROUP BY user_id
            ) t2 ON t1.user_id = t2.user_id AND t1.recorded_at = t2.max_time
            LEFT JOIN users u ON t1.user_id = u.id
            WHERE t1.organization_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Query Error: " . $conn->error);
    }

    $stmt->bind_param("ii", $org_id, $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $couriers = [];
    while ($row = $result->fetch_assoc()) {
        $couriers[] = [
            'user_id' => $row['user_id'],
            'name' => $row['courier_name'] ?: 'Kurir',
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'last_updated' => $row['recorded_at']
        ];
    }
    $stmt->close();

    if (count($couriers) > 0) {
        http_response_code(200);
        echo json_encode([
            'found' => true,
            'couriers' => $couriers // Mengembalikan ARRAY kurir
        ]);
    } else {
        http_response_code(200);
        echo json_encode([
            'found' => false,
            'couriers' => [],
            'message' => 'Tidak ada kurir yang aktif.'
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>