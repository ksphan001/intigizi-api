<?php
// File: app/public_gallery_get.php
// Penjelasan: API publik baru untuk mengambil foto-foto terbaru dari galeri dapur
// untuk ditampilkan di halaman depan. Tidak memerlukan login.

require_once __DIR__ . '/config.php';

try {
    // Mengambil 4 foto terbaru dari organisasi yang aktif
    $sql = "SELECT
                kg.id,
                kg.image_path,
                kg.caption,
                o.name as organization_name
            FROM kitchen_gallery kg
            JOIN organizations o ON kg.organization_id = o.id
            WHERE o.is_active = 1
            ORDER BY kg.created_at DESC
            LIMIT 4";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    $gallery_items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($gallery_items);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mengambil data galeri.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
