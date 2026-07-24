<?php
// File: app/proposal_menus_add.php
// PENJELASAN: File ini ditulis ulang sepenuhnya untuk mengatasi masalah "Hari Libur".
// 1. Logika "self-healing" ditambahkan untuk memastikan menu "Hari Libur" (dengan id=1) ada di database.
//    Jika tidak ada, skrip akan membuatnya secara otomatis untuk mencegah error.
// 2. Logika UPSERT (Update or Insert) yang efisien digunakan untuk menangani penjadwalan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$data = json_decode(file_get_contents("php://input"));

// Validasi input dasar
if (!isset($data->proposal_id) || !isset($data->menu_id) || empty($data->serving_dates) || !is_array($data->serving_dates)) {
    http_response_code(400);
    echo json_encode(['message' => 'proposal_id, menu_id, dan array serving_dates yang tidak kosong wajib diisi.']);
    exit();
}

$proposal_id = (int)$data->proposal_id;
$menu_id = (int)$data->menu_id;
$serving_dates = $data->serving_dates;

$conn->begin_transaction();

try {
    // 1. Validasi proposal: Pastikan ada, milik organisasi, dan statusnya 'Draft'.
    $propSql = "SELECT start_date, end_date FROM proposals WHERE id = ? AND organization_id = ? AND status = 'Draft'";
    $propStmt = $conn->prepare($propSql);
    $propStmt->bind_param("ii", $proposal_id, $org_id);
    $propStmt->execute();
    $propResult = $propStmt->get_result();
    if ($propResult->num_rows === 0) {
        throw new Exception('Proposal tidak ditemukan, bukan milik Anda, atau statusnya bukan Draft.', 403);
    }
    $proposal = $propResult->fetch_assoc();
    $propStmt->close();

    // --- PERBAIKAN UTAMA (SELF-HEALING) ---
    // Jika menu yang dipilih adalah Hari Libur (ID 1), pastikan data masternya ada.
    if ($menu_id === 1) {
        $checkHolidaySql = "SELECT id FROM menus WHERE id = 1";
        if ($conn->query($checkHolidaySql)->num_rows === 0) {
            // Jika tidak ada, buat sekarang juga. ID 8 diasumsikan sebagai Super Admin.
            // Ini mencegah error foreign key constraint.
            $insertHolidaySql = "INSERT INTO menus (id, menu_name, created_by, organization_id) VALUES (1, '-- HARI LIBUR --', 8, NULL)";
            if (!$conn->query($insertHolidaySql)) {
                throw new Exception("Gagal menginisialisasi data master 'Hari Libur'.");
            }
        }
    }
    // --- AKHIR PERBAIKAN ---

    // 2. Siapkan statement untuk pengecekan, update, dan insert (Logika UPSERT)
    $checkSql = "SELECT id FROM proposal_menus WHERE proposal_id = ? AND serving_date = ?";
    $checkStmt = $conn->prepare($checkSql);

    $updateSql = "UPDATE proposal_menus SET menu_id = ? WHERE proposal_id = ? AND serving_date = ?";
    $updateStmt = $conn->prepare($updateSql);

    $insertSql = "INSERT INTO proposal_menus (organization_id, proposal_id, menu_id, serving_date) VALUES (?, ?, ?, ?)";
    $insertStmt = $conn->prepare($insertSql);

    // 3. Lakukan proses UPSERT untuk setiap tanggal yang dipilih
    foreach ($serving_dates as $date) {
        // Validasi setiap tanggal harus berada dalam rentang proposal
        if ($date < $proposal['start_date'] || $date > $proposal['end_date']) {
            throw new Exception("Tanggal {$date} berada di luar rentang proposal.", 400);
        }

        // Cek apakah sudah ada jadwal untuk tanggal ini
        $checkStmt->bind_param("is", $proposal_id, $date);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            // Jika ada, UPDATE menu_id yang ada
            $updateStmt->bind_param("iis", $menu_id, $proposal_id, $date);
            if(!$updateStmt->execute()){
                 throw new Exception("Gagal memperbarui jadwal untuk tanggal {$date}: " . $updateStmt->error);
            }
        } else {
            // Jika tidak ada, INSERT jadwal baru
            $insertStmt->bind_param("iiis", $org_id, $proposal_id, $menu_id, $date);
            if(!$insertStmt->execute()){
                throw new Exception("Gagal menambahkan jadwal untuk tanggal {$date}: " . $insertStmt->error);
            }
        }
    }

    $checkStmt->close();
    $updateStmt->close();
    $insertStmt->close();

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Jadwal berhasil diperbarui.']);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Gagal memperbarui jadwal: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
