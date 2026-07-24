<?php
// File: app/purchase_order_update_status.php
// Penjelasan: Diperbarui untuk menangani input JSON dan FormData, serta notifikasi.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';
require_once __DIR__ . '/helpers/financial_helper.php'; // 1. Sertakan financial helper

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

// --- PERBAIKAN UTAMA DI SINI ---
// Logika untuk menangani dua jenis input: JSON (untuk aksi) dan FormData (untuk upload file).
$action = '';
$po_id = 0;

// Cek tipe konten yang dikirim oleh client
if (stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $data = json_decode(file_get_contents("php://input"));
    $action = $data->action ?? '';
    $po_id = isset($data->po_id) ? (int)$data->po_id : 0;
} else {
    // Jika bukan JSON, asumsikan itu adalah FormData (multipart/form-data) dari upload file.
    $action = $_POST['action'] ?? '';
    $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
}
// --- AKHIR PERBAIKAN ---

if (empty($action) || $po_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Aksi dan ID Purchase Order wajib diisi.']);
    exit();
}

$conn->begin_transaction();
try {
    // Mengunci baris PO untuk mencegah race condition saat update
    $poSql = "SELECT * FROM purchase_orders WHERE id = ? AND organization_id = ? FOR UPDATE";
    $poStmt = $conn->prepare($poSql);
    $poStmt->bind_param("ii", $po_id, $org_id);
    $poStmt->execute();
    $po = $poStmt->get_result()->fetch_assoc();
    $poStmt->close();

    if (!$po) {
        throw new Exception("Purchase Order tidak ditemukan atau Anda tidak memiliki akses.", 404);
    }
    
    $message = '';
    $notification_title = '';
    $notification_message = '';
    $notification_link = "/app/vendor/orders/{$po['id']}"; // Link default untuk vendor
    $notify_vendor = false;

    switch ($action) {
        case 'approve_prices':
            if ($po['status'] !== 'Menunggu Persetujuan Harga') {
                throw new Exception("Aksi tidak valid untuk status PO saat ini.", 409);
            }
            // Finalisasi harga: salin harga dan subtotal vendor ke kolom utama
            $finalizeSql = "UPDATE po_items SET price_per_unit = vendor_price_per_unit, subtotal = vendor_subtotal WHERE po_id = ? AND vendor_price_per_unit IS NOT NULL";
            $finalizeStmt = $conn->prepare($finalizeSql);
            $finalizeStmt->bind_param("i", $po_id);
            $finalizeStmt->execute();
            $finalizeStmt->close();
            
            $updateSql = "UPDATE purchase_orders SET status = 'Siap Dibayar', vendor_status = 'Disetujui Dapur' WHERE id = ?";
            $message = "Harga dari vendor telah disetujui.";
            
            $notification_title = "Penawaran Harga Disetujui";
            $notification_message = "Dapur telah menyetujui penawaran harga Anda untuk PO {$po['po_code']}.";
            $notify_vendor = true;
            break;
            
        case 'reject_prices':
            if ($po['status'] !== 'Menunggu Persetujuan Harga') {
                throw new Exception("Aksi tidak valid untuk status PO saat ini.", 409);
            }
            // Reset penawaran vendor
            $resetSql = "UPDATE po_items SET vendor_price_per_unit = NULL, vendor_subtotal = NULL WHERE po_id = ?";
            $resetStmt = $conn->prepare($resetSql);
            $resetStmt->bind_param("i", $po_id);
            $resetStmt->execute();
            $resetStmt->close();
            
            // Hitung ulang total amount ke harga asli
            $recalcSql = "SELECT SUM(subtotal) as original_total FROM po_items WHERE po_id = ?";
            $recalcStmt = $conn->prepare($recalcSql);
            $recalcStmt->bind_param("i", $po_id);
            $recalcStmt->execute();
            $original_total = $recalcStmt->get_result()->fetch_assoc()['original_total'];
            $recalcStmt->close();

            $updateSql = "UPDATE purchase_orders SET vendor_status = 'Menunggu Konfirmasi', status = 'Diverifikasi', total_amount = ? WHERE id = ?";
            $params = [$original_total, $po_id];
            $types = "di";
            $message = "Penawaran harga dari vendor telah ditolak.";

            $notification_title = "Penawaran Harga Ditolak";
            $notification_message = "Dapur menolak penawaran harga Anda untuk PO {$po['po_code']}. Silakan ajukan penawaran harga baru.";
            $notify_vendor = true;
            break;

        case 'upload_payment_proof':
             if ($po['status'] !== 'Siap Dibayar') {
                throw new Exception("Aksi tidak valid untuk status PO saat ini.", 409);
            }
            if (!isset($_FILES['payment_proof'])) {
                throw new Exception("File bukti pembayaran tidak ditemukan.", 400);
            }
            
            $target_dir = __DIR__ . "/../uploads/payment_proofs/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            $file_ext = strtolower(pathinfo($_FILES["payment_proof"]["name"], PATHINFO_EXTENSION));
            $new_filename = "payment_{$po_id}_" . time() . "." . $file_ext;
            
            if (move_uploaded_file($_FILES["payment_proof"]["tmp_name"], $target_dir . $new_filename)) {
                $file_path = "/uploads/payment_proofs/" . $new_filename;
                $updateSql = "UPDATE purchase_orders SET status = 'Pembayaran Terkirim', payment_proof_path = ? WHERE id = ?";
                $params = [$file_path, $po_id];
                $types = "si";
                $message = "Bukti pembayaran berhasil diunggah.";

                $notification_title = "Pembayaran Diterima";
                $notification_message = "Dapur telah mengirimkan bukti pembayaran untuk PO {$po['po_code']}. Silakan verifikasi dan unggah invoice.";
                $notify_vendor = true;
            } else {
                throw new Exception("Gagal mengunggah file.");
            }
            break;
            
        case 'complete_order':
            if ($po['status'] !== 'Pembayaran Terkirim' || empty($po['invoice_path'])) {
                throw new Exception("Pesanan belum dapat diselesaikan. Pastikan pembayaran sudah terkirim dan invoice sudah diterima.", 409);
            }
            
            $updateSql = "UPDATE purchase_orders SET status = 'Selesai' WHERE id = ?";
            $message = "Pesanan telah diselesaikan, stok dan jurnal keuangan telah diperbarui.";

            // Logika penambahan stok
            $itemSql = "SELECT pi.ingredient_id, pi.quantity, u.conversion_factor FROM po_items pi JOIN ingredients i ON pi.ingredient_id = i.id JOIN units u ON i.unit_id = u.id WHERE pi.po_id = ? AND pi.organization_id = ?";
            $itemStmt = $conn->prepare($itemSql);
            $itemStmt->bind_param("ii", $po_id, $org_id);
            $itemStmt->execute();
            $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemStmt->close();

            if (!empty($items)) {
                $stockSql = "INSERT INTO stock (organization_id, ingredient_id, current_quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE current_quantity = current_quantity + VALUES(current_quantity)";
                $stockStmt = $conn->prepare($stockSql);
                $transSql = "INSERT INTO stock_transactions (organization_id, ingredient_id, type, quantity, notes, po_id) VALUES (?, ?, 'Masuk', ?, ?, ?)";
                $transStmt = $conn->prepare($transSql);
                $notes = "Stok masuk dari PO " . $po['po_code'];

                foreach ($items as $item) {
                    $quantity_in_base_unit = (float)$item['quantity'] * (float)$item['conversion_factor'];
                    $stockStmt->bind_param("iid", $org_id, $item['ingredient_id'], $quantity_in_base_unit);
                    $stockStmt->execute();
                    $transStmt->bind_param("iidsi", $org_id, $item['ingredient_id'], $quantity_in_base_unit, $notes, $po_id);
                    $transStmt->execute();
                }
                $stockStmt->close();
                $transStmt->close();
            }

            // --- 2. LOGIKA BARU: PENCATATAN JURNAL KEUANGAN ---
            // Saat pesanan selesai, catat sebagai pengeluaran dari Kas/Bank ke Biaya Bahan Baku.
            // Asumsi sumber dana adalah dari 'Kas di Bank' (ID Akun = 2).
            // Ini bisa disesuaikan jika ada pilihan sumber dana di frontend.
            $source_account_id = 2; // ID Akun 'Kas di Bank'
            $expense_account_id = 4; // ID Akun 'Biaya Bahan Baku'
            
            record_transaction(
                $conn,
                $org_id,
                date('Y-m-d'), // Tanggal transaksi adalah hari ini
                "Pembelian bahan baku sesuai PO " . $po['po_code'],
                $expense_account_id,     // Debet: Biaya Bahan Baku (bertambah)
                $source_account_id,      // Kredit: Kas di Bank (berkurang)
                (float)$po['total_amount'],
                $user_id,
                $po_id
            );
            // --- AKHIR LOGIKA BARU ---

            $notification_title = "Pesanan Selesai";
            $notification_message = "Dapur telah mengonfirmasi penerimaan barang untuk PO {$po['po_code']}. Transaksi selesai.";
            $notify_vendor = true;
            break;

        default:
            throw new Exception("Aksi tidak dikenal.", 400);
    }

    if (isset($updateSql)) {
        $stmt = $conn->prepare($updateSql);
        if (isset($params) && isset($types)) {
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param("i", $po_id);
        }
        $stmt->execute();
        $stmt->close();
    }
    
    if ($notify_vendor && $po['supplier_id']) {
        $vendorUser = null;
        $orgSql = "SELECT id FROM organizations WHERE id = ? AND registration_type = 'Vendor'";
        $orgStmt = $conn->prepare($orgSql);
        $orgStmt->bind_param("i", $po['supplier_id']);
        $orgStmt->execute();
        if ($orgStmt->get_result()->num_rows > 0) {
            $userSql = "SELECT id, organization_id FROM users WHERE organization_id = ? AND role_id = 5 LIMIT 1";
            $userStmt = $conn->prepare($userSql);
            $userStmt->bind_param("i", $po['supplier_id']);
            $userStmt->execute();
            $vendorUser = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
        }
        $orgStmt->close();

        if (!$vendorUser) {
            $supSql = "SELECT user_id, organization_id FROM suppliers WHERE id = ?";
            $supStmt = $conn->prepare($supSql);
            $supStmt->bind_param("i", $po['supplier_id']);
            $supStmt->execute();
            $supRes = $supStmt->get_result()->fetch_assoc();
            if ($supRes) {
                $vendorUser = ['id' => $supRes['user_id'], 'organization_id' => $supRes['organization_id']];
            }
            $supStmt->close();
        }

        if ($vendorUser) {
            send_notification($conn, $vendorUser['organization_id'], $vendorUser['id'], $notification_title, $notification_message, $notification_link);
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

