<?php
// File: app/purchase_order_assign_supplier.php
// PENJELASAN: Diperbarui untuk mengirim notifikasi ke vendor/supplier yang ditugaskan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php'; // Sertakan mesin notifikasi

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->po_id) || !isset($data->supplier_id)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Purchase Order dan ID Supplier wajib diisi.']);
    exit();
}

$po_id = (int)$data->po_id;
$supplier_id = (int)$data->supplier_id;

$conn->begin_transaction();
try {
    // 1. Ambil info PO untuk validasi dan notifikasi
    $checkSql = "SELECT status, po_code FROM purchase_orders WHERE id = ? AND organization_id = ? FOR UPDATE";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $po_id, $org_id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('Purchase Order tidak ditemukan atau Anda tidak memiliki akses.', 404);
    }
    $po = $result->fetch_assoc();
    $checkStmt->close();

    if (in_array($po['status'], ['Dibayar', 'Selesai', 'Ditolak Vendor'])) {
        throw new Exception('Supplier tidak dapat diubah karena PO sudah dalam proses final.', 403);
    }

    // 2. Lanjutkan update
    $sql = "UPDATE purchase_orders SET supplier_id = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $supplier_id, $po_id, $org_id);

    if ($stmt->execute()) {
        // --- LOGIKA NOTIFIKASI BARU ---
        $vendor_user_id = null;
        $vendor_org_id = null;

        // Cek apakah supplier_id adalah Vendor eksternal
        $vendorCheckSql = "SELECT u.id, u.organization_id FROM users u JOIN organizations o ON u.organization_id = o.id WHERE o.id = ? AND o.registration_type = 'Vendor' AND u.role_id = 5 LIMIT 1";
        $vendorStmt = $conn->prepare($vendorCheckSql);
        $vendorStmt->bind_param("i", $supplier_id);
        $vendorStmt->execute();
        $vendorResult = $vendorStmt->get_result();
        if ($vendorRow = $vendorResult->fetch_assoc()) {
            $vendor_user_id = $vendorRow['id'];
            $vendor_org_id = $vendorRow['organization_id'];
        }
        $vendorStmt->close();

        // Jika bukan, cek apakah supplier_id adalah Supplier internal
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

        // Kirim notifikasi jika user ditemukan
        if ($vendor_user_id && $vendor_org_id) {
            send_notification(
                $conn,
                $vendor_org_id,
                $vendor_user_id,
                "Pesanan Baru untuk Anda: {$po['po_code']}",
                "Anda menerima pesanan baru yang perlu ditinjau.",
                "/app/vendor/orders"
            );
        }
        // --- AKHIR LOGIKA NOTIFIKASI ---

        $conn->commit();
        http_response_code(200);
        echo json_encode(['message' => 'Supplier berhasil ditetapkan dan notifikasi telah dikirim.']);

    } else {
        throw new Exception('Gagal memperbarui data PO.', 500);
    }
    $stmt->close();

} catch (Exception $e) {
    $conn->rollback();
    $errorCode = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($errorCode);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
