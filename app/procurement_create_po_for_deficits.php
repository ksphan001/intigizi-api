<?php
// File: app/procurement_create_po_for_deficits.php
// Penjelasan: API untuk membuat PO otomatis secara split berdasarkan bahan baku defisit dan supplier terpilih.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->proposal_id) || !isset($data->items) || !is_array($data->items)) {
    http_response_code(400);
    echo json_encode(['message' => 'Proposal ID dan daftar items wajib disertakan.']);
    exit();
}

$proposal_id = (int)$data->proposal_id;
$items = $data->items;

if (count($items) === 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Daftar items bahan baku kosong.']);
    exit();
}

$conn->begin_transaction();
try {
    // 1. Kelompokkan item berdasarkan supplier_id
    $supplier_groups = [];
    foreach ($items as $item) {
        $supplier_id = isset($item->suggested_supplier_id) ? (int)$item->suggested_supplier_id : 0;
        // Hanya proses item yang memiliki supplier
        if ($supplier_id <= 0) continue;

        if (!isset($supplier_groups[$supplier_id])) {
            $supplier_groups[$supplier_id] = [];
        }
        $supplier_groups[$supplier_id][] = $item;
    }

    if (count($supplier_groups) === 0) {
        throw new Exception("Tidak ada bahan baku dengan supplier valid untuk diproses PO-nya.");
    }

    $created_pos = [];

    // 2. Loop setiap supplier untuk membuat PO terpisah (split PO)
    foreach ($supplier_groups as $sup_id => $group_items) {
        $po_code = "PO-AUTO-" . time() . "-" . rand(100, 999);
        
        // Hitung total_amount
        $total_amount = 0;
        foreach ($group_items as $item) {
            $qty = (float)$item->deficit_qty;
            $price = (float)$item->suggested_price;
            $total_amount += $qty * $price;
        }

        // Simpan PO Utama
        $poSql = "INSERT INTO purchase_orders (organization_id, po_code, proposal_id, supplier_id, total_amount, status, vendor_status) VALUES (?, ?, ?, ?, ?, 'Dikirim', 'Menunggu Konfirmasi')";
        $poStmt = $conn->prepare($poSql);
        $poStmt->bind_param("isiid", $org_id, $po_code, $proposal_id, $sup_id, $total_amount);
        
        if (!$poStmt->execute()) {
            throw new Exception("Gagal membuat data PO utama: " . $poStmt->error);
        }
        $po_id = $poStmt->insert_id;
        $poStmt->close();

        // Simpan PO Items
        $itemSql = "INSERT INTO po_items (organization_id, po_id, ingredient_id, quantity, price_per_unit, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
        $itemStmt = $conn->prepare($itemSql);

        foreach ($group_items as $item) {
            $ing_id = (int)$item->ingredient_id;
            $qty = (float)$item->deficit_qty;
            $price = (float)$item->suggested_price;
            $subtotal = $qty * $price;

            $itemStmt->bind_param("iiiddd", $org_id, $po_id, $ing_id, $qty, $price, $subtotal);
            if (!$itemStmt->execute()) {
                throw new Exception("Gagal menyimpan item PO: " . $itemStmt->error);
            }
        }
        $itemStmt->close();

        $created_pos[] = [
            'po_id' => $po_id,
            'po_code' => $po_code,
            'total_amount' => $total_amount,
            'items_count' => count($group_items)
        ];
    }

    $conn->commit();

    http_response_code(201);
    echo json_encode([
        'message' => 'PO otomatis berhasil dibuat secara terpisah.',
        'created_pos' => $created_pos
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
