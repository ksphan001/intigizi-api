<?php
// File: app/superadmin_get_organization_details.php
// PENJELASAN: Diperbarui untuk:
// 1. Mengambil data dari financial_transactions untuk total_spending.
// 2. Memperbaiki error 'Unknown column' dengan mengubah 'b.nik' menjadi 'b.nik_nisn'.
// --- PERBAIKAN BARU: Menambahkan LEFT JOIN ke distribution_points untuk mengambil dp.name sebagai kitchen_name ---

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

if ($userData['role_id'] != 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$org_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($org_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID organisasi wajib diisi.']);
    exit();
}

try {
    $response = [];

    // 1. Ambil Detail Dasar Organisasi (TERMASUK KITCHEN_NAME)
    // --- PERBAIKAN DI SINI ---
    $orgSql = "SELECT 
                   o.id, o.name, o.organization_type, o.director_name, o.pic_name, o.pic_whatsapp, 
                   o.is_active, o.created_at, o.registration_type, 
                   r.name as regency_name, p.name as province_name,
                   dp.name as kitchen_name 
               FROM organizations o
               LEFT JOIN regencies r ON o.regency_id = r.id
               LEFT JOIN provinces p ON o.province_id = p.id
               LEFT JOIN distribution_points dp ON o.id = dp.organization_id AND dp.is_main_kitchen = 1
               WHERE o.id = ? LIMIT 1";
    // --- AKHIR PERBAIKAN ---
               
    $orgStmt = $conn->prepare($orgSql);
    $orgStmt->bind_param("i", $org_id);
    $orgStmt->execute();
    $orgResult = $orgStmt->get_result();
    
    if ($orgResult->num_rows == 0) {
        http_response_code(404);
        echo json_encode(['message' => 'Organisasi tidak ditemukan.']);
        exit();
    }
    $details = $orgResult->fetch_assoc();
    $response['details'] = $details;
    $orgStmt->close();

    // 2. Ambil Daftar Pengguna
    $usersSql = "SELECT u.id, u.full_name, u.username, u.email, r.role_name, u.is_active 
                 FROM users u 
                 JOIN roles r ON u.role_id = r.id 
                 WHERE u.organization_id = ? 
                 ORDER BY u.full_name ASC";
    $usersStmt = $conn->prepare($usersSql);
    $usersStmt->bind_param("i", $org_id);
    $usersStmt->execute();
    $response['users'] = $usersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $usersStmt->close();

    // 3. Jika Vendor, ambil data tambahan
    if ($details && $details['registration_type'] === 'Vendor') {
        $portfolioSql = "SELECT id, title, description, image_path FROM portfolio_items WHERE organization_id = ? ORDER BY id DESC";
        $portfolioStmt = $conn->prepare($portfolioSql);
        $portfolioStmt->bind_param("i", $org_id);
        $portfolioStmt->execute();
        $response['portfolio'] = $portfolioStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $portfolioStmt->close();

        $productsSql = "SELECT p.id, p.product_name, p.price_per_unit, p.description, u.symbol as unit_symbol FROM vendor_products p JOIN units u ON p.unit_id = u.id WHERE p.organization_id = ? ORDER BY p.product_name ASC";
        $productsStmt = $conn->prepare($productsSql);
        $productsStmt->bind_param("i", $org_id);
        $productsStmt->execute();
        $response['products'] = $productsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $productsStmt->close();
    }

    // 4. Jika Mitra Dapur, ambil data operasional
    if ($details && $details['registration_type'] !== 'Vendor') {
        $pointsSql = "SELECT id, name, address, is_main_kitchen, latitude, longitude FROM distribution_points WHERE organization_id = ? ORDER BY is_main_kitchen DESC, name ASC";
        $pointsStmt = $conn->prepare($pointsSql);
        $pointsStmt->bind_param("i", $org_id);
        $pointsStmt->execute();
        $response['distribution_points'] = $pointsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pointsStmt->close();

        $beneficiariesSql = "SELECT b.id, b.full_name, b.nik_nisn, dp.name as distribution_point_name FROM beneficiaries b JOIN distribution_points dp ON b.distribution_point_id = dp.id WHERE b.organization_id = ? ORDER BY b.full_name ASC";
        $benStmt = $conn->prepare($beneficiariesSql);
        $benStmt->bind_param("i", $org_id);
        $benStmt->execute();
        $response['beneficiaries'] = $benStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $benStmt->close();

        $reportsSql = "SELECT dr.id, dr.distribution_date, dr.quantity_sent, dr.status, dp.name as distribution_point_name FROM distribution_reports dr JOIN distribution_points dp ON dr.distribution_point_id = dp.id WHERE dr.organization_id = ? ORDER BY dr.distribution_date DESC LIMIT 5";
        $repStmt = $conn->prepare($reportsSql);
        $repStmt->bind_param("i", $org_id);
        $repStmt->execute();
        $response['recent_reports'] = $repStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $repStmt->close();
        
        $spendingSql = "SELECT SUM(amount) as total FROM financial_transactions 
                        WHERE organization_id = ? AND debit_account_id IN (4, 5, 6, 9)";
        $spendStmt = $conn->prepare($spendingSql);
        $spendStmt->bind_param("i", $org_id);
        $spendStmt->execute();
        $response['total_spending'] = (float)($spendStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $spendStmt->close();

        $prodTotalSql = "SELECT SUM(p.target_recipients) as total FROM production_logs pl JOIN proposals p ON pl.proposal_id = p.id WHERE pl.organization_id = ?";
        $prodTotalStmt = $conn->prepare($prodTotalSql);
        $prodTotalStmt->bind_param("i", $org_id);
        $prodTotalStmt->execute();
        $response['total_production'] = (int)($prodTotalStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $prodTotalStmt->close();
        
        $recentProdSql = "SELECT pl.id, pl.production_date, p.proposal_code, p.target_recipients FROM production_logs pl JOIN proposals p ON pl.proposal_id = p.id WHERE pl.organization_id = ? ORDER BY pl.production_date DESC LIMIT 10";
        $recentProdStmt = $conn->prepare($recentProdSql);
        $recentProdStmt->bind_param("i", $org_id);
        $recentProdStmt->execute();
        $response['recent_production'] = $recentProdStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recentProdStmt->close();
    }

    http_response_code(200);
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>