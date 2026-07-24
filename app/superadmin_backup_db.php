<?php
// File: app/superadmin_backup_db.php
// Penjelasan: Endpoint untuk mengekspor database db_intigizi dan menyimpannya di folder server.
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
    // Cari semua tabel di database saat ini
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    $sqlDump = "-- IntiGizi Database Backup\n";
    $sqlDump .= "-- Tanggal: " . date('Y-m-d H:i:s') . "\n";
    $sqlDump .= "-- Host: " . $_ENV['DB_HOST'] . "\n\n";
    
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // 1. Ekspor Struktur Tabel (DDL)
        $sqlDump .= "-- -----------------------------------------------------\n";
        $sqlDump .= "-- Struktur untuk tabel `" . $table . "`\n";
        $sqlDump .= "-- -----------------------------------------------------\n";
        $sqlDump .= "DROP TABLE IF EXISTS `" . $table . "`;\n";
        
        $createTableRes = $conn->query("SHOW CREATE TABLE `" . $table . "`");
        $createTableRow = $createTableRes->fetch_row();
        $sqlDump .= $createTableRow[1] . ";\n\n";

        // 2. Ekspor Data Tabel (DML)
        $sqlDump .= "-- Data untuk tabel `" . $table . "`\n";
        $dataRes = $conn->query("SELECT * FROM `" . $table . "`");
        $numFields = $dataRes->field_count;

        $insertQueries = [];
        while ($row = $dataRes->fetch_row()) {
            $values = [];
            for ($i = 0; $i < $numFields; $i++) {
                if ($row[$i] === null) {
                    $values[] = "NULL";
                } else {
                    $val = $conn->real_escape_string($row[$i]);
                    $val = str_replace("\n", "\\n", $val);
                    $val = str_replace("\r", "\\r", $val);
                    $values[] = "'" . $val . "'";
                }
            }
            $insertQueries[] = "(" . implode(", ", $values) . ")";
        }

        if (count($insertQueries) > 0) {
            $sqlDump .= "INSERT INTO `" . $table . "` VALUES \n" . implode(",\n", $insertQueries) . ";\n";
        }
        $sqlDump .= "\n\n";
    }

    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // Tentukan direktori penyimpanan cadangan
    $backupDir = __DIR__ . '/../backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }

    // Tulis ke file
    $filename = "backup_intigizi_" . date('Y-m-d_H-i-s') . ".sql";
    $filePath = $backupDir . $filename;
    
    if (file_put_contents($filePath, $sqlDump) === false) {
        throw new Exception("Gagal menulis file SQL cadangan di server.");
    }

    http_response_code(200);
    echo json_encode([
        'message' => 'Cadangan database berhasil dibuat di server!',
        'filename' => $filename
    ]);
    exit();

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal membuat cadangan database.',
        'error' => $e->getMessage()
    ]);
    exit();
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
