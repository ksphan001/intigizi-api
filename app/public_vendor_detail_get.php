<?php
// File: app/public_vendor_detail_get.php
// Penjelasan: PERBAIKAN KRITIS. Menambahkan `o.profile_picture` ke dalam query
// dan header no-cache untuk memastikan foto profil selalu yang terbaru.

require_once __DIR__ . '/config.php';

// Menambahkan header untuk mencegah browser caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$vendor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($vendor_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID vendor tidak valid.']);
    exit();
}

try {
    $response = [];

    // 1. Ambil detail utama vendor
    $detailSql = "SELECT 
                    o.id, 
                    o.name, 
                    o.vendor_description, 
                    o.vendor_website,
                    o.vendor_address,
                    o.profile_picture,
                    o.average_rating,
                    o.review_count,
                    vc.name as category_name,
                    u.email as pic_email,
                    u.phone_number as pic_phone
                FROM organizations o
                LEFT JOIN vendor_categories vc ON o.vendor_category_id = vc.id
                JOIN users u ON o.id = u.organization_id AND u.role_id = 5
                WHERE o.id = ? AND o.is_active = 1 AND o.registration_type = 'Vendor'
                LIMIT 1";
    $detailStmt = $conn->prepare($detailSql);
    $detailStmt->bind_param("i", $vendor_id);
    $detailStmt->execute();
    $detailResult = $detailStmt->get_result();
    
    if ($detailResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['message' => 'Vendor tidak ditemukan atau belum aktif.']);
        exit();
    }
    $response['details'] = $detailResult->fetch_assoc();
    $detailStmt->close();

    // 2. Ambil produk yang ditawarkan vendor
    $productsSql = "SELECT p.id, p.product_name, p.price_per_unit, p.description, u.symbol as unit_symbol 
                  FROM vendor_products p
                  JOIN units u ON p.unit_id = u.id
                  WHERE p.organization_id = ? ORDER BY p.product_name ASC";
    $productsStmt = $conn->prepare($productsSql);
    $productsStmt->bind_param("i", $vendor_id);
    $productsStmt->execute();
    $response['products'] = $productsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $productsStmt->close();

    // 3. Ambil portofolio vendor
    $portfolioSql = "SELECT id, title, description, image_path FROM portfolio_items WHERE organization_id = ? ORDER BY id DESC";
    $portfolioStmt = $conn->prepare($portfolioSql);
    $portfolioStmt->bind_param("i", $vendor_id);
    $portfolioStmt->execute();
    $response['portfolio'] = $portfolioStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $portfolioStmt->close();
    
    // 4. Ambil ulasan
    $reviewsSql = "SELECT 
                    vr.rating, vr.comment, vr.created_at,
                    o_reviewer.name as reviewer_org_name
                   FROM vendor_reviews vr
                   JOIN organizations o_reviewer ON vr.reviewer_org_id = o_reviewer.id
                   WHERE vr.vendor_id = ?
                   ORDER BY vr.created_at DESC
                   LIMIT 5";
    $reviewsStmt = $conn->prepare($reviewsSql);
    $reviewsStmt->bind_param("i", $vendor_id);
    $reviewsStmt->execute();
    $response['reviews'] = $reviewsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reviewsStmt->close();

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

