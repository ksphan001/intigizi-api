<?php
// File: app/reports_get_budget_summary.php
// Penjelasan: Logika kalkulasi anggaran diubah untuk menggunakan BERAT KOTOR (BDD).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    // 1. Ambil semua proposal yang disetujui untuk organisasi ini
    $proposals_sql = "SELECT id, proposal_code, start_date, end_date FROM proposals WHERE organization_id = ? AND status = 'Disetujui' ORDER BY start_date DESC";
    $proposals_stmt = $conn->prepare($proposals_sql);
    $proposals_stmt->bind_param("i", $org_id);
    $proposals_stmt->execute();
    $proposals = $proposals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $proposals_stmt->close();

    // 2. Ambil total jumlah penerima manfaat per kategori untuk organisasi ini
    $counts_sql = "SELECT dpc.category_id, SUM(dpc.count) as total_count
                   FROM distribution_point_counts dpc
                   JOIN distribution_points dp ON dpc.distribution_point_id = dp.id
                   WHERE dp.organization_id = ?
                   GROUP BY dpc.category_id";
    $counts_stmt = $conn->prepare($counts_sql);
    $counts_stmt->bind_param("i", $org_id);
    $counts_stmt->execute();
    $category_totals_result = $counts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $counts_stmt->close();
    
    $beneficiary_counts = [];
    foreach($category_totals_result as $row) {
        $beneficiary_counts[$row['category_id']] = (int)$row['total_count'];
    }

    $report_data = [];

    // Pre-prepare statements for use inside the loop
    $schedule_sql = "SELECT menu_id, COUNT(DISTINCT serving_date) as day_count FROM proposal_menus WHERE proposal_id = ? AND menu_id != 1 GROUP BY menu_id";
    $schedule_stmt = $conn->prepare($schedule_sql);

    // --- PERUBAHAN: JOIN nutrition_data untuk ambil bdd_percentage ---
    $recipe_sql = "SELECT 
                       mi.quantity_per_portion, i.latest_price, 
                       u.conversion_factor, nd.bdd_percentage
                   FROM menu_ingredients mi
                   JOIN ingredients i ON mi.ingredient_id = i.id
                   JOIN units u ON i.unit_id = u.id
                   LEFT JOIN nutrition_data nd ON i.id = nd.ingredient_id AND i.organization_id = nd.organization_id
                   WHERE mi.menu_id = ? AND mi.organization_id = ?";
    $recipe_stmt = $conn->prepare($recipe_sql);

    $po_sql = "SELECT SUM(total_amount) as total FROM purchase_orders WHERE proposal_id = ?";
    $po_stmt = $conn->prepare($po_sql);

    // 3. Loop melalui setiap proposal untuk menghitung anggarannya
    foreach ($proposals as $proposal) {
        $proposal_id = $proposal['id'];
        $total_estimated_budget = 0;

        // a. Dapatkan jadwal menu untuk proposal ini
        $schedule_stmt->bind_param("i", $proposal_id);
        $schedule_stmt->execute();
        $menu_schedule_result = $schedule_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $menu_day_counts = [];
        foreach($menu_schedule_result as $row){
            $menu_day_counts[$row['menu_id']] = (int)$row['day_count'];
        }

        // b. Hitung biaya untuk setiap menu dalam jadwal
        foreach ($menu_day_counts as $menu_id => $day_count) {
            $recipe_stmt->bind_param("ii", $menu_id, $org_id);
            $recipe_stmt->execute();
            $ingredients = $recipe_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            foreach ($ingredients as $ingredient) {
                $portions_json = $ingredient['quantity_per_portion'];
                $portions_array = json_decode($portions_json, true);
                
                // --- LOGIKA BDD ---
                $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
                if ($bdd_factor <= 0) $bdd_factor = 1.00;
                // ------------------
                
                $total_grams_net_per_day = 0;

                if (is_array($portions_array)) {
                    // FORMAT BARU (JSON per kategori)
                    foreach ($beneficiary_counts as $cat_id => $count) {
                        $portion_for_cat_net = (float)($portions_array[$cat_id] ?? 0);
                        $total_grams_net_per_day += $portion_for_cat_net * $count;
                    }
                }
                
                // Konversi ke BERAT KOTOR
                $total_grams_gross_per_day = $total_grams_net_per_day / $bdd_factor;
                
                $total_grams_gross_for_proposal_period = $total_grams_gross_per_day * $day_count;
                
                if($ingredient['conversion_factor'] > 0) {
                    $price_per_gram = (float)$ingredient['latest_price'] / (float)$ingredient['conversion_factor'];
                    // Hitung HPP dari BERAT KOTOR
                    $total_estimated_budget += $total_grams_gross_for_proposal_period * $price_per_gram;
                }
            }
        }

        // c. Dapatkan total realisasi dari purchase orders (Logika ini tetap)
        $po_stmt->bind_param("i", $proposal_id);
        $po_stmt->execute();
        $realized_spending_result = $po_stmt->get_result()->fetch_assoc();
        $total_realized_spending = (float)($realized_spending_result['total'] ?? 0);

        // d. Tambahkan hasil ke data laporan akhir
        $report_data[] = [
            'id' => $proposal_id,
            'proposal_code' => $proposal['proposal_code'],
            'total_estimated_budget' => $total_estimated_budget,
            'total_realized_spending' => $total_realized_spending
        ];
    }

    // Close prepared statements
    $schedule_stmt->close();
    $recipe_stmt->close();
    $po_stmt->close();

    http_response_code(200);
    echo json_encode($report_data);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi kesalahan saat mengambil data laporan.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>