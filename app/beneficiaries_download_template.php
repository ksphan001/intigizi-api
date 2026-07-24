<?php
// File: app/beneficiaries_download_template.php
// SIASAT BARU: ob_start() akan menangkap semua output (termasuk header JSON dari config.php)
ob_start();

// Hapus 'define()' karena kita menggunakan ob_clean()
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// 1. Ambil data master untuk dropdown
try {
    // Ambil Titik Distribusi (hanya non-dapur utama)
    $stmt_dp = $conn->prepare("SELECT name FROM distribution_points WHERE organization_id = ? AND is_main_kitchen = 0 ORDER BY name ASC");
    $stmt_dp->bind_param("i", $org_id);
    $stmt_dp->execute();
    $result_dp = $stmt_dp->get_result();
    $distribution_points = $result_dp->fetch_all(MYSQLI_ASSOC);
    $stmt_dp->close();
    $dp_list = array_column($distribution_points, 'name');

    // Ambil Kategori Penerima Manfaat
    $stmt_cat = $conn->prepare("SELECT name FROM beneficiary_categories ORDER BY sort_order ASC");
    $stmt_cat->execute();
    $result_cat = $stmt_cat->get_result();
    $categories = $result_cat->fetch_all(MYSQLI_ASSOC);
    $stmt_cat->close();
    $cat_list = array_column($categories, 'name');
    
} catch (Exception $e) {
    // PERBAIKAN: Bersihkan buffer sebelum kirim error
    ob_clean(); 
    http_response_code(500);
    error_log("Gagal mengambil data master untuk template: " . $e->getMessage());
    exit;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Data Penerima');

// 2. Set Header
$headers = [
    'A1' => 'Nama Lengkap (Wajib)',
    'B1' => 'NIK/NISN (Opsional, 16 digit)',
    'C1' => 'Kategori (Wajib)',
    'D1' => 'Nomor Telepon (Opsional)',
    'E1' => 'Alamat Lengkap (Wajib)',
    'F1' => 'Titik Distribusi (Wajib)',
    'G1' => 'Berat Badan (kg) (Opsional)',
    'H1' => 'Tinggi Badan (cm) (Opsional)'
];
foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
    $sheet->getStyle($cell)->getFont()->setBold(true);
    $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFAAAA');
}

// 3. Buat sheet tersembunyi untuk data validasi
$validationSheet = $spreadsheet->createSheet();
$validationSheet->setTitle('DataValidasi');

// Isi data Kategori
$validationSheet->setCellValue('A1', 'Daftar Kategori');
foreach ($cat_list as $index => $category) {
    $validationSheet->setCellValue('A' . ($index + 2), $category);
}
// Isi data Titik Distribusi
$validationSheet->setCellValue('B1', 'Daftar Titik Distribusi');
foreach ($dp_list as $index => $dp) {
    $validationSheet->setCellValue('B' . ($index + 2), $dp);
}
// Sembunyikan sheet
$validationSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN);


// 4. Terapkan Validasi Data
for ($i = 2; $i <= 1000; $i++) { // Terapkan validasi untuk 1000 baris
    // Validasi Teks NIK/NISN (sebagai teks agar 0 di depan tidak hilang)
    $validation_nik = $sheet->getCell('B' . $i)->getDataValidation();
    $validation_nik->setType(DataValidation::TYPE_TEXTLENGTH);
    $validation_nik->setErrorStyle(DataValidation::STYLE_WARNING);
    $validation_nik->setAllowBlank(true);
    $validation_nik->setShowInputMessage(true);
    $validation_nik->setShowErrorMessage(true);
    $validation_nik->setPromptTitle('Input NIK/NISN');
    $validation_nik->setPrompt('Masukkan 16 digit NIK atau NISN. Harap format sebagai teks.');
    $validation_nik->setErrorTitle('Input Error');
    $validation_nik->setError('NIK/NISN harus 16 digit (atau kosongkan).');
    $validation_nik->setFormula1(16);
    $validation_nik->setFormula2(16);
    $sheet->getStyle('B' . $i)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

    // Validasi Dropdown Kategori
    $validation_cat = $sheet->getCell('C' . $i)->getDataValidation();
    $validation_cat->setType(DataValidation::TYPE_LIST);
    $validation_cat->setErrorStyle(DataValidation::STYLE_STOP);
    $validation_cat->setAllowBlank(false);
    $validation_cat->setShowInputMessage(true);
    $validation_cat->setShowErrorMessage(true);
    $validation_cat->setShowDropDown(true);
    $validation_cat->setFormula1('=DataValidasi!$A$2:$A$' . (count($cat_list) + 1));

    // Validasi Dropdown Titik Distribusi
    $validation_dp = $sheet->getCell('F' . $i)->getDataValidation();
    $validation_dp->setType(DataValidation::TYPE_LIST);
    $validation_dp->setErrorStyle(DataValidation::STYLE_STOP);
    $validation_dp->setAllowBlank(false);
    $validation_dp->setShowInputMessage(true);
    $validation_dp->setShowErrorMessage(true);
    $validation_dp->setShowDropDown(true);
    $validation_dp->setFormula1('=DataValidasi!$B$2:$B$' . (count($dp_list) + 1));
    
    // Validasi Angka untuk Berat dan Tinggi
    $validation_weight = $sheet->getCell('G' . $i)->getDataValidation();
    $validation_weight->setType(DataValidation::TYPE_DECIMAL);
    $validation_weight->setAllowBlank(true);
    $sheet->getStyle('G' . $i)->getNumberFormat()->setFormatCode('#,##0.00');

    $validation_height = $sheet->getCell('H' . $i)->getDataValidation();
    $validation_height->setType(DataValidation::TYPE_DECIMAL);
    $validation_height->setAllowBlank(true);
    $sheet->getStyle('H' . $i)->getNumberFormat()->setFormatCode('#,##0.00');
}

// 5. Atur lebar kolom
$sheet->getColumnDimension('A')->setWidth(30);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(40);
$sheet->getColumnDimension('F')->setWidth(30);
$sheet->getColumnDimension('G')->setWidth(18);
$sheet->getColumnDimension('H')->setWidth(18);

// Set sheet aktif kembali ke sheet pertama
$spreadsheet->setActiveSheetIndex(0);

// 6. Kirim file ke browser
$filename = "template_penerima_manfaat_" . date('Ymd') . ".xlsx";

// --- SIASAT BARU: Bersihkan semua output sebelumnya ---
ob_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

// --- SIASAT BARU: Matikan buffer dan kirim output ---
ob_end_flush();
$conn->close();
exit;
?>