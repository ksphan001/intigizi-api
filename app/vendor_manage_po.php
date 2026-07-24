<?php
// File: app/vendor_manage_po.php
// Penjelasan: PERBAIKAN DEFINITIF. Logika dirombak total untuk menyimpan subtotal vendor
// dan memastikan kalkulasi total harga selalu benar, serta mengimplementasikan notifikasi.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$user_id = (int)$userData['id'];

if ($userData['role_id'] != 5) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));
$action = $data->action ?? '';
$po_id = isset($data->po_id) ? (int)$data->po_id : 0;

if (empty($action) || $po_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Aksi dan ID Purchase Order wajib diisi.']);
    exit();
}

$conn->begin_transaction();
try {
    $poSql = "SELECT * FROM purchase_orders WHERE id = ?";
    $poStmt = $conn->prepare($poSql);
    $poStmt->bind_param("i", $po_id);
    $poStmt->execute();
    $po = $poStmt->get_result()->fetch_assoc();
    $poStmt->close();

    if (!$po) throw new Exception("Purchase Order tidak ditemukan.", 404);
    
    $message = '';
    $notification_title = '';
    $notification_message = '';
    $notification_link = "/app/purchase-orders/{$po_id}";
    $notify_kitchen_staff = false;

    if ($action === 'submit_prices') {
        if ($po['vendor_status'] !== 'Menunggu Konfirmasi') {
            throw new Exception("Aksi tidak valid untuk status PO saat ini.", 409);
        }
        if (!isset($data->items) || !is_array($data->items)) {
            throw new Exception("Data item harga tidak valid.", 400);
        }

        // Ambil semua item dari DB untuk PO ini, di-map berdasarkan ingredient_id
        $itemDetailsSql = "SELECT id, ingredient_id, quantity FROM po_items WHERE po_id = ?";
        $itemDetailsStmt = $conn->prepare($itemDetailsSql);
        $itemDetailsStmt->bind_param("i", $po_id);
        $itemDetailsStmt->execute();
        $items_from_db_result = $itemDetailsStmt->get_result();
        $items_map = [];
        while($row = $items_from_db_result->fetch_assoc()){
            $items_map[$row['ingredient_id']] = $row;
        }
        $itemDetailsStmt->close();

        $new_total_amount = 0;
        $itemUpdateSql = "UPDATE po_items SET vendor_price_per_unit = ?, vendor_subtotal = ? WHERE id = ?";
        $itemStmt = $conn->prepare($itemUpdateSql);

        foreach ($data->items as $item_from_frontend) {
            $item_obj = (object)$item_from_frontend; // Pastikan bisa diakses sebagai objek
            $ingredient_id = (int)$item_obj->ingredient_id;
            
            if (isset($items_map[$ingredient_id])) {
                $db_item = $items_map[$ingredient_id];
                $vendor_price = (float)$item_obj->vendor_price;
                $quantity = (float)$db_item['quantity'];

                $vendor_subtotal = $quantity * $vendor_price;
                $new_total_amount += $vendor_subtotal;
                
                $itemStmt->bind_param("ddi", $vendor_price, $vendor_subtotal, $db_item['id']);
                $itemStmt->execute();
            }
        }
        $itemStmt->close();

        $updatePoSql = "UPDATE purchase_orders SET vendor_status = 'Menunggu Persetujuan Dapur', status = 'Menunggu Persetujuan Harga', total_amount = ? WHERE id = ?";
        $updatePoStmt = $conn->prepare($updatePoSql);
        $updatePoStmt->bind_param("di", $new_total_amount, $po_id);
        $updatePoStmt->execute();
        $updatePoStmt->close();

        $message = "Penawaran harga berhasil dikirim.";
        $notification_title = "Penawaran Harga Baru Diterima";
        $notification_message = "Vendor telah mengirimkan penawaran harga baru untuk PO {$po['po_code']}. Mohon untuk ditinjau.";
        $notify_kitchen_staff = true;

    } elseif ($action === 'reject') {
        if ($po['vendor_status'] !== 'Menunggu Konfirmasi') {
             throw new Exception("Aksi tidak valid untuk status PO saat ini.", 409);
        }
        $updatePoSql = "UPDATE purchase_orders SET vendor_status = 'Ditolak Vendor', status = 'Ditolak Vendor' WHERE id = ?";
        $updatePoStmt = $conn->prepare($updatePoSql);
        $updatePoStmt->bind_param("i", $po_id);
        $updatePoStmt->execute();
        $updatePoStmt->close();
        
        $message = "Pesanan telah ditolak.";
        $notification_title = "Pesanan Ditolak oleh Vendor";
        $notification_message = "Vendor telah menolak pesanan untuk PO {$po['po_code']}.";
        $notify_kitchen_staff = true;
    } else {
        throw new Exception("Aksi tidak dikenal.", 400);
    }
    
    if ($notify_kitchen_staff) {
        $kitchen_org_id = $po['organization_id'];
        $staffSql = "SELECT id FROM users WHERE organization_id = ? AND role_id IN (3, 7)"; 
        $staffStmt = $conn->prepare($staffSql);
        $staffStmt->bind_param("i", $kitchen_org_id);
        $staffStmt->execute();
        $staffs = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $staffStmt->close();
        
        foreach($staffs as $staff) {
            send_notification($conn, $kitchen_org_id, $staff['id'], $notification_title, $notification_message, $notification_link);
        }
    }

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => $message]);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>
