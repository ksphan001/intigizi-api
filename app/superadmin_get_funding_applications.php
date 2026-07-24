<?php
// File: app/superadmin_get_funding_applications.php
// Deskripsi: API untuk Super Admin mengambil daftar semua pengajuan pendanaan.
// PERBAIKAN:
// 1. Mengambil 'fa.kitchen_name' untuk kolom "Nama Proyek / Dapur".
// 2. Mengambil 'fa.legal_entity_name' untuk kolom "Organisasi Pemohon".
// 3. Menghapus 'LEFT JOIN' yang tidak perlu ke tabel 'organizations'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Hanya Super Admin (role_id 8) yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    // --- PERBAIKAN DI SINI ---
    // Query ini diperbaiki untuk mengambil kolom yang benar
    $sql = "SELECT 
                fa.id, 
                fa.kitchen_name,        -- Ini akan ditampilkan di Kolom 1 (Nama Proyek / Dapur)
                fa.legal_entity_name,   -- Ini akan ditampilkan di Kolom 2 (Organisasi Pemohon)
                fa.pic_full_name,
                fa.status,
                fa.created_at
            FROM funding_applications fa
            ORDER BY fa.created_at DESC";
            
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $applications = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($applications);

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