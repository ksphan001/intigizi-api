<?php
// File: app/superadmin_get_organizations.php
// PENJELASAN: Diperbarui untuk menyertakan data status berlangganan,
// dan DIPERBAIKI untuk mengambil nama provinsi & kabupaten dengan benar.
// --- PERBAIKAN BARU: Menambahkan 'dp.name as kitchen_name' ---

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();

if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

// Ambil parameter filter dari query string
$registration_type = $_GET['type'] ?? null; // Default ke null jika tidak ada
$province_id = $_GET['province'] ?? null;
$regency_id = $_GET['regency'] ?? null;

try {
    // --- PERBAIKAN UTAMA DI SINI ---
    // Query diubah untuk LEFT JOIN ke tabel provinces, regencies,
    // DAN distribution_points (untuk mendapatkan kitchen_name).
    $sql = "SELECT 
                o.id, 
                o.name, 
                dp.name as kitchen_name, -- Mengambil nama dapur publik
                o.organization_type, 
                o.director_name, 
                o.pic_name, 
                o.pic_whatsapp,
                o.is_active,
                o.created_at,
                o.subscription_status,
                o.subscription_until,
                p.name as province_name,
                r.name as regency_name,
                o.latitude,
                o.longitude
            FROM organizations o
            LEFT JOIN provinces p ON o.province_id = p.id
            LEFT JOIN regencies r ON o.regency_id = r.id
            LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
            WHERE 1=1 ";

    $params = [];
    $types = "";

    // Tambahkan filter berdasarkan tipe registrasi
    if ($registration_type) {
        $sql .= " AND o.registration_type = ?";
        $params[] = $registration_type;
        $types .= "s";
    }

    // Tambahkan filter berdasarkan provinsi
    if ($province_id) {
        $sql .= " AND o.province_id = ?";
        $params[] = $province_id;
        $types .= "s";
    }

    // Tambahkan filter berdasarkan kabupaten/kota
    if ($regency_id) {
        $sql .= " AND o.regency_id = ?";
        $params[] = $regency_id;
        $types .= "s";
    }

    $sql .= " ORDER BY o.created_at DESC";
            
    $stmt = $conn->prepare($sql);

    // Bind parameter jika ada
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $organizations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    http_response_code(200);
    echo json_encode($organizations);

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