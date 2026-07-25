<?php
// File: app/seed_kitchen_comparison_dummy.php
// Penjelasan: Seeding data perbandingan harga supplier lokal dapur IntiGizi.

require_once __DIR__ . '/config.php';

$org_id = 4; // Dapur SPPG Sukadamai

$suppliers = [
    [
        'id' => 1,
        'supplier_name' => 'PT Supplier Gizi Prima',
        'address' => 'Jl. Jenderal Sudirman No. 12, Senayan, Jakarta Pusat',
        'contact_person' => 'Andi Pemasok',
        'phone_number' => '08123456789',
        'latitude' => -6.175392,
        'longitude' => 106.827153,
        'coverage_radius_km' => 15,
        'is_verified' => 1,
        'marketplace_id' => 1,
        'catalog' => [
            ['ing_id' => 39, 'price' => 24000.00, 'capacity' => 500.00, 'unit' => 'butir'],
            ['ing_id' => 1, 'price' => 14500.00, 'capacity' => 1000.00, 'unit' => 'kg'],
            ['ing_id' => 31, 'price' => 115000.00, 'capacity' => 100.00, 'unit' => 'kg'],
            ['ing_id' => 80, 'price' => 12000.00, 'capacity' => 200.00, 'unit' => 'kg']
        ]
    ],
    [
        'id' => 2,
        'supplier_name' => 'CV Sayuran Organik Jaya',
        'address' => 'Jl. RS Fatmawati No. 45, Cilandak, Jakarta Selatan',
        'contact_person' => 'Budi Sayur',
        'phone_number' => '08213456781',
        'latitude' => -6.2943,
        'longitude' => 106.8028,
        'coverage_radius_km' => 20,
        'is_verified' => 1,
        'marketplace_id' => 2,
        'catalog' => [
            ['ing_id' => 80, 'price' => 10500.00, 'capacity' => 250.00, 'unit' => 'kg'], // Cheaper Wortel
            ['ing_id' => 39, 'price' => 25000.00, 'capacity' => 300.00, 'unit' => 'butir']
        ]
    ],
    [
        'id' => 3,
        'supplier_name' => 'UD Daging Berkah Mandiri',
        'address' => 'Jl. Raya Bogor KM 24, Ciracas, Jakarta Timur',
        'contact_person' => 'Hendra Daging',
        'phone_number' => '08783456782',
        'latitude' => -6.3283,
        'longitude' => 106.8678,
        'coverage_radius_km' => 25,
        'is_verified' => 1,
        'marketplace_id' => 3,
        'catalog' => [
            ['ing_id' => 31, 'price' => 108000.00, 'capacity' => 150.00, 'unit' => 'kg'], // Cheaper Daging Sapi
            ['ing_id' => 39, 'price' => 23500.00, 'capacity' => 400.00, 'unit' => 'butir']  // Cheapest Telur
        ]
    ]
];

try {
    $conn->begin_transaction();

    // Hapus data mapping lama untuk 3 ID supplier ini
    $conn->query("DELETE FROM supplier_ingredients WHERE supplier_id IN (1, 2, 3)");
    $conn->query("DELETE FROM suppliers WHERE id IN (1, 2, 3) AND organization_id = {$org_id}");

    // Insert Suppliers
    $suppSql = "INSERT INTO suppliers (id, organization_id, supplier_name, address, contact_person, phone_number, latitude, longitude, coverage_radius_km, is_verified, marketplace_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $suppStmt = $conn->prepare($suppSql);

    $catSql = "INSERT INTO supplier_ingredients (supplier_id, ingredient_id, base_price, daily_capacity, unit_symbol) VALUES (?, ?, ?, ?, ?)";
    $catStmt = $conn->prepare($catSql);

    foreach ($suppliers as $s) {
        $suppStmt->bind_param("iissssddiii", $s['id'], $org_id, $s['supplier_name'], $s['address'], $s['contact_person'], $s['phone_number'], $s['latitude'], $s['longitude'], $s['coverage_radius_km'], $s['is_verified'], $s['marketplace_id']);
        $suppStmt->execute();

        foreach ($s['catalog'] as $c) {
            $catStmt->bind_param("iidds", $s['id'], $c['ing_id'], $c['price'], $c['capacity'], $c['unit']);
            $catStmt->execute();
        }
    }

    $suppStmt->close();
    $catStmt->close();

    $conn->commit();
    echo "=================================================================\n";
    echo "SEEDING PERBANDINGAN HARGA & GPS SUPPLIER DI DAPUR SUKSES!\n";
    echo "=================================================================\n";
    echo "Berhasil memetakan 3 Pemasok Marketplace dengan tumpang tindih produk:\n";
    echo "- Telur Ayam Ras (Pemasok 1: Rp24rb, Pemasok 2: Rp25rb, Pemasok 3: Rp23.5rb)\n";
    echo "- Wortel (Pemasok 1: Rp12rb, Pemasok 2: Rp10.5rb)\n";
    echo "- Daging Sapi Sirloin (Pemasok 1: Rp115rb, Pemasok 3: Rp108rb)\n";
    echo "=================================================================\n";

} catch (Throwable $e) {
    $conn->rollback();
    echo "Error Seeding: " . $e->getMessage() . "\n";
} finally {
    $conn->close();
}
?>
