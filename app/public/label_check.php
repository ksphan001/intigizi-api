<?php
// File: app/public/label_check.php
// Public endpoint for QR code label verification

require_once __DIR__ . '/../config.php';

// Allow CORS for public access
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");

$proposal_id_input = isset($_GET['pid']) ? $_GET['pid'] : null;
$date_input = isset($_GET['date']) ? $_GET['date'] : null;

if (!$proposal_id_input || !$date_input) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter pid dan date wajib diisi.']);
    exit();
}

try {
    // 1. Get Menu & Kitchen Details
    // We treat proposal_code as ID input or actual ID? 
    // Usually pid in URL is ID, but let's support Proposal Code if passed as string, or ID.
    // Let's assume ID for now as it is stable.

    // Find Proposal, Org, and Menu
    // JOIN with distribution_points to get the actual Kitchen Name (Main Kitchen)
    $sql = "SELECT 
                p.id as proposal_id, p.proposal_code, p.organization_id,
                COALESCE(dp.name, o.name) as kitchen_name,
                pm.menu_id, m.menu_name,
                pl.production_date, pl.best_before
            FROM proposals p
            JOIN organizations o ON p.organization_id = o.id
            LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
            JOIN proposal_menus pm ON p.id = pm.proposal_id
            JOIN menus m ON pm.menu_id = m.id
            LEFT JOIN production_logs pl ON p.id = pl.proposal_id AND pl.production_date = pm.serving_date
            WHERE (p.id = ? OR p.proposal_code = ?) 
            AND pm.serving_date = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $proposal_id_input, $proposal_id_input, $date_input);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();

    if (!$data) {
        throw new Exception('Data produksi tidak ditemukan untuk ID/Tanggal tersebut.', 404);
    }

    $menu_id = $data['menu_id'];
    $org_id = $data['organization_id'];

    // 2. Calculate Nutrition (Average of all categories roughly, or pick first populated one)
    // We reuse logic from menu_nutrition_get.php but simplified

    // Get ingredients
    $ing_sql = "SELECT
                    mi.quantity_per_portion,
                    nd.calories, nd.protein, nd.carbohydrates, nd.fat, nd.fiber
                FROM menu_ingredients mi
                LEFT JOIN nutrition_data nd ON mi.ingredient_id = nd.ingredient_id AND nd.organization_id = mi.organization_id
                WHERE mi.menu_id = ? AND mi.organization_id = ?";

    $stmt = $conn->prepare($ing_sql);
    $stmt->bind_param("ii", $menu_id, $org_id);
    $stmt->execute();
    $ingredients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate totals
    // We will take the maximum nutritional value across categories to be safe for a label
    // Or just calculate for a standard category ID (e.g. 1) if we knew it. 
    // Let's calculate for the FIRST category found in quantity_json to represent "Per Porsi"

    $totals = [
        'energy' => 0,
        'protein' => 0,
        'carbo' => 0,
        'fat' => 0,
        'fiber' => 0
    ];

    foreach ($ingredients as $ing) {
        $portions = json_decode($ing['quantity_per_portion'], true);
        $qty = 0;
        if (is_array($portions)) {
            // Take the first available quantity found used as "Reference Portion"
            foreach ($portions as $q) {
                if ($q > 0) {
                    $qty = (float) $q;
                    break;
                }
            }
        }

        if ($qty > 0) {
            $totals['energy'] += ((float) $ing['calories'] * $qty) / 100;
            $totals['protein'] += ((float) $ing['protein'] * $qty) / 100;
            $totals['carbo'] += ((float) $ing['carbohydrates'] * $qty) / 100;
            $totals['fat'] += ((float) $ing['fat'] * $qty) / 100;
            $totals['fiber'] += ((float) $ing['fiber'] * $qty) / 100;
        }
    }

    // Response structure
    $response = [
        'kitchen_name' => $data['kitchen_name'],
        'menu_name' => $data['menu_name'],
        'proposal_code' => $data['proposal_code'],
        'production_date' => $date_input,
        'best_before' => $data['best_before'] ? $data['best_before'] : null,
        'nutrition' => [
            'energy' => round($totals['energy'], 1),
            'protein' => round($totals['protein'], 1),
            'fat' => round($totals['fat'], 1),
            'carbo' => round($totals['carbo'], 1),
            'fiber' => round($totals['fiber'], 1)
        ]
    ];

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn))
        $conn->close();
}
?>