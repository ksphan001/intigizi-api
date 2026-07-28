<?php
// File: app/public/get_local_overrides.php
// Deskripsi: API Publik untuk aplikasi Sentra IntiGizi mencatat supplier yang dijadikan lokal oleh dapur.

require_once __DIR__ . '/../config.php';

// CORS untuk akses Sentra
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");

try {
    // Ambil daftar supplier Sentra yang di-override menjadi lokal oleh dapur
    $sql = "SELECT 
                s.marketplace_id,
                s.supplier_name,
                o.name as kitchen_name,
                o.id as kitchen_organization_id,
                s.is_local_override,
                s.created_at as connected_at
            FROM suppliers s
            JOIN organizations o ON s.organization_id = o.id
            WHERE s.marketplace_id IS NOT NULL AND s.is_local_override = 1
            ORDER BY s.created_at DESC";
            
    $result = $conn->query($sql);
    $overrides = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'total_overrides' => count($overrides),
        'data' => $overrides
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
