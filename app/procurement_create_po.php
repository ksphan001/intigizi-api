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
if (!isset($data['proposal_id']) || !isset($data['supplier_id']) || !isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Data proposal, supplier, dan item wajib diisi.']);
    exit();
}

$proposal_id = (int)$data['proposal_id'];
$supplier_id = (int)$data['supplier_id'];

$conn->begin_transaction();

try {
    $total_amount = 0;
    foreach ($data['items'] as $item) {
        if (!isset($item['quantity']) || !isset($item['price'])) {
            throw new Exception('Setiap item harus memiliki quantity dan price.');
        }
        $total_amount += (float)$item['quantity'] * (float)$item['price'];
    }

    $po_code = "PO-" . date("Ymd") . "-" . strtoupper(substr(md5(time() . $proposal_id), 0, 6));
    $poSql = "INSERT INTO purchase_orders (organization_id, po_code, proposal_id, supplier_id, total_amount, status) VALUES (?, ?, ?, ?, ?, 'Dikirim')";
    $poStmt = $conn->prepare($poSql);
    $poStmt->bind_param("isiid", $org_id, $po_code, $proposal_id, $supplier_id, $total_amount);
    $poStmt->execute();
    $po_id = $conn->insert_id;
    if ($po_id == 0) throw new Exception('Gagal membuat record PO utama.');
    $poStmt->close();

    $itemSql = "INSERT INTO po_items (organization_id, po_id, ingredient_id, quantity, price_per_unit, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
    $itemStmt = $conn->prepare($itemSql);
    foreach ($data['items'] as $item) {
        $quantity = (float)$item['quantity'];
        $price = (float)$item['price'];
        $subtotal = $quantity * $price;
        $itemStmt->bind_param("iiiddd", $org_id, $po_id, $item['ingredient_id'], $quantity, $price, $subtotal);
        $itemStmt->execute();
    }
    $itemStmt->close();
    
    // Logika notifikasi
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

    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Purchase Order berhasil dibuat dan notifikasi terkirim.', 'po_code' => $po_code]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error internal saat membuat PO.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>

