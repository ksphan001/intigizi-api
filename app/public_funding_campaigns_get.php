<?php
// File: app/public_funding_campaigns_get.php
// PERBAIKAN: Menambahkan 'fc.cover_image_path' ke query SELECT dan GROUP BY
// agar gambar cover tampil di halaman publik.
//
// --- PERBAIKAN BARU (SESUAI PERMINTAAN ANDA) ---
// LEFT JOIN diubah agar HANYA menjumlahkan investasi dengan status = 'paid'

require_once __DIR__ . '/config.php';

try {
    // --- PERBAIKAN DI SINI ---
    // Logika LEFT JOIN diubah:
    // 1. LEFT JOIN ke tabel investments (i)
    // 2. Klausa "ON" sekarang menyertakan "AND i.status = 'paid'"
    // Ini memastikan bahwa SUM(i.total_investment) hanya menghitung investasi yang sudah lunas.
    $sql = "SELECT 
                fc.id, 
                fc.title, 
                fc.target_amount, 
                o.name as organization_name,
                fc.cover_image_path,
                COALESCE(SUM(i.total_investment), 0) as current_amount
            FROM funding_campaigns fc
            JOIN organizations o ON fc.organization_id = o.id
            LEFT JOIN investments i ON fc.id = i.campaign_id AND i.status = 'paid'
            WHERE fc.status = 'active'
            GROUP BY fc.id, fc.title, fc.target_amount, o.name, fc.cover_image_path
            ORDER BY fc.created_at DESC";

    $result = $conn->query($sql);
    if ($result === false) {
        throw new Exception("Query gagal: " . $conn->error);
    }
    $campaigns = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($campaigns);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data proyek pendanaan.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>