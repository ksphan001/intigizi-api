<?php
// File: app/purchase_order_items_get.php
// Penjelasan: Diperbaiki untuk memastikan Vendor/Supplier dapat melihat item PO
// dengan mengambil data bahan baku dari organisasi dapur yang benar.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;

if ($po_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Purchase Order (po_id) wajib disertakan.']);
    exit();
}

try {
    // Tentukan ID supplier yang berhak (bisa dari org_id untuk Vendor, atau suppliers.id untuk internal)
    $effective_supplier_id = 0;
    if ($userData['role_id'] == 5) {
        if (isset($userData['org_type']) && $userData['org_type'] === 'Vendor') {
            $effective_supplier_id = $org_id;
        } else {
            $supplierIdSql = "SELECT id FROM suppliers WHERE user_id = ?";
            $supplierIdStmt = $conn->prepare($supplierIdSql);
            $supplierIdStmt->bind_param("i", $user_id);
            $supplierIdStmt->execute();
            $supplierResult = $supplierIdStmt->get_result()->fetch_assoc();
            if ($supplierResult) {
                $effective_supplier_id = (int)$supplierResult['id'];
            }
            $supplierIdStmt->close();
        }
    }

    // --- PERBAIKAN UTAMA DI SINI ---
    // Logika query dirombak total.
    // 1. JOIN ke `purchase_orders` (po) untuk melakukan pengecekan hak akses.
    // 2. JOIN ke `ingredients` (i) menggunakan `pi.organization_id` untuk memastikan data bahan baku diambil dari organisasi dapur yang benar.
    $sql = "SELECT
                pi.id, pi.ingredient_id, i.name as ingredient_name,
                pi.quantity, pi.price_per_unit, pi.vendor_price_per_unit, pi.subtotal,
                u.symbol as unit_symbol
            FROM po_items pi
            JOIN purchase_orders po ON pi.po_id = po.id
            JOIN ingredients i ON pi.ingredient_id = i.id AND i.organization_id = pi.organization_id
            JOIN units u ON i.unit_id = u.id
            WHERE pi.po_id = ? AND (po.organization_id = ? OR po.supplier_id = ?)
            ORDER BY i.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $po_id, $org_id, $effective_supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        $items = $result->fetch_all(MYSQLI_ASSOC);
        // Jika tidak ada item yang ditemukan, mungkin karena PO tidak dimiliki.
        // Cek kepemilikan PO secara terpisah untuk memberikan pesan error yang lebih jelas.
        if (empty($items)) {
            $checkOwnerSql = "SELECT id FROM purchase_orders WHERE id = ? AND (organization_id = ? OR supplier_id = ?)";
            $checkStmt = $conn->prepare($checkOwnerSql);
            $checkStmt->bind_param("iii", $po_id, $org_id, $effective_supplier_id);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Anda tidak memiliki akses ke pesanan ini.']);
                $checkStmt->close();
                $conn->close();
                exit();
            }
            $checkStmt->close();
        }
        echo json_encode($items);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Query ke database gagal.']);
    }

    $stmt->close();
    $conn->close();

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
}
?>

