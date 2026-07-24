<?php
// File: app/production_tasks_get.php
// Penjelasan: API endpoint baru untuk mengambil daftar tugas produksi yang akan datang.
// API ini secara otomatis mengambil semua menu yang telah dijadwalkan dari proposal yang disetujui,
// dan menyaring yang produksinya belum dicatat.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // Query ini mengambil semua jadwal menu dari proposal yang 'Disetujui',
    // yang tanggalnya adalah hari ini atau di masa depan,
    // dan yang belum ada catatannya di 'production_logs'.
    $sql = "SELECT
                pm.serving_date,
                p.id as proposal_id,
                p.proposal_code,
                m.menu_name,
                p.target_recipients
            FROM
                proposal_menus pm
            JOIN
                proposals p ON pm.proposal_id = p.id
            JOIN
                menus m ON pm.menu_id = m.id
            LEFT JOIN
                production_logs pl ON p.id = pl.proposal_id AND pm.serving_date = pl.production_date
            WHERE
                p.organization_id = ?
                AND p.status = 'Disetujui'
                AND pm.menu_id != 1 -- Bukan hari libur
                AND pm.serving_date >= CURDATE() -- Mulai dari hari ini
                AND pl.id IS NULL -- Hanya yang belum dicatat di log produksi
            ORDER BY
                pm.serving_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($tasks);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi kesalahan saat mengambil data tugas produksi.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        if(isset($stmt)) $stmt->close();
        $conn->close();
    }
}
?>
