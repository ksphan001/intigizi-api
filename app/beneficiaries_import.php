<?php
// File: app/beneficiaries_import.php
// Penjelasan: Diperbarui untuk memproses kolom "NIK/NISN", "Kategori", "Berat Badan", dan "Tinggi Badan".
// Juga akan mencatat riwayat BMI pertama saat impor.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

if (!isset($_FILES['beneficiary_file'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Tidak ada file yang diunggah.']);
    exit;
}

$file = $_FILES['beneficiary_file']['tmp_name'];

try {
    // 1. Load data master (Titik Distribusi & Kategori) untuk validasi
    $stmt_dp = $conn->prepare("SELECT id, name FROM distribution_points WHERE organization_id = ?");
    $stmt_dp->bind_param("i", $org_id);
    $stmt_dp->execute();
    $result_dp = $stmt_dp->get_result();
    $dp_map = [];
    while ($row = $result_dp->fetch_assoc()) {
        $dp_map[strtolower($row['name'])] = $row['id'];
    }
    $stmt_dp->close();

    $stmt_cat = $conn->prepare("SELECT id, name FROM beneficiary_categories");
    $stmt_cat->execute();
    $result_cat = $stmt_cat->get_result();
    $cat_map = [];
    while ($row = $result_cat->fetch_assoc()) {
        $cat_map[strtolower($row['name'])] = $row['id'];
    }
    $stmt_cat->close();

    // 2. Baca file Excel
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestRow();

    $insertedCount = 0;
    $errors = [];

    $conn->begin_transaction();

    // 3. Siapkan prepared statements
    $sql_insert = "INSERT INTO beneficiaries (
                        organization_id, full_name, nik_nisn, category_id, phone_number, address, 
                        distribution_point_id, current_weight_kg, current_height_cm, current_bmi
                   ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_insert = $conn->prepare($sql_insert);
    
    $sql_history = "INSERT INTO beneficiary_bmi_history 
                        (organization_id, beneficiary_id, measurement_date, weight_kg, height_cm, bmi, recorded_by_user_id) 
                    VALUES (?, ?, CURDATE(), ?, ?, ?, ?)";
    $stmt_history = $conn->prepare($sql_history);

    // Loop mulai dari baris 2 (baris 1 adalah header)
    for ($row = 2; $row <= $highestRow; $row++) {
        $full_name = trim($sheet->getCell('A' . $row)->getValue());
        if (empty($full_name)) continue; // Lewati baris kosong

        $nik_nisn = trim($sheet->getCell('B' . $row)->getValue());
        $category_name = strtolower(trim($sheet->getCell('C' . $row)->getValue()));
        $phone_number = trim($sheet->getCell('D' . $row)->getValue());
        $address = trim($sheet->getCell('E' . $row)->getValue());
        $dp_name = strtolower(trim($sheet->getCell('F' . $row)->getValue()));
        $weight_kg = (float)trim($sheet->getCell('G' . $row)->getValue()) ?: null;
        $height_cm = (float)trim($sheet->getCell('H' . $row)->getValue()) ?: null;

        // Validasi
        if (!isset($cat_map[$category_name])) {
            $errors[] = "Baris $row: Kategori '$category_name' tidak valid.";
            continue;
        }
        if (!isset($dp_map[$dp_name])) {
            $errors[] = "Baris $row: Titik Distribusi '$dp_name' tidak valid.";
            continue;
        }
        if (empty($address)) {
            $errors[] = "Baris $row: Alamat wajib diisi.";
            continue;
        }
        
        $category_id = $cat_map[$category_name];
        $dp_id = $dp_map[$dp_name];
        $bmi = null;

        if ($weight_kg > 0 && $height_cm > 0) {
            $height_m = $height_cm / 100;
            $bmi = $weight_kg / ($height_m * $height_m);
        }

        try {
            $stmt_insert->bind_param(
                "ississsdd",
                $org_id, $full_name, $nik_nisn, $category_id, $phone_number, $address,
                $dp_id, $weight_kg, $height_cm, $bmi
            );
            $stmt_insert->execute();
            $beneficiary_id = $conn->insert_id;

            // Jika ada data BMI, masukkan ke riwayat
            if ($beneficiary_id > 0 && $weight_kg > 0 && $height_cm > 0) {
                $stmt_history->bind_param("iidddi", $org_id, $beneficiary_id, $weight_kg, $height_cm, $bmi, $user_id);
                $stmt_history->execute();
            }
            $insertedCount++;

        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) { // Kode error untuk duplicate entry
                $errors[] = "Baris $row: Gagal impor. NIK/NISN '$nik_nisn' mungkin sudah terdaftar.";
            } else {
                $errors[] = "Baris $row: Error database - " . $e->getMessage();
            }
        }
    }

    $stmt_insert->close();
    $stmt_history->close();

    if (!empty($errors)) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode([
            'message' => "Impor gagal dengan $insertedCount data berhasil dan " . count($errors) . " error.",
            'errors' => $errors
        ]);
    } else {
        $conn->commit();
        http_response_code(200);
        echo json_encode(['message' => "Impor berhasil! $insertedCount data penerima manfaat telah ditambahkan."]);
    }

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error saat memproses file.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>