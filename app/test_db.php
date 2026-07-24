<?php
// File: app/test_db.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h3>Memulai Uji Coba Laporan Purchase Orders (reports_get_purchase_orders.php)</h3>";

require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$conn = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'] ?? '', $_ENV['DB_NAME']);

if ($conn->connect_error) {
    echo "<span style='color:red'>GAGAL koneksi: " . $conn->connect_error . "</span><br>";
    exit();
}
echo "<span style='color:green'>Koneksi database berhasil.</span><br>";

// Test query EXACTLY as written in reports_get_purchase_orders.php (GROUP BY supplier_name)
try {
    $org_id = 1; 
    $start_date = '2026-06-30';
    $end_date = '2026-07-23';

    $sql = "SELECT 
                COALESCE(s.supplier_name, o.name) AS supplier_name,
                COUNT(po.id) AS po_count,
                SUM(po.total_amount) AS total_purchase_value
            FROM 
                purchase_orders po
            LEFT JOIN 
                suppliers s ON po.supplier_id = s.id AND s.organization_id = po.organization_id
            LEFT JOIN
                organizations o ON po.supplier_id = o.id AND o.registration_type = 'Vendor'
            WHERE 
                po.organization_id = ? 
                AND DATE(po.created_at) BETWEEN ? AND ?
                AND po.supplier_id IS NOT NULL
                AND COALESCE(s.supplier_name, o.name) IS NOT NULL
            GROUP BY 
                supplier_name
            ORDER BY 
                total_purchase_value DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan query: " . $conn->error);
    }
    
    $stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo "<span style='color:green'>BERHASIL menjalankan query laporan original!</span><br>";
    echo "Jumlah baris: " . count($data) . "<br>";
    echo "<pre>" . print_r($data, true) . "</pre>";

} catch (Exception $e) {
    echo "<span style='color:red'>GAGAL query laporan original: " . $e->getMessage() . "</span><br>";
}

$conn->close();
?>
