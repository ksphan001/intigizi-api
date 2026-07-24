<?php
// File: app/public_funding_campaign_detail_get.php
// Penjelasan: API publik baru untuk mengambil detail lengkap satu kampanye
// pendanaan yang aktif, termasuk dokumen dan dana terkumpul.
// PERBAIKAN: Mengganti i.lot_count menjadi i.lots_purchased
//
// --- PERBAIKAN BARU (SESUAI PERMINTAAN ANDA) ---
// Subquery untuk 'current_amount' sekarang HANYA menjumlahkan investasi dengan status = 'paid'

require_once __DIR__ . '/config.php';

$campaign_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($campaign_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Kampanye tidak valid.']);
    exit();
}

try {
    $response = [];

    // 1. Ambil detail utama kampanye dan hitung dana terkumpul
    // --- PERBAIKAN DI SINI ---
    $sql_details = "SELECT 
                        fc.*,
                        o.name as organization_name,
                        u.full_name as user_name,
                        (
                            SELECT SUM(i.total_investment) 
                            FROM investments i 
                            WHERE i.campaign_id = fc.id
                            AND i.status = 'paid'
                        ) as current_amount
                    FROM funding_campaigns fc
                    JOIN organizations o ON fc.organization_id = o.id
                    JOIN users u ON fc.user_id = u.id
                    WHERE fc.id = ? AND fc.status = 'active'";
    $stmt_details = $conn->prepare($sql_details);
    $stmt_details->bind_param("i", $campaign_id);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    
    if($result_details->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['message' => 'Proyek pendanaan tidak ditemukan atau belum aktif.']);
        exit();
    }
    
    $response['details'] = $result_details->fetch_assoc();
    // Decode JSON untuk 'terms_override'
    if (isset($response['details']['terms_override'])) {
        $response['details']['terms_override'] = json_decode($response['details']['terms_override'], true);
    }
    
    $stmt_details->close();

    // 2. Ambil dokumen-dokumen terkait
    $sql_docs = "SELECT id, document_name, file_path FROM campaign_documents WHERE campaign_id = ?";
    $stmt_docs = $conn->prepare($sql_docs);
    $stmt_docs->bind_param("i", $campaign_id);
    $stmt_docs->execute();
    $result_docs = $stmt_docs->get_result();
    $response['documents'] = $result_docs->fetch_all(MYSQLI_ASSOC);
    $stmt_docs->close();

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>