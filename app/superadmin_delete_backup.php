<?php
// File: app/superadmin_delete_backup.php
// Penjelasan: Endpoint untuk menghapus berkas backup SQL dari server.
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

// Ambil input JSON
$input = json_decode(file_get_contents('php://input'), true);
$rawFilename = $input['filename'] ?? '';

if (empty($rawFilename)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama file wajib disertakan.']);
    exit();
}

// Sanitasi nama berkas untuk mencegah Directory Traversal
$filename = basename($rawFilename);
$backupDir = __DIR__ . '/../backups/';
$filePath = $backupDir . $filename;

// Pastikan file ada
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['message' => 'Berkas cadangan tidak ditemukan di server.']);
    exit();
}

// Hapus berkas
if (unlink($filePath)) {
    http_response_code(200);
    echo json_encode(['message' => 'Berkas cadangan berhasil dihapus dari server.']);
    exit();
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menghapus berkas cadangan dari disk server.']);
    exit();
}
?>
