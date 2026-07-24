<?php
// File: app/distribution_points_create.php
// Penjelasan: API untuk membuat titik distribusi baru.
// Logika di dalamnya menangani penyimpanan data ke dua tabel:
// 1. `distribution_points` untuk data utama.
// 2. `distribution_point_counts` untuk rincian jumlah per kategori.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->name) || !isset($data->address)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama dan alamat wajib diisi.']);
    exit();
}

$conn->begin_transaction();

try {
    // 1. Simpan data utama ke tabel distribution_points
    $sql = "INSERT INTO distribution_points (organization_id, name, address, pic_name, pic_phone, latitude, longitude, is_main_kitchen) VALUES (?, ?, ?, ?, ?, ?, ?, 0)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "issssdd", 
        $org_id, 
        $data->name, 
        $data->address, 
        $data->pic_name, 
        $data->pic_phone, 
        $data->latitude, 
        $data->longitude
    );
    $stmt->execute();
    $point_id = $conn->insert_id;
    if ($point_id === 0) throw new Exception("Gagal menyimpan data utama titik distribusi.");
    $stmt->close();

    // 2. Simpan rincian jumlah per kategori ke tabel distribution_point_counts
    if (isset($data->category_counts) && is_array($data->category_counts)) {
        $countSql = "INSERT INTO distribution_point_counts (distribution_point_id, category_id, count) VALUES (?, ?, ?)";
        $countStmt = $conn->prepare($countSql);
        foreach ($data->category_counts as $item) {
            $countStmt->bind_param("iii", $point_id, $item->category_id, $item->count);
            $countStmt->execute();
        }
        $countStmt->close();
    }

    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Titik distribusi berhasil ditambahkan.']);

} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => "Nama titik distribusi '{$data->name}' sudah ada."]);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal menambahkan titik distribusi: ' . $e->getMessage()]);
    }
} finally {
    if(isset($conn)) $conn->close();
}
?>
