<?php
// fix_qd_nutrition.php
require_once __DIR__ . '/config.php';

// Cek koneksi
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Default valid JSON
$default_nutrition = json_encode([
    'calories' => 0,
    'protein' => 0,
    'fat' => 0,
    'carbs' => 0
]);

echo "Default JSON: " . $default_nutrition . "\n";

// Update semua record yang nutrition_info = '0'
$sql = "UPDATE quick_distributions SET nutrition_info = ? WHERE nutrition_info = '0'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $default_nutrition);

if ($stmt->execute()) {
    echo "Berhasil memperbaiki data. Jumlah baris yang diupdate: " . $stmt->affected_rows . "\n";
} else {
    echo "Gagal mengupdate: " . $stmt->error . "\n";
}

$stmt->close();
$conn->close();
?>