<?php
// File: app/menu_ingredients_get.php
// PENJELASAN: Diperbarui untuk mengirim data porsi sebagai JSON mentah
// dan menyertakan data harga/konversi agar kalkulasi HPP bisa dilakukan di frontend.
// Ini memperbaiki '500 Internal Server Error' yang disebabkan oleh kalkulasi SQL pada data JSON.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    $menu_id = isset($_GET['menu_id']) ? (int)$_GET['menu_id'] : 0;

    if ($menu_id <= 0) {
        http_response_code(400);
        throw new Exception('ID menu wajib disertakan.');
    }

    // --- PERBAIKAN DI SINI ---
    // Menghapus kalkulasi HPP dari SQL dan memilih data mentah yang diperlukan.
    $sql = "SELECT
                mi.id,
                mi.ingredient_id,
                i.name as ingredient_name,
                mi.quantity_per_portion, -- Ini adalah string JSON
                i.latest_price,          -- Kirim harga mentah
                u.conversion_factor,     -- Kirim faktor konversi
                CASE
                    WHEN u.type = 'massa' THEN 'gr'
                    WHEN u.type = 'volume' THEN 'ml'
                    ELSE u.symbol
                END as base_unit_symbol
            FROM
                menu_ingredients mi
            JOIN
                ingredients i ON mi.ingredient_id = i.id
            JOIN
                units u ON i.unit_id = u.id
            WHERE
                mi.menu_id = ? AND mi.organization_id = ?
            ORDER BY
                i.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $menu_id, $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $ingredients = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($ingredients);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi error internal pada server.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        if(isset($stmt)) $stmt->close();
        $conn->close();
    }
}
?>

