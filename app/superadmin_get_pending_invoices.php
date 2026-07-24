<?php
// File: app/superadmin_get_pending_invoices.php
// Penjelasan: API endpoint untuk Super Admin mengambil daftar semua
// permintaan langganan (invoice) yang statusnya masih 'pending'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    // Query untuk mengambil invoice yang pending dan menggabungkannya dengan nama organisasi
    $sql = "SELECT 
                si.id, 
                si.organization_id,
                o.name as organization_name,
                si.package_name,
                si.amount,
                si.created_at
            FROM subscription_invoices si
            JOIN organizations o ON si.organization_id = o.id
            WHERE si.status = 'pending'
            ORDER BY si.created_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoices = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode($invoices);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memuat daftar verifikasi.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
