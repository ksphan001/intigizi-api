<?php
// File: app/supplier_ingredients_manage.php
// Penjelasan: Mengelola katalog bahan baku yang disediakan oleh supplier, harga dasar, dan kapasitas harian.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->action)) {
    // Fallback ke GET jika dipanggil lewat query parameter
    $action = $_SERVER['REQUEST_METHOD'] === 'GET' ? 'get' : '';
} else {
    $action = $data->action;
}

$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : (isset($data->supplier_id) ? (int)$data->supplier_id : 0);

if ($supplier_id <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'ID Supplier wajib diisi.']);
    exit();
}

// Cek apakah supplier ini berasal dari marketplace untuk tindakan modifikasi (tulis/save)
if ($action !== 'get') {
    $checkSql = "SELECT marketplace_id FROM suppliers WHERE id = ? AND organization_id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $supplier_id, $org_id);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($existing && !empty($existing['marketplace_id'])) {
        http_response_code(403);
        echo json_encode(['message' => 'Akses ditolak. Katalog supplier dari Marketplace terpusat tidak dapat diubah dari dapur lokal.']);
        exit();
    }
}

try {
    if ($action === 'get') {
        $sql = "SELECT 
                    i.id as ingredient_id,
                    i.name as ingredient_name,
                    u.symbol as default_unit_symbol,
                    COALESCE(si.base_price, i.latest_price) as base_price,
                    COALESCE(si.daily_capacity, 0.00) as daily_capacity,
                    CASE WHEN si.id IS NOT NULL THEN 1 ELSE 0 END as is_supplied
                FROM ingredients i
                JOIN units u ON i.unit_id = u.id
                LEFT JOIN supplier_ingredients si ON i.id = si.ingredient_id AND si.supplier_id = ?
                WHERE i.organization_id = ?
                ORDER BY i.name ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $supplier_id, $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        http_response_code(200);
        echo json_encode($items);
        
    } elseif ($action === 'add_single') {
        if (!isset($data->ingredient_id) || !isset($data->base_price)) {
            throw new Exception("Bahan baku dan harga wajib diisi.");
        }
        $ingredient_id = (int)$data->ingredient_id;
        $base_price = (float)$data->base_price;
        $daily_capacity = isset($data->daily_capacity) ? (float)$data->daily_capacity : 9999.00;
        
        $checkSql = "SELECT id FROM supplier_ingredients WHERE supplier_id = ? AND ingredient_id = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("ii", $supplier_id, $ingredient_id);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($exists) {
            $updateSql = "UPDATE supplier_ingredients SET base_price = ? WHERE supplier_id = ? AND ingredient_id = ?";
            $upStmt = $conn->prepare($updateSql);
            $upStmt->bind_param("dii", $base_price, $supplier_id, $ingredient_id);
            $upStmt->execute();
            $upStmt->close();
        } else {
            $insertSql = "INSERT INTO supplier_ingredients (supplier_id, ingredient_id, base_price, daily_capacity) VALUES (?, ?, ?, ?)";
            $insStmt = $conn->prepare($insertSql);
            $insStmt->bind_param("iidd", $supplier_id, $ingredient_id, $base_price, $daily_capacity);
            $insStmt->execute();
            $insStmt->close();
        }
        
        http_response_code(200);
        echo json_encode(['message' => 'Berhasil menghubungkan bahan baku ke supplier.']);
        
    } elseif ($action === 'save') {
        if (!isset($data->items) || !is_array($data->items)) {
            throw new Exception("Daftar items bahan baku wajib dilampirkan.");
        }
        
        $conn->begin_transaction();
        
        // 1. Hapus pemetaan yang lama untuk supplier ini
        $deleteSql = "DELETE FROM supplier_ingredients WHERE supplier_id = ?";
        $delStmt = $conn->prepare($deleteSql);
        $delStmt->bind_param("i", $supplier_id);
        $delStmt->execute();
        $delStmt->close();
        
        // 2. Insert pemetaan baru yang dicentang (is_supplied = 1)
        $insertSql = "INSERT INTO supplier_ingredients (supplier_id, ingredient_id, base_price, daily_capacity, unit_symbol) VALUES (?, ?, ?, ?, ?)";
        $insStmt = $conn->prepare($insertSql);
        
        foreach ($data->items as $row) {
            if (empty($row->is_supplied)) continue;
            
            $ingredient_id = (int)$row->ingredient_id;
            $base_price = (float)$row->base_price;
            $daily_capacity = (float)$row->daily_capacity;
            $unit_symbol = isset($row->default_unit_symbol) ? $conn->real_escape_string($row->default_unit_symbol) : '';
            
            $insStmt->bind_param("iiddd", $supplier_id, $ingredient_id, $base_price, $daily_capacity, $unit_symbol);
            $insStmt->execute();
        }
        
        $insStmt->close();
        $conn->commit();
        
        http_response_code(200);
        echo json_encode(['message' => 'Katalog bahan baku supplier berhasil diperbarui.']);
    } else {
        http_response_code(400);
        echo json_encode(['message' => 'Aksi tidak valid.']);
    }
} catch (Throwable $e) {
    if ($action === 'save' && isset($conn)) $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>
