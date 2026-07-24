<?php
// File: app/ingredients_sync_master.php
// Penjelasan: Sinkronisasi harga bahan baku lokal dapur (latest_price) dengan taksiran harga pasar resmi di master_ingredients.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

if (php_sapi_name() === 'cli') {
    $userData = ['role_id' => 7, 'org_id' => 4];
} else {
    $userData = verify_jwt_token();
}
$org_id = (int)$userData['org_id'];

// Keamanan: Hanya Administrator Dapur (Role ID 7) yang diizinkan melakukan sinkronisasi
if (!isset($userData['role_id']) || (int)$userData['role_id'] !== 7) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Fitur ini hanya untuk Administrator Dapur.']);
    exit();
}

$method = php_sapi_name() === 'cli' ? 'POST' : $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Metode request tidak diizinkan. Gunakan POST.']);
    exit();
}

try {
    $conn->begin_transaction();

    // Jalankan query sinkronisasi harga
    $sql = "UPDATE ingredients i
            JOIN master_ingredients mi ON i.master_ingredient_id = mi.id
            SET i.latest_price = mi.estimated_price
            WHERE i.organization_id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        'message' => "Berhasil menyinkronkan harga bahan baku. $affected_rows bahan baku disesuaikan dengan harga acuan master."
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan saat menyinkronkan harga.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
