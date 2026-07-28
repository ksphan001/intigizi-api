<?php
// File: app/supplier_toggle_local.php
// Deskripsi: Mengubah status supplier (Sentra terpusat <-> Lokal Mandiri) untuk dapur bersangkutan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->supplier_id) || !isset($data->action)) {
    http_response_code(400);
    echo json_encode(['message' => 'Supplier ID dan Aksi wajib diisi.']);
    exit();
}

$supplier_id = (int)$data->supplier_id;
$action = trim($data->action); // make_local atau make_marketplace

try {
    // 1. Cek keberadaan supplier di dapur ini
    $sql = "SELECT id, marketplace_id, supplier_name FROM suppliers WHERE id = ? AND organization_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $supplier_id, $org_id);
    $stmt->execute();
    $supplier = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$supplier) {
        throw new Exception("Supplier tidak ditemukan atau Anda tidak memiliki akses.", 404);
    }

    if ($action === 'make_local') {
        if (!$supplier['marketplace_id']) {
            throw new Exception("Supplier ini memang merupakan supplier lokal manual.", 400);
        }

        // Set is_local_override = 1
        $updateSql = "UPDATE suppliers SET is_local_override = 1 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $supplier_id);
        $updateStmt->execute();
        $updateStmt->close();

        echo json_encode([
            'message' => "Supplier '{$supplier['supplier_name']}' berhasil diubah menjadi Supplier Lokal. Pembaruan harga dan profil kini dikelola dapur sepenuhnya.",
            'is_local_override' => 1
        ]);

    } elseif ($action === 'make_marketplace') {
        if (!$supplier['marketplace_id']) {
            throw new Exception("Supplier ini tidak terhubung ke Sentra IntiGizi.", 400);
        }

        $conn->begin_transaction();

        // 1. Matikan override local (is_local_override = 0)
        $updateSql = "UPDATE suppliers SET is_local_override = 0 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $supplier_id);
        $updateStmt->execute();
        $updateStmt->close();

        $conn->commit();

        // 2. Lakukan sync ulang katalog & profil dari Marketplace
        // Kita panggil logika sync yang sudah ada di sync_marketplace_supplier.php secara internal
        // Untuk memicu sync, kita buat mock request ke endpoint tersebut atau salin baris kodenya.
        // Agar rapi, kita kirim redirect internal atau fetch ulang data dari API Marketplace.
        $marketplace_id = (int)$supplier['marketplace_id'];
        
        // Panggil endpoint sync lokal
        $sync_url = "http://localhost/app/sync_marketplace_supplier.php";
        // Kita juga bisa lakukan include saja dengan menyisipkan parameter
        $_POST['marketplace_id'] = $marketplace_id; 
        
        // Fetch ulang profil terbaru dari marketplace untuk sinkronisasi paksa
        $supplier_api_base = rtrim($_ENV['SUPPLIER_API_URL'] ?? 'http://intigizi-supplier-api.test', '/');
        $marketplace_url = $supplier_api_base . "/app/marketplace_suppliers.php?id=" . $marketplace_id;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $marketplace_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 && $response) {
            $marketData = json_decode($response, true);
            if (isset($marketData['supplier']) && isset($marketData['catalog'])) {
                $supplierInfo = $marketData['supplier'];
                $catalogInfo = $marketData['catalog'];

                $conn2 = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
                $conn2->begin_transaction();

                $supplier_name = $conn2->real_escape_string($supplierInfo['supplier_name']);
                $address = $conn2->real_escape_string($supplierInfo['address']);
                $contact_person = $conn2->real_escape_string($supplierInfo['contact_person']);
                $phone_number = $conn2->real_escape_string($supplierInfo['phone_number']);
                $latitude = $supplierInfo['latitude'];
                $longitude = $supplierInfo['longitude'];
                $coverage_radius_km = (int)$supplierInfo['coverage_radius_km'];
                $is_verified = (int)($supplierInfo['is_verified'] ?? 0);
                $average_rating = (float)($supplierInfo['average_rating'] ?? 0.00);
                $review_count = (int)($supplierInfo['review_count'] ?? 0);
                $sla_score = (float)($supplierInfo['sla_score'] ?? 100.00);
                $avg_process_time = (float)($supplierInfo['avg_process_time_hours'] ?? 0.00);

                // Paksa override update data profil sentra terbaru ke lokal
                $upSql = "UPDATE suppliers SET supplier_name = ?, address = ?, contact_person = ?, phone_number = ?, latitude = ?, longitude = ?, coverage_radius_km = ?, is_verified = ?, average_rating = ?, review_count = ?, sla_score = ?, avg_process_time_hours = ? WHERE id = ?";
                $upStmt = $conn2->prepare($upSql);
                $upStmt->bind_param("ssssssiidiidi", $supplier_name, $address, $contact_person, $phone_number, $latitude, $longitude, $coverage_radius_km, $is_verified, $average_rating, $review_count, $sla_score, $avg_process_time, $supplier_id);
                $upStmt->execute();
                $upStmt->close();

                // Sync catalog item
                $conn2->query("DELETE FROM supplier_ingredients WHERE supplier_id = " . $supplier_id);
                
                $unitSql = "SELECT id, symbol FROM units";
                $unitResult = $conn2->query($unitSql);
                $local_units = [];
                while ($u = $unitResult->fetch_assoc()) {
                    $local_units[strtolower($u['symbol'])] = (int)$u['id'];
                }

                $insCatSql = "INSERT INTO supplier_ingredients (supplier_id, ingredient_id, base_price, daily_capacity) VALUES (?, ?, ?, ?)";
                $insCatStmt = $conn2->prepare($insCatSql);

                foreach ($catalogInfo as $item) {
                    $ing_name = $item['ingredient_name'];
                    $ing_res = $conn2->query("SELECT id FROM ingredients WHERE name = '" . $conn2->real_escape_string($ing_name) . "' AND organization_id = " . $org_id . " LIMIT 1");
                    $ing_data = $ing_res->fetch_assoc();
                    
                    if ($ing_data) {
                        $ing_id = (int)$ing_data['id'];
                        $price = (float)$item['price'];
                        $capacity = (float)$item['daily_capacity'];
                        
                        $insCatStmt->bind_param("iidd", $supplier_id, $ing_id, $price, $capacity);
                        $insCatStmt->execute();
                    }
                }
                $insCatStmt->close();
                $conn2->commit();
                $conn2->close();
            }
        }

        echo json_encode([
            'message' => "Supplier '{$supplier['supplier_name']}' berhasil dikoneksikan kembali ke Sentra IntiGizi. Data profil dan katalog diperbarui otomatis.",
            'is_local_override' => 0
        ]);
    } else {
        throw new Exception("Aksi tidak didukung.", 400);
    }

} catch (Throwable $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode(['message' => $e->getMessage()]);
}
?>
