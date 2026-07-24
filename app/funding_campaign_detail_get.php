<?php
// File: app/funding_campaign_detail_get.php
// Penjelasan: API baru untuk Super Admin mengambil detail lengkap
// satu pengajuan pendanaan, termasuk dokumen-dokumennya.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$user_role = (int)$userData['role_id'];

if ($user_role !== 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$campaign_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($campaign_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Kampanye tidak valid.']);
    exit();
}

try {
    $response = [];

    // 1. Ambil detail utama kampanye
    $sql_details = "SELECT 
                        fc.*,
                        o.name as organization_name,
                        u.full_name as user_name
                    FROM funding_campaigns fc
                    JOIN organizations o ON fc.organization_id = o.id
                    JOIN users u ON fc.user_id = u.id
                    WHERE fc.id = ?";
    $stmt_details = $conn->prepare($sql_details);
    $stmt_details->bind_param("i", $campaign_id);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    
    if($result_details->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['message' => 'Detail pengajuan tidak ditemukan.']);
        exit();
    }
    $response['details'] = $result_details->fetch_assoc();
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
