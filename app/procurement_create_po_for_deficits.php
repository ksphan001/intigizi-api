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
    // 1. Kelompokkan item berdasarkan supplier_id (0 berarti Belanja Mandiri / Tunai)
    $supplier_groups = [];
    
    foreach ($items as $item) {
        $supplier_id = isset($item->suggested_supplier_id) ? (int)$item->suggested_supplier_id : 0;
        if ($supplier_id <= 0) {
            $supplier_id = 0; // Masukkan ke kelompok Belanja Mandiri
        }

        if (!isset($supplier_groups[$supplier_id])) {
            $supplier_groups[$supplier_id] = [];
        }
        $supplier_groups[$supplier_id][] = $item;
    }

    $created_pos = [];

    // 2. Loop setiap kelompok supplier untuk membuat PO terpisah (split PO)
    foreach ($supplier_groups as $sup_id => $group_items) {
        $supplier_id_db = $sup_id > 0 ? $sup_id : null;
        
        $po_code = $supplier_id_db === null 
            ? "PO-CASH-" . date("Ymd") . "-" . strtoupper(substr(md5(time() . $proposal_id . rand(10, 99)), 0, 5))
            : "PO-AUTO-" . time() . "-" . rand(100, 999);
            
        $status = $supplier_id_db === null ? 'Selesai' : 'Dikirim';
        $vendor_status = $supplier_id_db === null ? null : 'Menunggu Konfirmasi';
        
        // Hitung total_amount
        $total_amount = 0;
        $processedGroupItems = [];
        foreach ($group_items as $item) {
            $qty = (float)$item->deficit_qty;
            $price = (float)$item->suggested_price;
            $ing_id = (int)$item->ingredient_id;

            if ($supplier_id_db !== null) {
                $priceSql = "SELECT base_price, tier_qty, tier_price FROM supplier_ingredients WHERE supplier_id = ? AND ingredient_id = ? LIMIT 1";
                $priceStmt = $conn->prepare($priceSql);
                $priceStmt->bind_param("ii", $supplier_id_db, $ing_id);
                $priceStmt->execute();
                $priceRes = $priceStmt->get_result()->fetch_assoc();
                $priceStmt->close();

                if ($priceRes) {
                    $base_price = (float)$priceRes['base_price'];
                    $tier_qty = (float)$priceRes['tier_qty'];
                    $tier_price = (float)$priceRes['tier_price'];

                    if ($tier_qty > 0 && $qty >= $tier_qty && $tier_price > 0) {
                        $price = $tier_price;
                    } else {
                        $price = $base_price;
                    }
                }
            }

            $subtotal = $qty * $price;
            $total_amount += $subtotal;

            $processedGroupItems[] = [
                'ingredient_id' => $ing_id,
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $subtotal
            ];
        }

        // Simpan PO Utama
        $poSql = "INSERT INTO purchase_orders (organization_id, po_code, proposal_id, supplier_id, total_amount, status, vendor_status) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $poStmt = $conn->prepare($poSql);
        $poStmt->bind_param("isiidss", $org_id, $po_code, $proposal_id, $supplier_id_db, $total_amount, $status, $vendor_status);
        
        if (!$poStmt->execute()) {
            throw new Exception("Gagal membuat data PO utama: " . $poStmt->error);
        }
        $po_id = $poStmt->insert_id;
        $poStmt->close();

        // Simpan PO Items
        $itemSql = "INSERT INTO po_items (organization_id, po_id, ingredient_id, quantity, price_per_unit, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
        $itemStmt = $conn->prepare($itemSql);

        foreach ($processedGroupItems as $item) {
            $itemStmt->bind_param("iiiddd", $org_id, $po_id, $item['ingredient_id'], $item['qty'], $item['price'], $item['subtotal']);
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

    // --- SINKRONISASI B2B MARKETPLACE ---
    try {
        require_once __DIR__ . '/marketplace_po_helper.php';
        foreach ($created_pos as $po) {
            $supSql = "SELECT supplier_id FROM purchase_orders WHERE id = ? LIMIT 1";
            $supStmt = $conn->prepare($supSql);
            $supStmt->bind_param("i", $po['po_id']);
            $supStmt->execute();
            $po_detail = $supStmt->get_result()->fetch_assoc();
            $supStmt->close();

            if ($po_detail && !empty($po_detail['supplier_id'])) {
                sync_po_to_marketplace($conn, $po['po_id'], $org_id, (int)$po_detail['supplier_id']);
            }
        }
    } catch (Throwable $sync_err) {
        // Biarkan gagal tanpa menggagalkan pengembalian sukses utama
    }

    $message = 'Proses PO otomatis (termasuk Belanja Mandiri) berhasil dibuat secara terpisah.';

    http_response_code(201);
    echo json_encode([
        'message' => $message,
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
