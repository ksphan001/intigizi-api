<?php
// File: app/distributor_tracking_cleanup.php
// Deskripsi: Skrip harian (Cronjob/Task Scheduler) untuk membersihkan log pelacakan GPS kurir yang usianya lebih dari 30 hari.

require_once __DIR__ . '/config.php';

// Hanya izinkan dijalankan via CLI (Command Line Interface) atau akses lokal untuk keamanan tambahan
if (php_sapi_name() !== 'cli' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Skrip ini hanya dapat dijalankan melalui CLI atau localhost.']);
    exit();
}

try {
    // Jalankan query penghapusan log GPS lama
    $sql = "DELETE FROM distributor_tracking WHERE recorded_at < NOW() - INTERVAL 30 DAY";
    if ($conn->query($sql)) {
        $affected = $conn->affected_rows;
        echo "Pembersihan Berhasil: {$affected} baris log koordinat GPS lama (>30 hari) telah dihapus.\n";
    } else {
        throw new Exception($conn->error);
    }
} catch (Throwable $e) {
    echo "Pembersihan Gagal: " . $e->getMessage() . "\n";
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
