<?php
// File: app/superadmin_download_backup.php
// Penjelasan: Endpoint untuk mengunduh berkas backup SQL dari server ke lokal komputer.
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

// Cek apakah parameter filename ada
if (!isset($_GET['filename']) || empty($_GET['filename'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter nama file cadangan wajib disertakan.']);
    exit();
}

// Sanitasi nama berkas untuk mencegah serangan Directory Traversal (../)
$filename = basename($_GET['filename']);
$backupDir = __DIR__ . '/../backups/';
$filePath = $backupDir . $filename;

// Pastikan file ada
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['message' => 'Berkas cadangan tidak ditemukan di server.']);
    exit();
}

// Bersihkan buffer sebelum men-stream berkas
if (ob_get_length()) ob_clean();

// Set header agar browser men-download berkas
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit();
?>
