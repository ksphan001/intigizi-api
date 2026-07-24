<?php
// File: app/production_history_get.php
// Penjelasan: API endpoint baru untuk mengambil riwayat produksi yang sudah dicatat.
// API ini menggabungkan beberapa tabel untuk memberikan detail yang lengkap.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Ambil filter tanggal dari query string
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;

if (!$start_date || !$end_date) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter start_date dan end_date wajib diisi.']);
    exit();
}

try {
    // Query ini mengambil semua log produksi dalam rentang tanggal yang dipilih
    // dan menggabungkannya dengan data proposal, menu, dan pengguna.
    $sql = "SELECT
                pl.id,
                pl.production_date,
                p.proposal_code,
                p.target_recipients,
                m.menu_name,
                u.full_name as created_by_name
            FROM
                production_logs pl
            JOIN
                proposals p ON pl.proposal_id = p.id
            JOIN
                users u ON pl.created_by = u.id
            LEFT JOIN
                proposal_menus pm ON pl.proposal_id = pm.proposal_id AND pl.production_date = pm.serving_date
            LEFT JOIN
                menus m ON pm.menu_id = m.id
            WHERE
                pl.organization_id = ?
                AND pl.production_date BETWEEN ? AND ?
            ORDER BY
                pl.production_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $history = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($history);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi kesalahan saat mengambil riwayat produksi.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        if(isset($stmt)) $stmt->close();
        $conn->close();
    }
}
?>
