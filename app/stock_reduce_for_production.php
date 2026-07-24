<?php
// File: app/stock_reduce_for_production.php
// PENJELASAN: Logika disempurnakan untuk mengurangi stok berdasarkan BERAT KOTOR (Gross Weight).
// Resep diinput dalam Berat Bersih (Net), jadi kita konversi menggunakan BDD.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int) $userData['org_id'];
$user_id = (int) $userData['id'];

try {
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->proposal_id) || !isset($data->production_date)) {
        throw new Exception('ID Proposal dan tanggal produksi wajib diisi.', 400);
    }
    $proposal_id = (int) $data->proposal_id;
    $production_date = $data->production_date;

    $conn->begin_transaction();

    // 1. Cek apakah produksi untuk tanggal ini belum pernah dicatat - DIGANTI: KITA PERBOLEHKAN MULTIPLE BATCH
    /*
    $logCheckSql = "SELECT id FROM production_logs WHERE proposal_id = ? AND production_date = ? AND organization_id = ?";
    $logCheckStmt = $conn->prepare($logCheckSql);
    $logCheckStmt->bind_param("isi", $proposal_id, $production_date, $org_id);
    $logCheckStmt->execute();
    if ($logCheckStmt->get_result()->num_rows > 0) {
        throw new Exception('Produksi untuk tanggal ini sudah pernah dicatat sebelumnya.', 409);
    }
    $logCheckStmt->close();
    */

    // 2. Ambil menu_id untuk hari produksi ini
    $menuSql = "SELECT menu_id FROM proposal_menus WHERE proposal_id = ? AND serving_date = ? AND organization_id = ?";
    $menuStmt = $conn->prepare($menuSql);
    $menuStmt->bind_param("isi", $proposal_id, $production_date, $org_id);
    $menuStmt->execute();
    $menuResult = $menuStmt->get_result()->fetch_assoc();
    if (!$menuResult) {
        throw new Exception('Tidak ada menu yang dijadwalkan untuk proposal dan tanggal ini.', 404);
    }
    $menu_id = $menuResult['menu_id'];
    $menuStmt->close();

    // 3. Ambil total jumlah penerima per kategori dari SEMUA titik distribusi
    $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count
                  FROM distribution_point_counts dpc
                  JOIN distribution_points dp ON dpc.distribution_point_id = dp.id
                  WHERE dp.organization_id = ?
                  GROUP BY dpc.category_id";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->bind_param("i", $org_id);
    $countsStmt->execute();
    $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $countsStmt->close();

    $category_totals = [];
    foreach ($category_totals_result as $row) {
        $category_totals[$row['category_id']] = (int) $row['total_count'];
    }

    // 4. Ambil resep untuk menu yang akan diproduksi (TERMASUK DATA BDD)
    // --- PERUBAHAN: JOIN nutrition_data untuk ambil bdd_percentage ---
    $recipeSql = "SELECT 
                      mi.ingredient_id, 
                      mi.quantity_per_portion, 
                      nd.bdd_percentage 
                  FROM menu_ingredients mi
                  LEFT JOIN nutrition_data nd ON mi.ingredient_id = nd.ingredient_id AND mi.organization_id = nd.organization_id
                  WHERE mi.menu_id = ? AND mi.organization_id = ?";

    $recipeStmt = $conn->prepare($recipeSql);
    $recipeStmt->bind_param("ii", $menu_id, $org_id);
    $recipeStmt->execute();
    $ingredients_to_reduce = $recipeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recipeStmt->close();
    if (empty($ingredients_to_reduce))
        throw new Exception('Menu ini tidak memiliki resep.', 400);

    // 5. Kurangi stok untuk setiap bahan dan catat transaksinya
    $stockUpdateSql = "UPDATE stock SET current_quantity = current_quantity - ? WHERE ingredient_id = ? AND organization_id = ? AND current_quantity >= ?";
    $stockUpdateStmt = $conn->prepare($stockUpdateSql);

    $transSql = "INSERT INTO stock_transactions (organization_id, ingredient_id, type, quantity, notes, menu_id) VALUES (?, ?, 'Keluar', ?, ?, ?)";
    $transStmt = $conn->prepare($transSql);
    $notes = "Produksi menu tanggal " . $production_date;

    foreach ($ingredients_to_reduce as $ing) {
        $portions_json = $ing['quantity_per_portion'];
        $portions = json_decode($portions_json, true);

        // --- LOGIKA BDD ---
        $bdd_factor = (float) ($ing['bdd_percentage'] ?? 1.00);
        if ($bdd_factor <= 0)
            $bdd_factor = 1.00;

        $quantity_to_reduce_net_grams = 0; // Berat bersih

        if (is_array($portions)) {
            foreach ($category_totals as $cat_id => $count) {
                $portion_for_cat_net = (float) ($portions[$cat_id] ?? 0);
                $quantity_to_reduce_net_grams += $portion_for_cat_net * $count;
            }
        }

        // Konversi ke BERAT KOTOR untuk mengurangi stok
        $quantity_to_reduce_gross_grams = $quantity_to_reduce_net_grams / $bdd_factor;
        // ------------------

        if ($quantity_to_reduce_gross_grams > 0) {
            // Kurangi stok menggunakan BERAT KOTOR
            $stockUpdateStmt->bind_param("diid", $quantity_to_reduce_gross_grams, $ing['ingredient_id'], $org_id, $quantity_to_reduce_gross_grams);
            $stockUpdateStmt->execute();
            if ($stockUpdateStmt->affected_rows == 0) {
                // Jika gagal (stok tidak cukup), rollback transaksi
                $ing_name_sql = "SELECT name FROM ingredients WHERE id = ?";
                $name_stmt = $conn->prepare($ing_name_sql);
                $name_stmt->bind_param("i", $ing['ingredient_id']);
                $name_stmt->execute();
                $ing_name = $name_stmt->get_result()->fetch_assoc()['name'] ?? "ID: " . $ing['ingredient_id'];
                $name_stmt->close();

                // Debugging Info
                $stock_query = "SELECT current_quantity FROM stock WHERE ingredient_id = ? AND organization_id = ?";
                $s_stmt = $conn->prepare($stock_query);
                $s_stmt->bind_param("ii", $ing['ingredient_id'], $org_id);
                $s_stmt->execute();
                $curr_qty = $s_stmt->get_result()->fetch_assoc()['current_quantity'] ?? 0;
                $s_stmt->close();

                throw new Exception("Stok tidak cukup untuk bahan: '{$ing_name}'. Tersedia: {$curr_qty} gr, Kebutuhan (kotor): {$quantity_to_reduce_gross_grams} gr.", 409);
            }

            // Catat transaksi stok menggunakan BERAT KOTOR
            $transStmt->bind_param("iidsi", $org_id, $ing['ingredient_id'], $quantity_to_reduce_gross_grams, $notes, $menu_id);
            $transStmt->execute();
        }
    }
    $stockUpdateStmt->close();
    $transStmt->close();

    // 6. Catat log produksi
    $best_before = isset($data->best_before) ? $data->best_before : NULL;
    $logSql = "INSERT INTO production_logs (organization_id, proposal_id, production_date, created_by, best_before) VALUES (?, ?, ?, ?, ?)";
    $logStmt = $conn->prepare($logSql);
    $logStmt->bind_param("iisss", $org_id, $proposal_id, $production_date, $user_id, $best_before);
    $logStmt->execute();
    $logStmt->close();

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Stok berhasil dikurangi dan produksi telah dicatat.']);

} catch (Throwable $e) {
    if (isset($conn) && $conn->ping())
        $conn->rollback();
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(['message' => 'Terjadi error: ' . $e->getMessage()]);
} finally {
    if (isset($conn))
        $conn->close();
}
?>