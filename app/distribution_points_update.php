<?php
// File: app/distribution_points_update.php
// Penjelasan: API untuk memperbarui data titik distribusi.
// Logikanya adalah:
// 1. Update data utama di `distribution_points`.
// 2. Hapus semua data jumlah kategori yang lama.
// 3. Masukkan kembali data jumlah kategori yang baru.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->id) || !isset($data->name) || !isset($data->address)) {
    http_response_code(400);
    echo json_encode(['message' => 'ID, nama, dan alamat wajib diisi.']);
    exit();
}

$conn->begin_transaction();

try {
    // 1. Update data utama di tabel distribution_points
    $sql = "UPDATE distribution_points SET name = ?, address = ?, pic_name = ?, pic_phone = ?, latitude = ?, longitude = ? WHERE id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssddii", 
        $data->name, 
        $data->address, 
        $data->pic_name, 
        $data->pic_phone, 
        $data->latitude, 
        $data->longitude, 
        $data->id, 
        $org_id
    );
    $stmt->execute();
    $stmt->close();
    
    // 2. Hapus semua data jumlah kategori yang lama untuk titik ini
    $deleteSql = "DELETE FROM distribution_point_counts WHERE distribution_point_id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->bind_param("i", $data->id);
    $deleteStmt->execute();
    $deleteStmt->close();

    // 3. Masukkan kembali data jumlah kategori yang baru
    if (isset($data->category_counts) && is_array($data->category_counts)) {
        $countSql = "INSERT INTO distribution_point_counts (distribution_point_id, category_id, count) VALUES (?, ?, ?)";
        $countStmt = $conn->prepare($countSql);
        foreach ($data->category_counts as $item) {
            if (!empty($item->count)) { // Hanya simpan jika ada jumlahnya
                $countStmt->bind_param("iii", $data->id, $item->category_id, $item->count);
                $countStmt->execute();
            }
        }
        $countStmt->close();
    }
    
    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Data titik distribusi berhasil diperbarui.']);

} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => "Nama titik distribusi '{$data->name}' sudah ada."]);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui data: ' . $e->getMessage()]);
    }
} finally {
    if(isset($conn)) $conn->close();
}
?>
