<?php
// File: app/kitchen_limits_manage.php
// Deskripsi: Mengelola batas anggaran HPP (max_hpp) kategori sasaran spesifik per dapur.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

try {
    if ($method === 'GET') {
        // Ambil semua kategori beserta batas HPP khusus dapur ini
        $sql = "SELECT c.id as category_id, c.name as category_name, COALESCE(kcl.max_hpp, 8000.00) as max_hpp
                FROM beneficiary_categories c
                LEFT JOIN kitchen_category_limits kcl ON c.id = kcl.category_id AND kcl.organization_id = ?
                ORDER BY c.sort_order ASC, c.id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $limits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode($limits);

    } elseif ($method === 'POST') {
        if (!isset($data->limits) || !is_array($data->limits)) {
            throw new Exception("Format data tidak valid.", 400);
        }

        $conn->begin_transaction();
        
        $sql = "INSERT INTO kitchen_category_limits (organization_id, category_id, max_hpp) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE max_hpp = VALUES(max_hpp)";
        $stmt = $conn->prepare($sql);

        foreach ($data->limits as $item) {
            $cat_id = (int)$item->category_id;
            $max_hpp = (float)$item->max_hpp;
            $stmt->bind_param("iid", $org_id, $cat_id, $max_hpp);
            $stmt->execute();
        }

        $stmt->close();
        $conn->commit();

        echo json_encode(['message' => 'Batas HPP Kategori berhasil disimpan.']);
    }

} catch (Throwable $e) {
    if (isset($conn) && $conn->in_transaction) $conn->rollback();
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
