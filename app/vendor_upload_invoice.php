<?php
// File: app/vendor_upload_invoice.php
// Penjelasan: Diperbarui untuk mengubah status dan mengirim notifikasi ke Dapur.

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

$po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
if ($po_id <= 0 || !isset($_FILES['invoice'])) {
    http_response_code(400);
    echo json_encode(['message' => 'ID PO dan file invoice wajib diisi.']);
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

    if (!$po || $po['status'] !== 'Pembayaran Terkirim') {
        throw new Exception("Tidak dapat mengunggah invoice untuk PO ini.", 403);
    }

    $target_dir = __DIR__ . "/../uploads/invoices/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    $file_ext = strtolower(pathinfo($_FILES["invoice"]["name"], PATHINFO_EXTENSION));
    $new_filename = "invoice_{$po_id}_" . time() . "." . $file_ext;

    if (move_uploaded_file($_FILES["invoice"]["tmp_name"], $target_dir . $new_filename)) {
        $file_path = "/uploads/invoices/" . $new_filename;
        
        // Update path invoice dan status vendor
        $updateSql = "UPDATE purchase_orders SET invoice_path = ?, vendor_status = 'Invoice Terkirim' WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $file_path, $po_id);
        $updateStmt->execute();
        $updateStmt->close();

        // Kirim notifikasi ke Dapur
        $kitchen_org_id = $po['organization_id'];
        $notification_title = "Invoice Diterima untuk PO {$po['po_code']}";
        $notification_message = "Vendor telah mengunggah invoice. Mohon konfirmasi penerimaan barang untuk menyelesaikan pesanan.";
        $notification_link = "/app/purchase-orders/{$po_id}";

        $staffSql = "SELECT id FROM users WHERE organization_id = ? AND role_id IN (3, 7)"; 
        $staffStmt = $conn->prepare($staffSql);
        $staffStmt->bind_param("i", $kitchen_org_id);
        $staffStmt->execute();
        $staffs = $staffStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $staffStmt->close();
        
        foreach($staffs as $staff) {
            send_notification($conn, $kitchen_org_id, $staff['id'], $notification_title, $notification_message, $notification_link);
        }

        $conn->commit();
        http_response_code(200);
        echo json_encode(['message' => 'Invoice berhasil diunggah.']);

    } else {
        throw new Exception("Gagal memindahkan file invoice.");
    }

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Terjadi kesalahan saat mengunggah invoice.', 'error' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>

