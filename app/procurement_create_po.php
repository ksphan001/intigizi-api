<?php
// File: app/procurement_create_po.php
// PENJELASAN: Ditambahkan logika untuk mengirim notifikasi ke Vendor/Supplier saat PO dibuat.
// PERBAIKAN: Mengatasi error "Cannot use object of type stdClass as array" dengan
// mengubah cara data JSON di-decode dan diakses.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// --- PERBAIKAN 1: Menggunakan `json_decode` dengan argumen `true` ---
// Ini mengubah JSON menjadi array asosiatif, bukan objek stdClass.
$data = json_decode(file_get_contents("php://input"), true);

// --- PERBAIKAN 2: Mengubah semua akses dari objek `->` menjadi array `['...']` ---
if (!isset($data['proposal_id']) || !isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Data proposal dan item wajib diisi.']);
    exit();
}

$proposal_id = (int)$data['proposal_id'];
$supplier_id = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;

if ($supplier_id === null || $supplier_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Gagal membuat PO. Pihak kedua (Supplier) wajib dipilih untuk setiap Purchase Order.']);
    exit();
}

$conn->begin_transaction();

try {
    // Cari harga dan apply harga grosir jika memenuhi syarat
    $processedItems = [];
    $total_amount = 0;
    
    foreach ($data['items'] as $item) {
        if (!isset($item['quantity'])) {
            throw new Exception('Setiap item harus memiliki quantity.');
        }
        
        $ing_id = (int)$item['ingredient_id'];
        $quantity = (float)$item['quantity'];
        
        // Cek database untuk harga & tier grosir
        $priceSql = "SELECT base_price, tier_qty, tier_price FROM supplier_ingredients WHERE supplier_id = ? AND ingredient_id = ? LIMIT 1";
        $priceStmt = $conn->prepare($priceSql);
        $priceStmt->bind_param("ii", $supplier_id, $ing_id);
        $priceStmt->execute();
        $priceRes = $priceStmt->get_result()->fetch_assoc();
        $priceStmt->close();
        
        $price = isset($item['price']) ? (float)$item['price'] : 0.00;
        if ($priceRes) {
            $base_price = (float)$priceRes['base_price'];
            $tier_qty = (float)$priceRes['tier_qty'];
            $tier_price = (float)$priceRes['tier_price'];
            
            if ($tier_qty > 0 && $quantity >= $tier_qty && $tier_price > 0) {
                $price = $tier_price;
            } else {
                $price = $base_price;
            }
        }
        
        $subtotal = $quantity * $price;
        $total_amount += $subtotal;
        
        $processedItems[] = [
            'ingredient_id' => $ing_id,
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal
        ];
    }

    $po_code = "PO-" . date("Ymd") . "-" . strtoupper(substr(md5(time() . $proposal_id . $supplier_id . rand(10, 99)), 0, 6));
    $status = 'Dikirim';
    $vendor_status = 'Menunggu Konfirmasi';

    $poSql = "INSERT INTO purchase_orders (organization_id, po_code, proposal_id, supplier_id, total_amount, status, vendor_status) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $poStmt = $conn->prepare($poSql);
    $poStmt->bind_param("isiidss", $org_id, $po_code, $proposal_id, $supplier_id, $total_amount, $status, $vendor_status);
    $poStmt->execute();
    $po_id = $conn->insert_id;
    if ($po_id == 0) throw new Exception('Gagal membuat record PO utama.');
    $poStmt->close();

    $itemSql = "INSERT INTO po_items (organization_id, po_id, ingredient_id, quantity, price_per_unit, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
    $itemStmt = $conn->prepare($itemSql);
    foreach ($processedItems as $item) {
        $itemStmt->bind_param("iiiddd", $org_id, $po_id, $item['ingredient_id'], $item['quantity'], $item['price'], $item['subtotal']);
        $itemStmt->execute();
    }
    $itemStmt->close();
    
    // Logika notifikasi & sinkronisasi B2B (Hanya jika memiliki supplier)
    if ($supplier_id !== null) {
        $vendor_user_id = null;
        $vendor_org_id = null;
        
        $vendorCheckSql = "SELECT u.id, u.organization_id FROM users u JOIN organizations o ON u.organization_id = o.id WHERE o.id = ? AND o.registration_type = 'Vendor' AND u.role_id = 5 LIMIT 1";
        $vendorStmt = $conn->prepare($vendorCheckSql);
        $vendorStmt->bind_param("i", $supplier_id);
        $vendorStmt->execute();
        if ($vendorRow = $vendorStmt->get_result()->fetch_assoc()) {
            $vendor_user_id = $vendorRow['id'];
            $vendor_org_id = $vendorRow['organization_id'];
        }
        $vendorStmt->close();

        if (!$vendor_user_id) {
            $supplierCheckSql = "SELECT user_id, organization_id FROM suppliers WHERE id = ?";
            $supplierStmt = $conn->prepare($supplierCheckSql);
            $supplierStmt->bind_param("i", $supplier_id);
            $supplierStmt->execute();
            if ($supplierRow = $supplierStmt->get_result()->fetch_assoc()) {
                $vendor_user_id = $supplierRow['user_id'];
                $vendor_org_id = $supplierRow['organization_id'];
            }
            $supplierStmt->close();
        }
        
        if ($vendor_user_id && $vendor_org_id) {
            send_notification($conn, $vendor_org_id, $vendor_user_id, "Pesanan Baru untuk Anda: {$po_code}", "Anda menerima pesanan baru yang perlu ditinjau.", "/app/vendor/orders");
        }

        // --- SINKRONISASI B2B MARKETPLACE ---
        try {
            require_once __DIR__ . '/marketplace_po_helper.php';
            sync_po_to_marketplace($conn, $po_id, $org_id, $supplier_id);
        } catch (Throwable $sync_err) {
            // Biarkan gagal tanpa menggagalkan pengembalian sukses utama
        }
    }
    
    $conn->commit();

    http_response_code(201);
    echo json_encode(['message' => 'Purchase Order berhasil dibuat.', 'po_code' => $po_code]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error internal saat membuat PO.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>

