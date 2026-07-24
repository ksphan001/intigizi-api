<?php
// File: app/vendor_get_profile.php
// Penjelasan: Ditulis ulang untuk mengambil semua data (profil, produk, portofolio)
// dalam satu panggilan, termasuk `profile_picture`.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    $response = [
        'profile' => null,
        'products' => [],
        'portfolio' => []
    ];

    // 1. Ambil detail utama profil dari organizations
    $profileSql = "SELECT 
                    o.name, 
                    o.vendor_description, 
                    o.vendor_address, 
                    o.vendor_website,
                    o.vendor_category_id,
                    o.profile_picture,
                    vc.name as category_name
                   FROM organizations o
                   LEFT JOIN vendor_categories vc ON o.vendor_category_id = vc.id
                   WHERE o.id = ? AND o.registration_type = 'Vendor'";
    $profileStmt = $conn->prepare($profileSql);
    $profileStmt->bind_param("i", $org_id);
    $profileStmt->execute();
    $response['profile'] = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();
    
    if (!$response['profile']) {
        throw new Exception("Profil vendor tidak ditemukan.");
    }

    // 2. Ambil produk yang ditawarkan
    $productsSql = "SELECT p.id, p.product_name, p.price_per_unit, p.description, p.unit_id, u.symbol as unit_symbol 
                  FROM vendor_products p
                  JOIN units u ON p.unit_id = u.id
                  WHERE p.organization_id = ? ORDER BY p.product_name ASC";
    $productsStmt = $conn->prepare($productsSql);
    $productsStmt->bind_param("i", $org_id);
    $productsStmt->execute();
    $response['products'] = $productsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $productsStmt->close();

    // 3. Ambil portofolio
    $portfolioSql = "SELECT id, title, description, image_path FROM portfolio_items WHERE organization_id = ? ORDER BY id DESC";
    $portfolioStmt = $conn->prepare($portfolioSql);
    $portfolioStmt->bind_param("i", $org_id);
    $portfolioStmt->execute();
    $response['portfolio'] = $portfolioStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $portfolioStmt->close();

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error internal pada server.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

