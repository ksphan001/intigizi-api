<?php
// File: app/superadmin_restore_db.php
// Penjelasan: Endpoint untuk memulihkan (restore) database.
// Mendukung pemulihan dari file unggahan manual ATAU file cadangan yang tersimpan di folder backups/ server.
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

$sqlContent = '';
$source = '';

// Ambil input JSON (untuk pemulihan berkas di server)
$input = json_decode(file_get_contents('php://input'), true);
$rawFilename = $input['filename'] ?? '';

if (!empty($rawFilename)) {
    // Sumber: Berkas yang tersimpan di server
    $filename = basename($rawFilename);
    $backupDir = __DIR__ . '/../backups/';
    $filePath = $backupDir . $filename;

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['message' => 'Berkas cadangan tidak ditemukan di server.']);
        exit();
    }

    $sqlContent = file_get_contents($filePath);
    $source = 'berkas server (' . $filename . ')';
} else {
    // Sumber: Unggahan file manual (multipart/form-data)
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['message' => 'File database SQL wajib diunggah atau nama file server disertakan.']);
        exit();
    }

    $fileTmpPath = $_FILES['backup_file']['tmp_name'];
    $fileName = $_FILES['backup_file']['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Validasi ekstensi berkas (.sql)
    if ($fileExtension !== 'sql') {
        http_response_code(400);
        echo json_encode(['message' => 'Format berkas tidak valid. Harap unggah berkas berformat .sql.']);
        exit();
    }

    $sqlContent = file_get_contents($fileTmpPath);
    $source = 'berkas unggahan manual (' . $fileName . ')';
}

try {
    if (!$sqlContent || trim($sqlContent) === '') {
        throw new Exception("Berkas SQL kosong atau tidak dapat dibaca.");
    }

    // Nonaktifkan pemeriksaan kunci asing sementara
    $conn->query("SET FOREIGN_KEY_CHECKS = 0;");

    // Jalankan multi query
    if ($conn->multi_query($sqlContent)) {
        do {
            // Bebaskan hasil query sebelumnya untuk menghindari error "commands out of sync"
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }

    if ($conn->error) {
        throw new Exception("Error saat eksekusi query: " . $conn->error);
    }

    // Aktifkan kembali pemeriksaan kunci asing
    $conn->query("SET FOREIGN_KEY_CHECKS = 1;");

    http_response_code(200);
    echo json_encode(['message' => 'Basis data berhasil dipulihkan dari ' . $source . ' dengan sukses!']);
    exit();

} catch (Throwable $e) {
    // Pastikan foreign keys diaktifkan kembali jika terjadi kegagalan di tengah jalan
    $conn->query("SET FOREIGN_KEY_CHECKS = 1;");
    
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal memulihkan basis data.',
        'error' => $e->getMessage()
    ]);
    exit();
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
