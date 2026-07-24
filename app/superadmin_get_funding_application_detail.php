<?php
// File: app/superadmin_get_funding_application_detail.php
// PERBAIKAN:
// 1. Mengambil data dari `funding_applications` (details).
// 2. Mengambil data dari `funding_campaigns` (campaign) jika sudah ada.
// Ini memungkinkan frontend menampilkan data yang sudah di-override (data terupdate).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin (role_id = 8) yang bisa mengakses
if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($application_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Pengajuan tidak valid.']);
    exit();
}

try {
    $response = [
        'details' => null,
        'documents' => [],
        'campaign' => null // Akan diisi jika kampanye sudah ada
    ];

    // 1. Ambil detail utama pengajuan (Data Asli)
    $sql_details = "SELECT 
                        fa.*,
                        p.name as province_name,
                        r.name as regency_name,
                        o.name as organization_name
                    FROM funding_applications fa
                    LEFT JOIN provinces p ON fa.province_id = p.id
                    LEFT JOIN regencies r ON fa.regency_id = r.id
                    LEFT JOIN organizations o ON fa.organization_id = o.id
                    WHERE fa.id = ?";
    
    $stmt_details = $conn->prepare($sql_details);
    $stmt_details->bind_param("i", $application_id);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    
    if($result_details->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['message' => 'Data pengajuan tidak ditemukan.']);
        exit();
    }
    $response['details'] = $result_details->fetch_assoc();
    if (isset($response['details']['bank_account_details'])) {
        $response['details']['bank_account_details'] = json_decode($response['details']['bank_account_details'], true);
    }
    $stmt_details->close();

    // 2. Ambil dokumen-dokumen terkait
    $sql_docs = "SELECT id, original_name, file_path FROM funding_application_documents WHERE application_id = ?";
    $stmt_docs = $conn->prepare($sql_docs);
    $stmt_docs->bind_param("i", $application_id);
    $stmt_docs->execute();
    $result_docs = $stmt_docs->get_result();
    $response['documents'] = $result_docs->fetch_all(MYSQLI_ASSOC);
    $stmt_docs->close();

    // --- PERBAIKAN BARU: Ambil data Kampanye (Data Terupdate) jika ada ---
    $sql_campaign = "SELECT 
                        id, title, description, target_amount, lot_price, 
                        profit_share, terms_override 
                     FROM funding_campaigns 
                     WHERE funding_application_id = ? 
                     LIMIT 1";
    $stmt_campaign = $conn->prepare($sql_campaign);
    $stmt_campaign->bind_param("i", $application_id);
    $stmt_campaign->execute();
    $result_campaign = $stmt_campaign->get_result();
    if ($result_campaign->num_rows > 0) {
        $campaign_data = $result_campaign->fetch_assoc();
        // Decode JSON terms_override
        if ($campaign_data['terms_override']) {
            $campaign_data['terms_override'] = json_decode($campaign_data['terms_override'], true);
        }
        $response['campaign'] = $campaign_data;
    }
    $stmt_campaign->close();
    // --- AKHIR PERBAIKAN ---

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server saat mengambil detail.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}