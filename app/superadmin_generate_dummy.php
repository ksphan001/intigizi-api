<?php
// File: app/superadmin_generate_dummy.php
// Penjelasan: Endpoint untuk menghasilkan data dummy operasional & transaksi di server dalam jumlah besar (rich seeder).
// Berguna untuk demonstrasi (demo) dan QA. Hanya dapat diakses oleh Super Admin.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

// Verifikasi JWT dan peroleh data user (Bypass jika dijalankan via CLI terminal)
if (php_sapi_name() === 'cli') {
    $userData = ['role_id' => 8, 'org_id' => 1];
} else {
    $userData = verify_jwt_token();
    // Pastikan yang mengakses adalah Super Admin (role_id = 8)
    if ((int)$userData['role_id'] !== 8) {
        http_response_code(403);
        echo json_encode(['message' => 'Akses ditolak. Fitur ini hanya untuk Super Admin.']);
        exit();
    }
}

try {
    // 1. Matikan Foreign Key Checks & Kosongkan Tabel
    $conn->query("SET FOREIGN_KEY_CHECKS = 0;");
    
    $tablesToTruncate = [
        'financial_transactions',
        'po_items',
        'purchase_orders',
        'quick_distribution_photos',
        'quick_distributions',
        'distribution_photos',
        'distribution_reports',
        'beneficiary_bmi_history',
        'beneficiaries',
        'distribution_point_counts',
        'distribution_points',
        'menu_ingredients',
        'menus',
        'nutrition_data',
        'ingredients',
        'volunteers',
        'operational_expenses',
        'proposals',
        'proposal_menus',
        'production_logs',
        'stock_transactions',
        'stock'
    ];

    foreach ($tablesToTruncate as $table) {
        $conn->query("TRUNCATE TABLE `{$table}`;");
    }

    // 2. Pastikan Dapur Demo (ID = 2) Tersedia sebagai Yayasan
    $orgCheck = $conn->query("SELECT id FROM organizations WHERE id = 2");
    if ($orgCheck->num_rows === 0) {
        $conn->query("INSERT INTO organizations (id, name, slug, organization_type, registration_type, is_active, subscription_status, subscription_until) 
            VALUES (2, 'Demo Dapur Nusantara', 'demo-dapur', 'Yayasan', 'Mitra Dapur', 1, 'active', '2030-01-01')");
    }

    // Pastikan Dapur SPPG Sukadamai (ID = 4) Tersedia sebagai Dapur/SPPG Anak
    $orgCheck4 = $conn->query("SELECT id FROM organizations WHERE id = 4");
    if ($orgCheck4->num_rows === 0) {
        $conn->query("INSERT INTO organizations (id, name, slug, organization_type, registration_type, is_active, subscription_status, subscription_until, parent_organization_id, director_name, pic_name, pic_whatsapp, province_id, regency_id) 
            VALUES (4, 'Dapur SPPG Sukadamai', 'sppg-sukadamai', 'Dapur/SPPG', 'Mitra Dapur', 1, 'trial', '2030-01-01', 2, 'Demo Ketua Dapur', 'Demo Administrator Dapur', '08123456789', '33', '3374')");
    }

    // 3. Salin data dari master_ingredients ke ingredients untuk organisasi SPPG ID = 4
    $conn->query("INSERT INTO ingredients (organization_id, name, unit_id, latest_price, master_ingredient_id) 
        SELECT 4, name, 2, estimated_price, id FROM master_ingredients");
 
    // 4. Salin data nutrisi ke nutrition_data untuk SPPG ID = 4
    $conn->query("INSERT INTO nutrition_data (organization_id, ingredient_id, calories, protein, carbohydrates, fat, fiber, bdd_percentage) 
        SELECT 4, i.id, m.calories, m.protein, m.carbohydrates, m.fat, m.fiber, m.bdd_percentage 
        FROM master_ingredients m 
        JOIN ingredients i ON m.name = i.name 
        WHERE i.organization_id = 4");

    // Dapatkan ID beberapa bahan penting untuk resep & stok
    $getIngredientId = function($name) use ($conn) {
        $res = $conn->query("SELECT id FROM ingredients WHERE name = '" . $conn->real_escape_string($name) . "' AND organization_id = 4");
        return ($res->num_rows > 0) ? $res->fetch_assoc()['id'] : null;
    };

    $berasId = $getIngredientId('Beras giling');
    $ayamId = $getIngredientId('Daging ayam, dada, mentah');
    $wortelId = $getIngredientId('Wortel');
    $telurId = $getIngredientId('Telur ayam ras, segar');
    $susuId = $getIngredientId('Susu Sekolah MBG');
    $bawangMerahId = $getIngredientId('Bawang merah');
    $bawangPutihId = $getIngredientId('Bawang putih');
    $tempeId = $getIngredientId('Tempe');
    $tahuId = $getIngredientId('Tahu');
    $kentangId = $getIngredientId('Kentang, segar');
    $minyakId = $getIngredientId('Minyak kelapa sawit');
    $bayamId = $getIngredientId('Bayam');
    $pisangId = $getIngredientId('Pisang ambon');
    $melonId = $getIngredientId('Melon');

    // 5. Buat 6 Data Menu Variatif (Tabel menus & menu_ingredients)
    $menusToCreate = [
        ['name' => 'Nasi Ayam Goreng & Wortel Rebus', 'ingredients' => [$berasId => 80, $ayamId => 100, $wortelId => 40, $minyakId => 10]],
        ['name' => 'Nasi Sop Ayam & Telur Puyuh', 'ingredients' => [$berasId => 80, $ayamId => 60, $wortelId => 30, $telurId => 20]],
        ['name' => 'Susu Sehat & Bubur Oat', 'ingredients' => [$susuId => 200]],
        ['name' => 'Nasi Orek Tempe & Tumis Bayam', 'ingredients' => [$berasId => 80, $tempeId => 75, $bayamId => 50, $bawangMerahId => 5]],
        ['name' => 'Perkedel Kentang & Tahu Goreng', 'ingredients' => [$kentangId => 100, $tahuId => 50, $telurId => 10]],
        ['name' => 'Jus Pisang Susu & Buah Melon', 'ingredients' => [$pisangId => 80, $susuId => 100, $melonId => 50]]
    ];

    $menuIds = [];
    foreach ($menusToCreate as $menuData) {
        $stmt = $conn->prepare("INSERT INTO menus (organization_id, menu_name, created_by) VALUES (4, ?, 5)"); // 5 = ahli-gizi
        $stmt->bind_param("s", $menuData['name']);
        $stmt->execute();
        $mId = $conn->insert_id;
        $menuIds[] = $mId;

        foreach ($menuData['ingredients'] as $ingId => $qty) {
            if (!$ingId) continue;
            $jsonPortion = json_encode([
                "1" => $qty, "2" => $qty, "3" => round($qty * 0.6), "4" => round($qty * 0.7),
                "5" => round($qty * 0.8), "6" => $qty, "7" => $qty, "8" => $qty
            ]);
            $stmtDetails = $conn->prepare("INSERT INTO menu_ingredients (organization_id, menu_id, ingredient_id, quantity_per_portion) VALUES (4, ?, ?, ?)");
            $stmtDetails->bind_param("iis", $mId, $ingId, $jsonPortion);
            $stmtDetails->execute();
        }
    }

    // 6. Buat 8 Titik Distribusi (Tabel distribution_points)
    $points = [
        ['name' => 'Posyandu Kenanga 01', 'address' => 'Jl. Cilandak Raya No. 10'],
        ['name' => 'TK Pembina Sehat', 'address' => 'Gg. Swadaya No. 15'],
        ['name' => 'SDN Cilandak Timur 02', 'address' => 'Jl. TB Simatupang No. 2'],
        ['name' => 'Posyandu Mawar Indah', 'address' => 'Jl. Jati Padang No. 8'],
        ['name' => 'PAUD Kasih Ibu', 'address' => 'Gg. Masjid No. 24'],
        ['name' => 'SDN Ragunan 01', 'address' => 'Jl. Harsono RM No. 1'],
        ['name' => 'Posyandu Bougenville', 'address' => 'Jl. Kebagusan No. 4'],
        ['name' => 'TK Aisyiyah 05', 'address' => 'Jl. Margonda Raya No. 12']
    ];
    $pointIds = [];
    foreach ($points as $p) {
        $stmt = $conn->prepare("INSERT INTO distribution_points (organization_id, name, address, latitude, longitude) VALUES (4, ?, ?, -6.29 + (rand()-0.5)*0.05, 106.81 + (rand()-0.5)*0.05)");
        $stmt->bind_param("ss", $p['name'], $p['address']);
        $stmt->execute();
        $pointIds[] = $conn->insert_id;
    }

    // 7. Buat 10 Sukarelawan (Tabel volunteers)
    $volunteerNames = [
        'Siti Khadijah', 'Bambang Tri', 'Lestari Indah', 'Dewi Lestari', 'Joko Susilo', 
        'Rina Wati', 'Andi Pratama', 'Hendra Wijaya', 'Eka Kartika', 'Ahmad Dani'
    ];
    $jobs = ['Penyaji Makanan', 'Kurir Distribusi', 'Persiapan Dapur', 'Pencatat BMI'];
    foreach ($volunteerNames as $vName) {
        $job = $jobs[array_rand($jobs)];
        $phone = '08' . rand(100000000, 999999999);
        $stmt = $conn->prepare("INSERT INTO volunteers (organization_id, full_name, job_type, phone_number, address) VALUES (4, ?, ?, ?, 'Jakarta Selatan')");
        $stmt->bind_param("sss", $vName, $job, $phone);
        $stmt->execute();
    }

    // 8. Buat 120 Penerima Manfaat secara Dinamis (Tabel beneficiaries & beneficiary_bmi_history)
    $firstNames = ['Rizky', 'Budi', 'Aisyah', 'Siti', 'Eko', 'Dedi', 'Putri', 'Sari', 'Gita', 'Agus', 'Lutfi', 'Dewi', 'Tri', 'Sri', 'Agung', 'Ilham', 'Wawan', 'Novi', 'Fajar', 'Dina', 'Rian', 'Yudi', 'Mega', 'Soni', 'Ani', 'Indra', 'Adit', 'Rudi', 'Tina', 'Hana'];
    $lastNames = ['Pratama', 'Santoso', 'Wulandari', 'Hidayat', 'Kusuma', 'Lestari', 'Wijaya', 'Saputra', 'Ramadhan', 'Nugroho', 'Astuti', 'Putra', 'Setiawan', 'Gunawan', 'Susanti', 'Hadi', 'Sutrisno', 'Purnama'];
    
    for ($i = 0; $i < 120; $i++) {
        $fullName = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $nik = '3174' . str_pad(rand(100000000000, 999999999999), 12, '0', STR_PAD_LEFT);
        $phone = '08' . rand(100000000, 999999999);
        $ptId = $pointIds[$i % count($pointIds)];
        $catId = rand(3, 6); // Balita, KB, SD
        
        $weight = rand(150, 350) / 10; // 15.0 s.d 35.0 kg
        $height = rand(900, 1300) / 10; // 90.0 s.d 130.0 cm
        $bmi = $weight / (($height / 100) * ($height / 100));

        $stmt = $conn->prepare("INSERT INTO beneficiaries (organization_id, full_name, nik_nisn, address, distribution_point_id, category_id, phone_number, current_weight_kg, current_height_cm, current_bmi) 
            VALUES (4, ?, ?, 'Alamat Demo Penerima', ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiisddd", $fullName, $nik, $ptId, $catId, $phone, $weight, $height, $bmi);
        $stmt->execute();
        $beneficiaryId = $conn->insert_id;

        // Riwayat BMI (2 entri per orang)
        $conn->query("INSERT INTO beneficiary_bmi_history (organization_id, beneficiary_id, measurement_date, weight_kg, height_cm, bmi, recorded_by_user_id) 
            VALUES (4, {$beneficiaryId}, DATE_SUB(CURDATE(), INTERVAL 60 DAY), " . ($weight - rand(2, 4)) . ", " . ($height - 2) . ", {$bmi}, 5)");
        $conn->query("INSERT INTO beneficiary_bmi_history (organization_id, beneficiary_id, measurement_date, weight_kg, height_cm, bmi, recorded_by_user_id) 
            VALUES (4, {$beneficiaryId}, DATE_SUB(CURDATE(), INTERVAL 30 DAY), " . ($weight - rand(1, 2)) . ", " . ($height - 1) . ", {$bmi}, 5)");
    }

    // 9. Isi Stok Awal untuk 15 Bahan Baku Utama (Tabel stock & stock_transactions)
    $stockItems = [
        $berasId => 1500.00,
        $ayamId => 800.00,
        $wortelId => 450.00,
        $telurId => 600.00,
        $susuId => 3000.00,
        $bawangMerahId => 100.00,
        $bawangPutihId => 100.00,
        $tempeId => 200.00,
        $tahuId => 200.00,
        $kentangId => 300.00,
        $minyakId => 400.00,
        $bayamId => 150.00,
        $pisangId => 500.00,
        $melonId => 300.00
    ];

    foreach ($stockItems as $ingId => $qty) {
        if (!$ingId) continue;
        $conn->query("INSERT INTO stock (organization_id, ingredient_id, current_quantity) VALUES (4, {$ingId}, {$qty}) ON DUPLICATE KEY UPDATE current_quantity = {$qty}");
        $conn->query("INSERT INTO stock_transactions (organization_id, ingredient_id, type, quantity, notes, transaction_date) 
            VALUES (4, {$ingId}, 'Masuk', {$qty}, 'Penerimaan Stok Awal', DATE_SUB(NOW(), INTERVAL 15 DAY))");
    }

    // 10. Buat 4 Proposal Siklus Menu Berurutan (3 Selesai, 1 Aktif/Diajukan)
    $proposals = [
        ['code' => 'PRP-2026-001', 'start' => -21, 'end' => -15, 'status' => 'Disetujui'],
        ['code' => 'PRP-2026-002', 'start' => -14, 'end' => -8, 'status' => 'Disetujui'],
        ['code' => 'PRP-2026-003', 'start' => -7, 'end' => -1, 'status' => 'Disetujui'],
        ['code' => 'PRP-2026-004', 'start' => 0, 'end' => 6, 'status' => 'Diajukan']
    ];

    foreach ($proposals as $prop) {
        $startDate = date('Y-m-d', strtotime("{$prop['start']} days"));
        $endDate = date('Y-m-d', strtotime("{$prop['end']} days"));

        $conn->query("INSERT INTO proposals (organization_id, proposal_code, start_date, end_date, target_recipients, status, created_by) 
            VALUES (4, '{$prop['code']}', '{$startDate}', '{$endDate}', 150, '{$prop['status']}', 7)");
        $proposalId = $conn->insert_id;

        // Hubungkan menu ke proposal (proposal_menus)
        for ($day = 0; $day < 7; $day++) {
            $servingDate = date('Y-m-d', strtotime("{$prop['start']} days +$day days"));
            $menuId = $menuIds[$day % count($menuIds)];
            $conn->query("INSERT INTO proposal_menus (organization_id, proposal_id, menu_id, serving_date) VALUES (4, {$proposalId}, {$menuId}, '{$servingDate}')");
        }

        // 11. Buat Laporan Distribusi untuk proposal yang disetujui (Tabel distribution_reports)
        if ($prop['status'] === 'Disetujui') {
            for ($day = 0; $day < 7; $day++) {
                $distDate = date('Y-m-d', strtotime("{$prop['start']} days +$day days"));
                // Masukkan laporan untuk setiap titik distribusi
                foreach ($pointIds as $ptId) {
                    $menuId = $menuIds[$day % count($menuIds)];
                    $conn->query("INSERT INTO distribution_reports (organization_id, distribution_point_id, distribution_date, menu_id, quantity_sent, quantity_received, status, notes, reported_by) 
                        VALUES (4, {$ptId}, '{$distDate}', {$menuId}, 50, 50, 'Diterima', 'Distribusi selesai tanpa hambatan', 6)");
                }
            }
        }
    }

    // 12. Buat Pengeluaran Operasional (Tabel operational_expenses) - Lebih Banyak Data
    $expenses = [
        ['desc' => 'Beli Tabung Gas LPG 3Kg (x3)', 'amount' => 360000.00, 'cat' => 1, 'days' => -20],
        ['desc' => 'Pembelian Bensin Motor Kurir', 'amount' => 50000.00, 'cat' => 2, 'days' => -18],
        ['desc' => 'Listrik & Air Dapur Utama', 'amount' => 450000.00, 'cat' => 3, 'days' => -15],
        ['desc' => 'Beli Sabun Cuci Piring & Kebersihan', 'amount' => 120000.00, 'cat' => 1, 'days' => -12],
        ['desc' => 'Pembelian Bensin Kurir Siklus 2', 'amount' => 50000.00, 'cat' => 2, 'days' => -10],
        ['desc' => 'Pembayaran Honor Relawan Dapur', 'amount' => 1500000.00, 'cat' => 4, 'days' => -7], // 4 = Biaya Tenaga Kerja
        ['desc' => 'Sewa Genset Darurat', 'amount' => 250000.00, 'cat' => 3, 'days' => -5],
        ['desc' => 'Pembelian Bensin Kurir Siklus 3', 'amount' => 50000.00, 'cat' => 2, 'days' => -2],
        ['desc' => 'Pembelian ATK & Label Kemasan', 'amount' => 180000.00, 'cat' => 1, 'days' => 0]
    ];

    foreach ($expenses as $exp) {
        $expDate = date('Y-m-d', strtotime("{$exp['days']} days"));
        $conn->query("INSERT INTO operational_expenses (organization_id, category_id, description, amount, expense_date, created_by, source_account_id) 
            VALUES (4, {$exp['cat']}, '{$exp['desc']}', {$exp['amount']}, '{$expDate}', 4, 1)"); // 1 = Kas Tunai
    }

    // 13. Buat Jurnal Entri Keuangan Lengkap (Tabel financial_transactions)
    // Transaksi Awal: Penerimaan Hibah Besar
    $conn->query("INSERT INTO financial_transactions (organization_id, transaction_date, description, debit_account_id, credit_account_id, amount, created_by) 
        VALUES (4, DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'Penerimaan Modal Hibah Awal Kemenkes', 2, 7, 150000000.00, 4)"); // Debit Bank (2), Kredit Modal (7)

    // Pencairan ke Kas Tunai Dapur
    $conn->query("INSERT INTO financial_transactions (organization_id, transaction_date, description, debit_account_id, credit_account_id, amount, created_by) 
        VALUES (4, DATE_SUB(CURDATE(), INTERVAL 24 DAY), 'Tarik Kas Bank ke Kas Tunai Dapur', 1, 2, 20000000.00, 4)"); // Debit Kas Tunai (1), Kredit Bank (2)

    // Transaksi Belanja Bahan Baku Rutin (5 Transaksi)
    $belanja = [
        ['desc' => 'Belanja Bahan Pokok Beras & Susu (Minggu 1)', 'amount' => 3500000.00, 'days' => -21],
        ['desc' => 'Belanja Protein Ayam & Telur (Minggu 1)', 'amount' => 4200000.00, 'days' => -18],
        ['desc' => 'Belanja Sayur, Buah & Bumbu (Minggu 1)', 'amount' => 1800000.00, 'days' => -17],
        ['desc' => 'Belanja Bahan Baku Segar (Minggu 2)', 'amount' => 5200000.00, 'days' => -11],
        ['desc' => 'Belanja Bahan Baku Mingguan (Minggu 3)', 'amount' => 6100000.00, 'days' => -4]
    ];

    foreach ($belanja as $b) {
        $trxDate = date('Y-m-d', strtotime("{$b['days']} days"));
        $conn->query("INSERT INTO financial_transactions (organization_id, transaction_date, description, debit_account_id, credit_account_id, amount, created_by) 
            VALUES (4, '{$trxDate}', '{$b['desc']}', 4, 1, {$b['amount']}, 4)"); // Debit Biaya Bahan Baku (4), Kredit Kas (1)
    }

    // Masukkan transaksi pengeluaran operasional ke dalam Jurnal Finansial secara otomatis
    foreach ($expenses as $exp) {
        $expDate = date('Y-m-d', strtotime("{$exp['days']} days"));
        // Hubungkan kategori biaya operasional ke akun akuntansi yang sesuai
        $debitAcc = ($exp['cat'] === 4) ? 9 : 5; // Biaya Tenaga Kerja (9) atau Biaya Operasional (5)
        $conn->query("INSERT INTO financial_transactions (organization_id, transaction_date, description, debit_account_id, credit_account_id, amount, created_by) 
            VALUES (4, '{$expDate}', 'Jurnal: {$exp['desc']}', {$debitAcc}, 1, {$exp['amount']}, 4)");
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1;");

    http_response_code(200);
    echo json_encode(['message' => 'Ratusan data demo berhasil dihasilkan secara dinamis di server! Database sekarang sangat kaya akan data histori.']);
    exit();

} catch (Throwable $e) {
    $conn->query("SET FOREIGN_KEY_CHECKS = 1;");
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal menghasilkan data demo dalam jumlah besar.',
        'error' => $e->getMessage()
    ]);
    exit();
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
