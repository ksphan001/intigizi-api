<?php
// File: app/superadmin_list_backups.php
// Penjelasan: Endpoint untuk mencantumkan file backup SQL yang tersedia di folder backups/ server.
// Hanya dapat diakses oleh Super Admin.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

// Verifikasi JWT dan peroleh data user
$userData = verify_jwt_token();

// Pastikan yang mengakses adalah Super Admin (role_id = 8)
if ((int)$userData['role_id'] !== 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Fitur ini hanya untuk Super Admin.']);
    exit();
}

try {
    $backupDir = __DIR__ . '/../backups/';
    $backups = [];

    if (is_dir($backupDir)) {
        $files = scandir($backupDir);
        foreach ($files as $file) {
            // Hanya ambil berkas .sql
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $filePath = $backupDir . $file;
                $backups[] = [
                    'filename' => $file,
                    'size' => filesize($filePath), // dalam bytes
                    'created_at' => date('Y-m-d H:i:s', filemtime($filePath))
                ];
            }
        }
    }

    // Urutkan berdasarkan tanggal dibuat secara descending (terbaru di atas)
    usort($backups, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    http_response_code(200);
    echo json_encode($backups);
    exit();

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mencantumkan daftar file cadangan.',
        'error' => $e->getMessage()
    ]);
    exit();
}
?>
