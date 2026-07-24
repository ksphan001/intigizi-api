<?php
// File: app/investor_get_portfolio.php
// Penjelasan: API terproteksi untuk mengambil daftar investasi
// MODIFIKASI: Menambahkan 'fc.organization_id as kitchen_org_id'
// PERBAIKAN: Mengganti 'i.lot_count' (salah) menjadi 'i.lots_purchased AS lot_count' (benar)
// --- PERBAIKAN BARU: Mengganti 'i.created_at' (salah) menjadi 'i.investment_date AS created_at' (benar) ---
// --- PERBAIKAN BARU 2: Menambahkan i.status dan i.payment_proof_path ---

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$user_id = (int)$userData['id'];

// Tidak perlu cek role karena user ID sudah unik

try {
    // --- PERBAIKAN DI SINI ---
    $sql = "SELECT 
                i.id,
                i.lots_purchased AS lot_count, 
                i.total_investment,
                i.investment_date AS created_at,
                i.status,
                i.payment_proof_path,
                fc.title as campaign_title,
                fc.status as campaign_status,
                fc.organization_id as kitchen_org_id 
            FROM investments i
            JOIN funding_campaigns fc ON i.campaign_id = fc.id
            WHERE i.user_id = ?
            ORDER BY i.investment_date DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $portfolio = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode($portfolio);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal memuat data portofolio.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>