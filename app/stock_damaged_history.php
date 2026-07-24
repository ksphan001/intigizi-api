<?php
// File: app/stock_damaged_history.php
// Penjelasan: Mengambil daftar riwayat bahan baku yang dilaporkan rusak/menyusut untuk organisasi terkait.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Hanya yang memiliki role berkaitan dengan dapur/pengawas yang bisa melihat
if (!isset($userData['role_id']) || !in_array((int)$userData['role_id'], [1, 2, 3, 4, 7, 8, 9])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    // Ambil data transaksi keluar yang bertipe kerusakan dari stock_transactions
    $sql = "SELECT 
                st.id, 
                st.quantity as quantity_in_base_unit, 
                st.notes, 
                st.transaction_date,
                i.name as ingredient_name,
                i.latest_price,
                u.symbol as unit_symbol,
                u.conversion_factor
            FROM stock_transactions st
            JOIN ingredients i ON st.ingredient_id = i.id
            JOIN units u ON i.unit_id = u.id
            WHERE st.organization_id = ? 
              AND st.type = 'Keluar' 
              AND st.notes LIKE 'Rusak:%'
            ORDER BY st.transaction_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Map data untuk menghitung nilai kerugian finansial & kuantitas dalam unit tampilan
    $formatted = array_map(function($row) {
        $cf = (float)$row['conversion_factor'];
        $qty_display = (float)$row['quantity_in_base_unit'] / ($cf > 0 ? $cf : 1);
        $loss_value = $qty_display * (float)$row['latest_price'];
        
        // Bersihkan prefix "Rusak: " dari notes untuk alasan tampilan
        $reason = str_replace("Rusak: ", "", $row['notes']);

        return [
            'id' => (int)$row['id'],
            'ingredient_name' => $row['ingredient_name'],
            'quantity' => $qty_display,
            'unit_symbol' => $row['unit_symbol'],
            'reason_and_notes' => $reason,
            'loss_value' => $loss_value,
            'date' => $row['transaction_date']
        ];
    }, $result);

    http_response_code(200);
    echo json_encode($formatted);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan saat memuat riwayat bahan rusak.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
