<?php
// File: app/units_get.php
// Penjelasan: Diperbarui untuk SaaS. Meskipun data satuan bersifat global,
// best practice-nya adalah tetap menyiapkannya untuk kemungkinan kustomisasi di masa depan.
// Untuk saat ini, kita tidak menambahkan filter organization_id agar semua organisasi bisa berbagi data satuan yang sama.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token(); // Verifikasi token tetap diperlukan untuk keamanan

header('Content-Type: application/json');

try {
    $sql = "SELECT id, name, symbol FROM units ORDER BY name ASC";
    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $units = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($units);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi error internal pada server.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
