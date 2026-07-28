<?php
// File: app/supplier_toggle_local.php
// Deskripsi: Mengubah status supplier (Sentra terpusat <-> Lokal Mandiri) untuk dapur bersangkutan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->supplier_id) || !isset($data->action)) {
    http_response_code(400);
    echo json_encode(['message' => 'Supplier ID dan Aksi wajib diisi.']);
    exit();
}

$supplier_id = (int)$data->supplier_id;
$action = trim($data->action); // make_local atau make_marketplace

try {
    // 1. Cek keberadaan supplier di dapur ini
    $sql = "SELECT id, marketplace_id, supplier_name FROM suppliers WHERE id = ? AND organization_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $supplier_id, $org_id);
    $stmt->execute();
    $supplier = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$supplier) {
        throw new Exception("Supplier tidak ditemukan atau Anda tidak memiliki akses.", 404);
    }

    if ($action === 'make_local') {
        if (!$supplier['marketplace_id']) {
            throw new Exception("Supplier ini memang merupakan supplier lokal manual.", 400);
        }

        // Set is_local_override = 1
        $updateSql = "UPDATE suppliers SET is_local_override = 1 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $supplier_id);
        $updateStmt->execute();
        $updateStmt->close();

        echo json_encode([
            'message' => "Supplier '{$supplier['supplier_name']}' berhasil diubah menjadi Supplier Lokal. Pembaruan harga dan profil kini dikelola dapur sepenuhnya.",
            'is_local_override' => 1
        ]);

    } elseif ($action === 'make_marketplace') {
        if (!$supplier['marketplace_id']) {
            throw new Exception("Supplier ini tidak terhubung ke Sentra IntiGizi.", 400);
        }

        $conn->begin_transaction();

        // 1. Matikan override local (is_local_override = 0)
        $updateSql = "UPDATE suppliers SET is_local_override = 0 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("i", $supplier_id);
        $updateStmt->execute();
        $updateStmt->close();

        $conn->commit();

        // 2. Lakukan sync ulang katalog & profil dari Marketplace secara terpusat
        $marketplace_id = (int)$supplier['marketplace_id'];
        
        // Set mock request payload agar dibaca oleh sync_marketplace_supplier.php
        $data = new stdClass();
        $data->marketplace_id = $marketplace_id;
        
        // Nonaktifkan header JSON output default dari sync_marketplace_supplier agar respons dikendalikan di sini
        ob_start();
        require __DIR__ . '/sync_marketplace_supplier.php';
        ob_end_clean();

        echo json_encode([
            'message' => "Supplier '{$supplier['supplier_name']}' berhasil dikoneksikan kembali ke Sentra IntiGizi. Data profil dan katalog diperbarui otomatis.",
            'is_local_override' => 0
        ]);
    } else {
        throw new Exception("Aksi tidak didukung.", 400);
    }

} catch (Throwable $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode(['message' => $e->getMessage()]);
}
?>
