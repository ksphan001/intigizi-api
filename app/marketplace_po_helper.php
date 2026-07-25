<?php
// File: app/marketplace_po_helper.php
// Penjelasan: Helper untuk mendaftarkan/mensinkronkan PO lokal ke Marketplace terpusat jika supplier terhubung.

function sync_po_to_marketplace($conn, $po_id, $org_id, $supplier_id) {
    try {
        // 1. Cek apakah supplier ini adalah supplier dari marketplace (memiliki marketplace_id)
        $supSql = "SELECT marketplace_id FROM suppliers WHERE id = ? LIMIT 1";
        $supStmt = $conn->prepare($supSql);
        $supStmt->bind_param("i", $supplier_id);
        $supStmt->execute();
        $sup = $supStmt->get_result()->fetch_assoc();
        $supStmt->close();

        if (!$sup || empty($sup['marketplace_id'])) {
            return false; // Bukan supplier marketplace
        }

        $marketplace_supplier_id = (int)$sup['marketplace_id'];

        // 2. Ambil detail PO utama
        $poSql = "SELECT po_code, total_amount FROM purchase_orders WHERE id = ? LIMIT 1";
        $poStmt = $conn->prepare($poSql);
        $poStmt->bind_param("i", $po_id);
        $poStmt->execute();
        $po = $poStmt->get_result()->fetch_assoc();
        $poStmt->close();

        if (!$po) return false;

        // 3. Ambil nama & koordinat Dapur
        $orgSql = "SELECT name, vendor_address, latitude, longitude FROM organizations WHERE id = ? LIMIT 1";
        $orgStmt = $conn->prepare($orgSql);
        $orgStmt->bind_param("i", $org_id);
        $orgStmt->execute();
        $org = $orgStmt->get_result()->fetch_assoc();
        $orgStmt->close();

        $kitchen_name = $org['name'] ?? "Dapur Gizi #" . $org_id;
        $kitchen_address = $org['vendor_address'] ?? "";
        $kitchen_lat = $org['latitude'] ?? null;
        $kitchen_lng = $org['longitude'] ?? null;

        // 4. Ambil items PO
        $itemsSql = "SELECT i.name as ingredient_name, pi.quantity, pi.price_per_unit, u.symbol as unit_symbol 
                     FROM po_items pi
                     JOIN ingredients i ON pi.ingredient_id = i.id
                     JOIN units u ON i.unit_id = u.id
                     WHERE pi.po_id = ?";
        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->bind_param("i", $po_id);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        // 5. Definisikan Webhook URL untuk sinkronisasi status pengantaran dari marketplace
        // Diarahkan ke host localhost / intigizi-api
        $webhook_url = "http://localhost/intigizi-api/app/webhook_po_status.php";

        // 6. Kirim data ke API Marketplace terpusat
        $ch = curl_init("http://intigizi-supplier-api.test/app/marketplace_orders.php");
        $payload = json_encode([
            'po_code' => $po['po_code'],
            'supplier_id' => $marketplace_supplier_id,
            'kitchen_name' => $kitchen_name,
            'kitchen_address' => $kitchen_address,
            'kitchen_latitude' => $kitchen_lat,
            'kitchen_longitude' => $kitchen_lng,
            'total_amount' => $po['total_amount'],
            'webhook_url' => $webhook_url,
            'items' => $items
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response ? true : false;
    } catch (Throwable $e) {
        // Biarkan gagal tanpa merusak alur transaksi utama PO
        return false;
    }
}
?>
