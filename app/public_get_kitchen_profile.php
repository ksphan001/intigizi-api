<?php
// File: app/public_get_kitchen_profile.php
// Penjelasan: API untuk mengambil semua data publik yang dibutuhkan untuk halaman profil dapur.
// --- PERBAIKAN: Mengambil dp.name sebagai kitchen_name ---

require_once __DIR__ . '/config.php';

$slug = isset($_GET['slug']) ? $_GET['slug'] : '';

if (empty($slug)) {
    http_response_code(400);
    echo json_encode(['message' => 'Slug dapur wajib diisi.']);
    exit();
}

try {
    // --- PERBAIKAN DI SINI ---
    // 1. Query diubah menjadi LEFT JOIN untuk memastikan data organisasi tetap tampil
    //    meskipun data dapur utama belum diatur.
    // 2. Menambahkan `dp.name as kitchen_name` untuk memastikan nama dapur (bukan nama yayasan)
    //    dikirim ke frontend dan digunakan pada judul halaman serta alt text gambar.
    $sql_details = "SELECT 
                        o.id, 
                        o.name, 
                        o.organization_type, 
                        o.slug, 
                        o.public_description, 
                        o.profile_picture,
                        dp.address as kitchen_address,
                        dp.latitude, 
                        dp.longitude,
                        dp.name as kitchen_name,
                        (SELECT target_recipients FROM proposals WHERE organization_id = o.id AND status = 'Disetujui' ORDER BY start_date DESC LIMIT 1) as target_recipients
                    FROM organizations o
                    LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
                    WHERE o.slug = ? AND o.is_active = 1 AND o.registration_type = 'Mitra Dapur'
                    LIMIT 1";

    $stmt_details = $conn->prepare($sql_details);
    $stmt_details->bind_param("s", $slug);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    $details = $result_details->fetch_assoc();
    $stmt_details->close();
    
    if (!$details) {
        throw new Exception("Profil dapur dengan slug '{$slug}' tidak ditemukan atau belum aktif.", 404);
    }
    
    $org_id = $details['id'];

    // Ambil galeri dapur
    $sql_gallery = "SELECT id, image_path, caption FROM kitchen_gallery WHERE organization_id = ? ORDER BY created_at DESC LIMIT 8";
    $stmt_gallery = $conn->prepare($sql_gallery);
    $stmt_gallery->bind_param("i", $org_id);
    $stmt_gallery->execute();
    $gallery = $stmt_gallery->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_gallery->close();

    // Ambil jadwal menu yang akan datang
    $sql_schedule = "SELECT pm.id, pm.serving_date, m.menu_name
                     FROM proposal_menus pm
                     JOIN menus m ON pm.menu_id = m.id
                     JOIN proposals p ON pm.proposal_id = p.id
                     WHERE p.organization_id = ? AND p.status = 'Disetujui' AND pm.serving_date >= CURDATE() AND pm.menu_id != 1
                     ORDER BY pm.serving_date ASC";
    $stmt_schedule = $conn->prepare($sql_schedule);
    $stmt_schedule->bind_param("i", $org_id);
    $stmt_schedule->execute();
    $schedule = $stmt_schedule->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_schedule->close();

    // Ambil semua titik distribusi (yang memiliki koordinat) untuk peta
    $sql_points = "SELECT id, name, latitude, longitude, address FROM distribution_points WHERE organization_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND is_main_kitchen = 0";
    $stmt_points = $conn->prepare($sql_points);
    $stmt_points->bind_param("i", $org_id);
    $stmt_points->execute();
    $points = $stmt_points->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_points->close();

    // Ambil tanggal-tanggal unik yang memiliki laporan distribusi
    $sql_events = "SELECT DISTINCT distribution_date 
                   FROM distribution_reports
                   WHERE organization_id = ? 
                   AND distribution_date >= CURDATE() - INTERVAL 3 MONTH";
    $stmt_events = $conn->prepare($sql_events);
    $stmt_events->bind_param("i", $org_id);
    $stmt_events->execute();
    $events_result = $stmt_events->get_result();
    $events = [];
    while ($row = $events_result->fetch_assoc()) {
        $events[] = $row['distribution_date'];
    }
    $stmt_events->close();

    // Gabungkan semua data menjadi satu respons
    $response = [
        'details' => $details,
        'gallery' => $gallery,
        'schedule' => $schedule,
        'distribution_points' => $points,
        'distribution_events' => $events,
    ];

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>