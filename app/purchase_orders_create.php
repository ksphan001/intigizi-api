<?php
// File: app/purchase_orders_create.php
// Penjelasan: Dirombak total untuk menerapkan logika BDD.
// Kebutuhan bahan baku (quantity) dihitung berdasarkan BERAT KOTOR.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/notification_engine.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    $data = json_decode(file_get_contents("php://input"));
    if (!isset($data->proposal_id)) {
        http_response_code(400);
        throw new Exception('ID proposal wajib diisi.');
    }
    $proposal_id = (int)$data->proposal_id;

    $conn->begin_transaction();

    // 1. Dapatkan total jumlah penerima per kategori & total keseluruhan
    $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count FROM distribution_point_counts dpc JOIN distribution_points dp ON dpc.distribution_point_id = dp.id WHERE dp.organization_id = ? GROUP BY dpc.category_id";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->bind_param("i", $org_id);
    $countsStmt->execute();
    $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $countsStmt->close();
    
    $beneficiary_counts = [];
    foreach($category_totals_result as $row) {
        $beneficiary_counts[$row['category_id']] = (int)$row['total_count'];
    }
    
    // 2. Dapatkan resep dari semua menu di proposal (TERMASUK DATA BDD)
    // --- PERUBAHAN: JOIN nutrition_data untuk ambil bdd_percentage ---
    $recipesSql = "SELECT 
                       mi.ingredient_id, mi.quantity_per_portion, 
                       i.latest_price, u.conversion_factor,
                       nd.bdd_percentage
                   FROM proposal_menus pm
                   JOIN menu_ingredients mi ON pm.menu_id = mi.menu_id AND mi.organization_id = pm.organization_id
                   JOIN ingredients i ON mi.ingredient_id = i.id AND i.organization_id = pm.organization_id
                   JOIN units u ON i.unit_id = u.id
                   LEFT JOIN nutrition_data nd ON i.id = nd.ingredient_id AND i.organization_id = nd.organization_id
                   WHERE pm.proposal_id = ? AND pm.organization_id = ? AND pm.menu_id != 1";
                   
    $recipesStmt = $conn->prepare($recipesSql);
    $recipesStmt->bind_param("ii", $proposal_id, $org_id);
    $recipesStmt->execute();
    $recipesResult = $recipesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recipesStmt->close();

    if (empty($recipesResult)) {
        throw new Exception('Tidak ada bahan baku di proposal ini, proposal belum disetujui, atau Anda tidak memiliki akses.', 400);
    }
    
    // 3. Kalkulasi total kebutuhan bahan baku (BERAT KOTOR)
    $ingredientNeeds = [];
    foreach ($recipesResult as $ing) {
        $ing_id = $ing['ingredient_id'];
        if (!isset($ingredientNeeds[$ing_id])) {
            $ingredientNeeds[$ing_id] = [
                'total_grams_gross' => 0, // --- PERUBAHAN: Hitung berat kotor ---
                'latest_price' => (float)$ing['latest_price'], 
                'conversion_factor' => (float)$ing['conversion_factor']
            ];
        }

        $portions_json = $ing['quantity_per_portion'];
        $portions = json_decode($portions_json, true);
        
        // --- LOGIKA BDD ---
        $bdd_factor = (float)($ing['bdd_percentage'] ?? 1.00);
        if ($bdd_factor <= 0) $bdd_factor = 1.00;
        // ------------------
        
        $grams_net_per_day = 0; // Berat bersih
        if (is_array($portions)) {
            foreach ($beneficiary_counts as $cat_id => $count) {
                $grams_net_per_day += (float)($portions[$cat_id] ?? 0) * $count;
            }
        }
        
        // Konversi dari berat bersih ke berat kotor
        $grams_gross_per_day = $grams_net_per_day / $bdd_factor;
        
        $ingredientNeeds[$ing_id]['total_grams_gross'] += $grams_gross_per_day;
    }

    // 4. Buat PO utama
    $po_code = "PO-" . date("Ymd") . "-" . strtoupper(substr(md5(time()), 0, 5));
    $total_amount = 0;
    foreach ($ingredientNeeds as $item) {
        if($item['conversion_factor'] > 0){
            // --- PERUBAHAN: Gunakan total_grams_gross ---
            $quantity_in_purchase_unit = $item['total_grams_gross'] / $item['conversion_factor'];
            $total_amount += $quantity_in_purchase_unit * $item['latest_price'];
        }
    }
    
    $poSql = "INSERT INTO purchase_orders (organization_id, po_code, proposal_id, supplier_id, total_amount, status) VALUES (?, ?, ?, NULL, ?, 'Dikirim')";
    $poStmt = $conn->prepare($poSql);
    $poStmt->bind_param("isid", $org_id, $po_code, $proposal_id, $total_amount);
    $poStmt->execute();
    $po_id = $conn->insert_id;
    if ($po_id == 0) throw new Exception("Gagal membuat PO. Cek jika proposal_id valid.");
    $poStmt->close();

    // 5. Masukkan item-item ke PO
    $itemSql = "INSERT INTO po_items (organization_id, po_id, ingredient_id, quantity, price_per_unit, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
    $itemStmt = $conn->prepare($itemSql);
    foreach ($ingredientNeeds as $ing_id => $item) {
        if($item['conversion_factor'] > 0){
            // --- PERUBAHAN: Gunakan total_grams_gross ---
            $quantity_in_purchase_unit = $item['total_grams_gross'] / $item['conversion_factor'];
            $subtotal = $quantity_in_purchase_unit * $item['latest_price'];
            $itemStmt->bind_param("iiiddd", $org_id, $po_id, $ing_id, $quantity_in_purchase_unit, $item['latest_price'], $subtotal);
            $itemStmt->execute();
        }
    }
    $itemStmt->close();
    
    $conn->commit();

    // 6. Logika notifikasi (tetap sama)
    $accountantSql = "SELECT id FROM users WHERE role_id = 3 AND organization_id = ?";
    $accStmt = $conn->prepare($accountantSql);
    $accStmt->bind_param("i", $org_id);
    $accStmt->execute();
    $accountants = $accStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $accStmt->close();

    foreach ($accountants as $accountant) {
        $title = "Purchase Order Baru Dibuat";
        $message = "PO {$po_code} telah dibuat dan membutuhkan verifikasi.";
        $link = "/app/purchase-orders/" . $po_id;
        send_notification($conn, $org_id, $accountant['id'], $title, $message, $link);
    }

    http_response_code(201);
    echo json_encode(['message' => 'Purchase Order gabungan berhasil dibuat.', 'created_po' => $po_code]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->ping()) $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error internal.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>