<?php
// File: app/sync_marketplace_supplier.php
// Penjelasan: Sinkronisasi data profil & katalog supplier dari Marketplace ke database lokal dapur.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->marketplace_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'Marketplace ID wajib dilampirkan.']);
    exit();
}

$marketplace_id = (int)$data->marketplace_id;

try {
    // 1. Fetch data dari API Marketplace
    $marketplace_url = "http://intigizi-supplier-api.test/app/marketplace_suppliers.php?id=" . $marketplace_id;
    
    // Gunakan cURL untuk memanggil API Marketplace
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $marketplace_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        throw new Exception("Gagal menghubungi server Marketplace (Code: {$http_code}).", 502);
    }

    $marketData = json_decode($response, true);
    if (!isset($marketData['supplier']) || !isset($marketData['catalog'])) {
        throw new Exception("Format data dari Marketplace tidak valid.", 502);
    }

    $supplierInfo = $marketData['supplier'];
    $catalogInfo = $marketData['catalog'];

    $conn->begin_transaction();

    // 2. Cek apakah supplier ini sudah pernah di-sync di dapur ini
    $checkSql = "SELECT id FROM suppliers WHERE marketplace_id = ? AND organization_id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $marketplace_id, $org_id);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    $local_supplier_id = 0;

    $supplier_name = $conn->real_escape_string($supplierInfo['supplier_name']);
    $address = $conn->real_escape_string($supplierInfo['address']);
    $contact_person = $conn->real_escape_string($supplierInfo['contact_person']);
    $phone_number = $conn->real_escape_string($supplierInfo['phone_number']);
    $latitude = $supplierInfo['latitude'];
    $longitude = $supplierInfo['longitude'];
    $coverage_radius_km = (int)$supplierInfo['coverage_radius_km'];

    $is_verified = (int)($supplierInfo['is_verified'] ?? 0);

    if ($existing) {
        $local_supplier_id = (int)$existing['id'];
        // Update
        $upSql = "UPDATE suppliers SET supplier_name = ?, address = ?, contact_person = ?, phone_number = ?, latitude = ?, longitude = ?, coverage_radius_km = ?, is_verified = ? WHERE id = ?";
        $upStmt = $conn->prepare($upSql);
        $upStmt->bind_param("ssssssiii", $supplier_name, $address, $contact_person, $phone_number, $latitude, $longitude, $coverage_radius_km, $is_verified, $local_supplier_id);
        $upStmt->execute();
        $upStmt->close();
    } else {
        // Insert Baru
        $insSql = "INSERT INTO suppliers (organization_id, supplier_name, address, contact_person, phone_number, latitude, longitude, coverage_radius_km, marketplace_id, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insStmt = $conn->prepare($insSql);
        $insStmt->bind_param("issssssiii", $org_id, $supplier_name, $address, $contact_person, $phone_number, $latitude, $longitude, $coverage_radius_km, $marketplace_id, $is_verified);
        $insStmt->execute();
        $local_supplier_id = $insStmt->insert_id;
        $insStmt->close();
    }

    // 3. Sinkronisasikan katalog barang supplier ke tabel supplier_ingredients
    // Hapus pemetaan katalog lama untuk supplier ini
    $delSql = "DELETE FROM supplier_ingredients WHERE supplier_id = ?";
    $delStmt = $conn->prepare($delSql);
    $delStmt->bind_param("i", $local_supplier_id);
    $delStmt->execute();
    $delStmt->close();

    // Dapatkan daftar satuan (units) lokal untuk pencocokan unit_symbol
    $unitSql = "SELECT id, symbol FROM units";
    $unitResult = $conn->query($unitSql);
    $units = [];
    while ($row = $unitResult->fetch_assoc()) {
        $units[strtolower($row['symbol'])] = (int)$row['id'];
    }

    // Loop data katalog dari marketplace
    foreach ($catalogInfo as $item) {
        $itemName = $conn->real_escape_string($item['ingredient_name']);
        $itemPrice = (float)$item['base_price'];
        $itemCapacity = (float)$item['daily_capacity'];
        $itemUnit = strtolower($item['unit_symbol']);

        // 3a. Cari apakah bahan makanan ini sudah terdaftar di daftar bahan makanan dapur
        $ingSql = "SELECT id FROM ingredients WHERE name = ? AND organization_id = ? LIMIT 1";
        $ingStmt = $conn->prepare($ingSql);
        $ingStmt->bind_param("si", $itemName, $org_id);
        $ingStmt->execute();
        $ingResult = $ingStmt->get_result()->fetch_assoc();
        $ingStmt->close();

        $local_ingredient_id = 0;

        if ($ingResult) {
            $local_ingredient_id = (int)$ingResult['id'];
        } else {
            // Dapatkan unit_id lokal, default ke 1 (atau index pertama jika tidak cocok)
            $unit_id = isset($units[$itemUnit]) ? $units[$itemUnit] : 1;

            // Buat bahan baku baru di daftar bahan dapur secara otomatis
            $newIngSql = "INSERT INTO ingredients (organization_id, name, unit_id, latest_price) VALUES (?, ?, ?, ?)";
            $newIngStmt = $conn->prepare($newIngSql);
            $newIngStmt->bind_param("isid", $org_id, $itemName, $unit_id, $itemPrice);
            $newIngStmt->execute();
            $local_ingredient_id = $newIngStmt->insert_id;
            $newIngStmt->close();
        }

        // 3b. Masukkan katalog ke supplier_ingredients
        $insCatSql = "INSERT INTO supplier_ingredients (supplier_id, ingredient_id, base_price, daily_capacity, unit_symbol) VALUES (?, ?, ?, ?, ?)";
        $insCatStmt = $conn->prepare($insCatSql);
        $insCatStmt->bind_param("iidds", $local_supplier_id, $local_ingredient_id, $itemPrice, $itemCapacity, $item['unit_symbol']);
        $insCatStmt->execute();
        $insCatStmt->close();
    }

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Sinkronisasi dengan Marketplace berhasil!', 'local_supplier_id' => $local_supplier_id]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
