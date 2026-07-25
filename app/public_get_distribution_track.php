<?php
// File: app/public_get_distribution_track.php
// Deskripsi: API publik utama untuk mengambil data pelacakan distribusi
// berdasarkan ID dapur (organisasi) dan tanggal yang dipilih.
// UPDATED: Now includes Quick Distribution (Manual) entries.

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$org_id = isset($_GET['org_id']) ? (int) $_GET['org_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if ($org_id <= 0 || empty($date)) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter org_id dan date wajib diisi.']);
    exit();
}

try {
    $response = [
        'main_kitchen' => null,
        'distributions' => []
    ];

    // 1. Ambil data dapur utama (titik awal)
    $kitchen_sql = "SELECT name, latitude, longitude 
                    FROM distribution_points 
                    WHERE organization_id = ? AND is_main_kitchen = 1 
                    LIMIT 1";
    $kitchen_stmt = $conn->prepare($kitchen_sql);
    $kitchen_stmt->bind_param("i", $org_id);
    $kitchen_stmt->execute();
    $kitchen_result = $kitchen_stmt->get_result();
    $response['main_kitchen'] = $kitchen_result->fetch_assoc();
    $kitchen_stmt->close();

    $distributions_combined = [];

    // ---------------------------------------------------------
    // 2. STANDARD DISTRIBUTIONS (from distribution_reports)
    // ---------------------------------------------------------
    $dist_sql = "SELECT 
                    dr.id as report_id,
                    dr.delivery_time,
                    dr.total_beneficiaries,
                    dr.menu_id,
                    dr.status,
                    m.menu_name,
                    dp.name AS point_name,
                    dp.latitude,
                    dp.longitude,
                    dr.reported_by as courier_id,
                    u.name as courier_name
                 FROM distribution_reports dr
                 JOIN menus m ON dr.menu_id = m.id
                 JOIN distribution_points dp ON dr.distribution_point_id = dp.id
                 LEFT JOIN users u ON dr.reported_by = u.id
                 WHERE dr.organization_id = ? AND dr.distribution_date = ?";

    $dist_stmt = $conn->prepare($dist_sql);
    $dist_stmt->bind_param("is", $org_id, $date);
    $dist_stmt->execute();
    $distributions_result = $dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dist_stmt->close();

    foreach ($distributions_result as $dist) {
        // Breakdown Penerima
        $breakdown_sql = "SELECT bc.name as category_name, dpc.count
                         FROM distribution_point_counts dpc
                         JOIN beneficiary_categories bc ON dpc.category_id = bc.id
                         JOIN distribution_reports dr ON dpc.distribution_point_id = dr.distribution_point_id
                         WHERE dr.id = ?";
        $breakdown_stmt = $conn->prepare($breakdown_sql);
        $breakdown_stmt->bind_param("i", $dist['report_id']);
        $breakdown_stmt->execute();
        $beneficiary_breakdown = $breakdown_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $breakdown_stmt->close();

        // Foto Dokumentasi
        $photo_stmt = $conn->prepare("SELECT id, image_path, caption FROM distribution_photos WHERE report_id = ?");
        $photo_stmt->bind_param("i", $dist['report_id']);
        $photo_stmt->execute();
        $photos = $photo_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $photo_stmt->close();

        // Data Gizi (Hitung Total per Porsi dari Bahan)
        // KARENA 'quantity_per_portion' adalah JSON, kita hitung di PHP.
        $ing_sql = "SELECT 
                        mi.quantity_per_portion,
                        nd.calories, nd.protein, nd.fat, nd.carbohydrates
                    FROM menu_ingredients mi 
                    JOIN ingredients ing ON mi.ingredient_id = ing.id 
                    LEFT JOIN nutrition_data nd ON mi.ingredient_id = nd.ingredient_id AND nd.organization_id = mi.organization_id
                    WHERE mi.menu_id = ?";

        $ing_stmt = $conn->prepare($ing_sql);
        $ing_stmt->bind_param("i", $dist['menu_id']);
        $ing_stmt->execute();
        $ingredients = $ing_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ing_stmt->close();

        $calc_nutrition = [
            'energy' => 0,
            'protein' => 0,
            'fat' => 0,
            'carbo' => 0,
            'fiber' => 0
        ];

        // Asumsi: Ambil porsi untuk Kategori ID 1 (Umum/Dewasa) atau nilai pertama yang ditemukan
        foreach ($ingredients as $ing) {
            $qty_json = json_decode($ing['quantity_per_portion'], true);
            $qty = 0;
            if (is_array($qty_json)) {
                // Skema JSON: {"cat_id": quantity, ...}
                // Ambil quantity terbesar sebagai representasi (atau specific ID jika ada konstanta)
                if (!empty($qty_json)) {
                    $qty = max($qty_json);
                }
            }

            if ($qty > 0) {
                $calc_nutrition['energy'] += ($qty * ($ing['calories'] ?? 0)) / 100;
                $calc_nutrition['protein'] += ($qty * ($ing['protein'] ?? 0)) / 100;
                $calc_nutrition['fat'] += ($qty * ($ing['fat'] ?? 0)) / 100;
                $calc_nutrition['carbo'] += ($qty * ($ing['carbohydrates'] ?? 0)) / 100;
            }
        }

        $distributions_combined[] = [
            'report_id' => $dist['report_id'],
            'type' => 'standard',
            'menu_name' => $dist['menu_name'],
            'point_name' => $dist['point_name'],
            'point_coords' => [
                'lat' => $dist['latitude'],
                'lon' => $dist['longitude']
            ],
            'delivery_time' => $dist['delivery_time'],
            'total_beneficiaries' => $dist['total_beneficiaries'],
            'beneficiary_breakdown' => $beneficiary_breakdown,
            'nutrition' => $calc_nutrition,
            'photos' => $photos,
            'courier_id' => $dist['courier_id'] ? (int)$dist['courier_id'] : null,
            'courier_name' => $dist['courier_name'],
            'status' => $dist['status']
        ];
    }

    // ---------------------------------------------------------
    // 3. QUICK DISTRIBUTIONS (from quick_distributions)
    // ---------------------------------------------------------
    $quick_sql = "SELECT 
                    qd.id, 
                    qd.distribution_date, 
                    qd.menu_name, 
                    qd.portion_count, 
                    qd.nutrition_info, 
                    qd.delivery_time,
                    qd.status,
                    dp.name as point_name,
                    dp.latitude,
                    dp.longitude
                  FROM quick_distributions qd
                  JOIN distribution_points dp ON qd.distribution_point_id = dp.id
                  WHERE qd.organization_id = ? AND qd.distribution_date = ? 
                  AND (qd.status = 'Dikirim' OR qd.status = 'Diterima' OR qd.status = 'Terjadwal')";

    $quick_stmt = $conn->prepare($quick_sql);
    $quick_stmt->bind_param("is", $org_id, $date);
    $quick_stmt->execute();
    $quick_result = $quick_stmt->get_result();

    while ($qd = $quick_result->fetch_assoc()) {
        // Foto Dokumentasi
        $qd_photo_stmt = $conn->prepare("SELECT id, image_path, caption FROM quick_distribution_photos WHERE quick_distribution_id = ?");
        $qd_photo_stmt->bind_param("i", $qd['id']);
        $qd_photo_stmt->execute();
        $qd_photos = $qd_photo_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $qd_photo_stmt->close();

        // Nutrition Mapping
        $raw_json = $qd['nutrition_info'];
        // Coba decode langsung, jika gagal coba stripslashes (jika ada double escaping)
        $nutrition_info = json_decode($raw_json, true);
        if ($nutrition_info === null && json_last_error() !== JSON_ERROR_NONE) {
            $nutrition_info = json_decode(stripslashes($raw_json), true);
        }

        // Defensive: Make sure it's an array to avoid warnings if data is '0' or null
        if (!is_array($nutrition_info)) {
            $nutrition_info = [];
        }

        $nutrition_data = [
            'energy' => $nutrition_info['calories'] ?? 0,
            'protein' => $nutrition_info['protein'] ?? 0,
            'fat' => $nutrition_info['fat'] ?? 0,
            'carbo' => $nutrition_info['carbs'] ?? 0,
            'fiber' => 0
        ];

        $distributions_combined[] = [
            'report_id' => 'quick_' . $qd['id'],
            'type' => 'quick',
            'menu_name' => $qd['menu_name'],
            'point_name' => $qd['point_name'],
            'point_coords' => [
                'lat' => $qd['latitude'],
                'lon' => $qd['longitude']
            ],
            // Format DATETIME ke TIME (H:i:s) agar sesuai dengan Frontend yang mengharapkan waktu saja
            // Frontend menggunakan substring(0, 5) yang jika DATETIME akan mengambil "YYYY-" (salah).
            // Jika kita kirim "HH:MM:SS", substring(0, 5) akan mengambil "HH:MM" (benar).
            'delivery_time' => $qd['delivery_time'] ? date('H:i:s', strtotime($qd['delivery_time'])) : null,
            'total_beneficiaries' => $qd['portion_count'],
            'beneficiary_breakdown' => [], // Tidak ada breakdown spesifik
            'nutrition' => $nutrition_data,
            'photos' => $qd_photos
        ];
    }
    $quick_stmt->close();


    $response['distributions'] = $distributions_combined;

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mengambil data pelacakan.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>