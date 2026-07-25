<?php
// File: app/seed_kitchen_dummy.php
// Penjelasan: Seeding data jumlah penerima manfaat per kategori pada setiap titik distribusi (sppg) dapur IntiGizi.

require_once __DIR__ . '/config.php';

$counts = [
    // Posyandu Kenanga 01 (id: 1)
    ['point_id' => 1, 'cat_id' => 1, 'count' => 20],  // Ibu Hamil
    ['point_id' => 1, 'cat_id' => 2, 'count' => 10],  // Ibu Menyusui
    ['point_id' => 1, 'cat_id' => 3, 'count' => 50],  // Balita
    
    // TK Pembina Sehat (id: 2)
    ['point_id' => 2, 'cat_id' => 4, 'count' => 80],  // KB & TK
    
    // SDN Cilandak Timur 02 (id: 3)
    ['point_id' => 3, 'cat_id' => 5, 'count' => 120], // SD 1 - 3
    ['point_id' => 3, 'cat_id' => 6, 'count' => 100], // SD 4 - 6
    
    // Posyandu Mawar Indah (id: 4)
    ['point_id' => 4, 'cat_id' => 1, 'count' => 15],  // Ibu Hamil
    ['point_id' => 4, 'cat_id' => 3, 'count' => 40],  // Balita
    
    // PAUD Kasih Ibu (id: 5)
    ['point_id' => 5, 'cat_id' => 4, 'count' => 60],  // KB & TK
    
    // SDN Ragunan 01 (id: 6)
    ['point_id' => 6, 'cat_id' => 5, 'count' => 150], // SD 1 - 3
    ['point_id' => 6, 'cat_id' => 6, 'count' => 130], // SD 4 - 6
    
    // Posyandu Bougenville (id: 7)
    ['point_id' => 7, 'cat_id' => 3, 'count' => 35],  // Balita
    
    // TK Aisyiyah 05 (id: 8)
    ['point_id' => 8, 'cat_id' => 4, 'count' => 75]   // KB & TK
];

try {
    $conn->begin_transaction();

    // Hapus data lama agar tidak terjadi penumpukan
    $conn->query("DELETE FROM distribution_point_counts");

    $sql = "INSERT INTO distribution_point_counts (distribution_point_id, category_id, count) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);

    foreach ($counts as $c) {
        $stmt->bind_param("iii", $c['point_id'], $c['cat_id'], $c['count']);
        $stmt->execute();
    }
    
    $stmt->close();
    $conn->commit();

    echo "=================================================================\n";
    echo "SEEDING PENERIMA MANFAAT DAPUR SUKSES!\n";
    echo "=================================================================\n";
    echo "Berhasil mengisi jumlah penerima manfaat pada 8 titik distribusi.\n";
    echo "Silakan lakukan simulasi perencanaan belanja & pengadaan gizi!\n";
    echo "=================================================================\n";

} catch (Throwable $e) {
    $conn->rollback();
    echo "Error Seeding: " . $e->getMessage() . "\n";
} finally {
    $conn->close();
}
?>
