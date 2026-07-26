<?php
// File: app/purchase_orders_create_manual.php
// PENJELASAN: API untuk membuat PO Manual dengan fitur Auto-Split PO berdasarkan supplier per item.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Daftar item belanja (items) wajib disertakan.']);
    exit();
}

$items = $data['items'];
$apply_ppn = isset($data['apply_ppn']) ? (bool)$data['apply_ppn'] : false;
$apply_pph = isset($data['apply_pph']) ? (bool)$data['apply_pph'] : false;

$conn->begin_transaction();

try {
    // 1. Kelompokkan item berdasarkan supplier_id
    $supplier_groups = [];
    foreach ($items as $index => $item) {
        if (empty($item['supplier_id'])) {
            throw new Exception("Item ke-" . ($index + 1) . " belum memilih supplier.");
        }
        if (empty($item['ingredient_id'])) {
            throw new Exception("Item ke-" . ($index + 1) . " belum memilih bahan baku.");
        }
        if (!isset($item['quantity']) || (float)$item['quantity'] <= 0) {
            throw new Exception("Item ke-" . ($index + 1) . " harus memiliki jumlah lebih dari 0.");
        }
        if (!isset($item['price_per_unit']) || (float)$item['price_per_unit'] < 0) {
            throw new Exception("Item ke-" . ($index + 1) . " harus memiliki harga satuan yang valid.");
        }

        $sup_id = (int)$item['supplier_id'];
        if (!isset($supplier_groups[$sup_id])) {
            $supplier_groups[$sup_id] = [];
        }
        $supplier_groups[$sup_id][] = $item;
    }

    $created_pos = [];

    // 2. Buat PO terpisah untuk setiap supplier group (Auto-Split PO)
    foreach ($supplier_groups as $sup_id => $group_items) {
        $total_amount = 0;
        $processedGroupItems = [];

        foreach ($group_items as $item) {
            $qty = (float)$item['quantity'];
            $price = (float)$item['price_per_unit'];
            $ing_id = (int)$item['ingredient_id'];
            $subtotal = $qty * $price;

            $total_amount += $subtotal;
            $processedGroupItems[] = [
                'ingredient_id' => $ing_id,
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $subtotal
            ];
        }

        // Hitung pajak secara proporsional per PO
        $tax_ppn = $apply_ppn ? Math.round($total_amount * 0.11) : 0.00;
        $tax_pph = $apply_pph ? Math.round($total_amount * 0.015) : 0.00;
        $net_amount = $total_amount + $tax_ppn - $tax_pph;

        $po_code = "PO-MANUAL-" . date("Ymd") . "-" . strtoupper(substr(md5(time() . $sup_id . rand(100, 999)), 0, 5));

        // Belanja manual langsung selesai
        $poSql = "INSERT INTO purchase_orders (organization_id, po_code, proposal_id, supplier_id, total_amount, tax_ppn, tax_pph, net_amount, status) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'Selesai')";
        $poStmt = $conn->prepare($poSql);
        $poStmt->bind_param("isidddd", $org_id, $po_code, $sup_id, $total_amount, $tax_ppn, $tax_pph, $net_amount);
        
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

        // Kirim notifikasi ke supplier terkait
        $vendor_user_id = null;
        $vendor_org_id = null;

        $vendorCheckSql = "SELECT u.id, u.organization_id FROM users u JOIN organizations o ON u.organization_id = o.id WHERE o.id = ? AND o.registration_type = 'Vendor' AND u.role_id = 5 LIMIT 1";
        $vendorStmt = $conn->prepare($vendorCheckSql);
        $vendorStmt->bind_param("i", $sup_id);
        $vendorStmt->execute();
        if ($vendorRow = $vendorStmt->get_result()->fetch_assoc()) {
            $vendor_user_id = $vendorRow['id'];
            $vendor_org_id = $vendorRow['organization_id'];
        }
        $vendorStmt->close();

        if (!$vendor_user_id) {
            $supplierCheckSql = "SELECT user_id, organization_id FROM suppliers WHERE id = ?";
            $supplierStmt = $conn->prepare($supplierCheckSql);
            $supplierStmt->bind_param("i", $sup_id);
            $supplierStmt->execute();
            if ($supplierRow = $supplierStmt->get_result()->fetch_assoc()) {
                $vendor_user_id = $supplierRow['user_id'];
                $vendor_org_id = $supplierRow['organization_id'];
            }
            $supplierStmt->close();
        }
        
        if ($vendor_user_id && $vendor_org_id) {
            send_notification($conn, $vendor_org_id, $vendor_user_id, "Pesanan Baru (Manual): {$po_code}", "Anda menerima pesanan baru yang dicatat langsung oleh dapur.", "/app/vendor/orders");
        }

        $created_pos[] = [
            'po_id' => $po_id,
            'po_code' => $po_code,
            'supplier_name' => isset($group_items[0]['suggested_supplier_name']) ? $group_items[0]['suggested_supplier_name'] : 'Supplier',
            'total_amount' => $total_amount
        ];
    }

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

            if ($po_detail) {
                sync_po_to_marketplace($conn, $po['po_id'], $org_id, (int)$po_detail['supplier_id']);
            }
        }
    } catch (Throwable $sync_err) {
        // Biarkan gagal tanpa menggagalkan pengembalian sukses utama
    }

    $conn->commit();
    
    // Bentuk pesan sukses informatif
    $po_codes_str = implode(', ', array_column($created_pos, 'po_code'));
    http_response_code(201);
    echo json_encode([
        'message' => 'PO manual berhasil dibuat dan di-split otomatis berdasarkan supplier: ' . $po_codes_str,
        'created_pos' => $created_pos
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
