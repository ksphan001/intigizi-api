<?php
// File: app/config.php
// Penjelasan: DIPERBARUI. Mengubah pengaturan zona waktu default ke WIB (Asia/Jakarta).

// --- Pengaturan Zona Waktu ---
// Ini memastikan semua fungsi tanggal/waktu di PHP menggunakan WIB (UTC+7).
date_default_timezone_set('Asia/Jakarta');

// --- Centralized Headers & CORS Management ---

// Memuat library dan file .env
require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// --- Error Reporting Configuration ---
$app_env = $_ENV['APP_ENV'] ?? 'production';
if ($app_env === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// Daftar domain yang diizinkan (dari file .env)
$allowed_origins = explode(',', $_ENV['ALLOWED_ORIGINS'] ?? '');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Cek apakah domain peminta ada di dalam daftar yang diizinkan
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Access-Control-Allow-Credentials: true");
}

// Handle pre-flight request (OPTIONS method)
// Ini penting agar request dengan header 'Authorization' untuk JWT bisa lewat
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    http_response_code(200);
    exit();
}

// Mengatur header default untuk semua response API menjadi JSON
header('Content-Type: application/json');


// --- Database Connection ---

// Konfigurasi Database
$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_pass = $_ENV['DB_PASS'];
$db_name = $_ENV['DB_NAME'];

// Buat Koneksi
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Cek Koneksi
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Koneksi ke database gagal: ' . $conn->connect_error]);
    exit();
}

$conn->set_charset("utf8mb4");
?>

