<?php
// File: app/webhook_po_status.php
// Penjelasan: Endpoint webhook penerima sinyal pembaruan status dari Marketplace terpusat.

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->po_code) || !isset($data->status)) {
    http_response_code(400);
    echo json_encode(['message' => 'Data po_code dan status wajib disertakan.']);
    exit();
}

$po_code = $conn->real_escape_string($data->po_code);
$status = $data->status; // pending, processing, shipped, delivered

try {
    // 1. Cek apakah PO exists di DB
    $checkSql = "SELECT id, status, vendor_status, organization_id, total_amount FROM purchase_orders WHERE po_code = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $po_code);
    $checkStmt->execute();
    $po = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$po) {
        http_response_code(404);
        echo json_encode(['message' => 'PO tidak ditemukan di database Dapur lokal.']);
        exit();
    }

    $po_id = (int)$po['id'];

    $conn->begin_transaction();

    // 2. Petakan status dari marketplace ke database dapur dan update delivery_status
    if ($status === 'processing') {
        $upSql = "UPDATE purchase_orders SET vendor_status = 'Disetujui Dapur', delivery_status = 'processing' WHERE id = ?";
        $upStmt = $conn->prepare($upSql);
        $upStmt->bind_param("i", $po_id);
        $upStmt->execute();
        $upStmt->close();
    } elseif ($status === 'shipped') {
        $upSql = "UPDATE purchase_orders SET vendor_status = 'Invoice Terkirim', delivery_status = 'shipped' WHERE id = ?";
        $upStmt = $conn->prepare($upSql);
        $upStmt->bind_param("i", $po_id);
        $upStmt->execute();
        $upStmt->close();
    } elseif ($status === 'delivered') {
        // Jika status "delivered", update status PO menjadi Selesai dan perbarui stok gizi dapur otomatis
        // a. Update PO status
        $upSql = "UPDATE purchase_orders SET status = 'Selesai', delivery_status = 'delivered' WHERE id = ?";
        $upStmt = $conn->prepare($upSql);
        $upStmt->bind_param("i", $po_id);
        $upStmt->execute();
        $upStmt->close();

        // b. Tambahkan kuantitas barang masuk ke dalam stok gudang dapur
        $itemsSql = "SELECT organization_id, ingredient_id, quantity FROM po_items WHERE po_id = ?";
        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->bind_param("i", $po_id);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        foreach ($items as $row) {
            $org_id = (int)$row['organization_id'];
            $ing_id = (int)$row['ingredient_id'];
            $qty = (float)$row['quantity'];

            // Upsert stok
            $stockSql = "INSERT INTO stock (organization_id, ingredient_id, current_quantity) 
                         VALUES (?, ?, ?) 
                         ON DUPLICATE KEY UPDATE current_quantity = current_quantity + ?";
            $stockStmt = $conn->prepare($stockSql);
            $stockStmt->bind_param("iidd", $org_id, $ing_id, $qty, $qty);
            $stockStmt->execute();
            $stockStmt->close();
        }

        // c. Pembukuan Keuangan Transaksi PO otomatis
        require_once __DIR__ . '/helpers/financial_helper.php';
        $org_id = (int)$po['organization_id'];
        $source_account_id = 2; // ID Akun 'Kas di Bank'
        $expense_account_id = 4; // ID Akun 'Biaya Bahan Baku'
        $system_user_id = 1; // ID user sistem default untuk webhook
        
        record_transaction(
            $conn,
            $org_id,
            date('Y-m-d'),
            "Pembelian bahan baku otomatis sesuai PO " . $po_code,
            $expense_account_id,     // Debet: Biaya Bahan Baku
            $source_account_id,      // Kredit: Kas di Bank
            (float)$po['total_amount'],
            $system_user_id,
            $po_id
        );
    }

    $conn->commit();

    http_response_code(200);
    echo json_encode(['message' => 'Status PO lokal berhasil diperbarui berdasarkan webhook.', 'po_code' => $po_code, 'new_status' => $status]);

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memproses webhook PO status.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
