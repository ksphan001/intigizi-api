<?php
define('IS_FILE_DOWNLOAD', true); // Beri tahu config.php ini adalah file download
ob_start(); // Mulai output buffering

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$periodString = date('d M Y', strtotime($start_date)) . ' - ' . date('d M Y', strtotime($end_date));

try {
    // 1. Ambil Data (Logika disalin dari reports_get_printable_data.php)
    $organization_info = null;
    $lpa_data = null;

    $org_sql = "SELECT o.name as org_name, o.director_name FROM organizations o WHERE o.id = ?";
    $org_stmt = $conn->prepare($org_sql);
    $org_stmt->bind_param("i", $org_id);
    $org_stmt->execute();
    $organization_info = $org_stmt->get_result()->fetch_assoc();
    $org_stmt->close();
    
    // Kalkulasi "Dana Diajukan" (Estimasi Anggaran)
    $countsSql = "SELECT dpc.category_id, SUM(dpc.count) as total_count FROM distribution_point_counts dpc JOIN distribution_points dp ON dpc.distribution_point_id = dp.id WHERE dp.organization_id = ? GROUP BY dpc.category_id";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->bind_param("i", $org_id);
    $countsStmt->execute();
    $category_totals_result = $countsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $countsStmt->close();
    $beneficiary_counts = [];
    foreach($category_totals_result as $row) { $beneficiary_counts[$row['category_id']] = (int)$row['total_count']; }

    $recipesSql = "
        SELECT mi.quantity_per_portion, i.latest_price, u.conversion_factor, nd.bdd_percentage
        FROM proposal_menus pm
        JOIN proposals p ON pm.proposal_id = p.id
        JOIN menu_ingredients mi ON pm.menu_id = mi.menu_id AND mi.organization_id = p.organization_id
        JOIN ingredients i ON mi.ingredient_id = i.id AND i.organization_id = p.organization_id
        JOIN units u ON i.unit_id = u.id
        LEFT JOIN nutrition_data nd ON i.id = nd.ingredient_id AND i.organization_id = nd.organization_id
        WHERE p.organization_id = ? AND p.status = 'Disetujui' AND pm.serving_date BETWEEN ? AND ? AND pm.menu_id != 1
    ";
    $recipe_stmt = $conn->prepare($recipesSql);
    $recipe_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $recipe_stmt->execute();
    $ingredients = $recipe_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recipe_stmt->close();
    
    $dana_diajukan = 0;
    foreach ($ingredients as $ingredient) {
        $portions = json_decode($ingredient['quantity_per_portion'], true);
        $bdd_factor = (float)($ingredient['bdd_percentage'] ?? 1.00);
        if ($bdd_factor <= 0) $bdd_factor = 1.00;
        $grams_needed_net = 0;
        if (is_array($portions)) {
            foreach ($beneficiary_counts as $cat_id => $count) { 
                $grams_needed_net += (float)($portions[$cat_id] ?? 0) * $count; 
            }
        }
        $grams_needed_gross = $grams_needed_net / $bdd_factor;
        if ($ingredient['conversion_factor'] > 0) {
            $price_per_gram = (float)$ingredient['latest_price'] / (float)$ingredient['conversion_factor'];
            $dana_diajukan += $grams_needed_gross * $price_per_gram;
        }
    }
    
    // Kalkulasi Dana Terealisasi (Pengeluaran)
    $realisasi_sql = "SELECT fa.id as account_id, COALESCE(SUM(ft.amount), 0) as total
                      FROM financial_transactions ft
                      JOIN financial_accounts fa ON ft.debit_account_id = fa.id
                      WHERE ft.organization_id = ? AND ft.transaction_date BETWEEN ? AND ?
                      AND fa.type = 'Biaya'
                      GROUP BY fa.id";
    $real_stmt = $conn->prepare($realisasi_sql);
    $real_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $real_stmt->execute();
    $realisasi_result = $real_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $real_stmt->close();

    $realisasi_bahan = 0; $realisasi_ops = 0; $realisasi_sewa = 0; $realisasi_tenaga_kerja = 0;
    foreach($realisasi_result as $row){
        if ($row['account_id'] == 4) $realisasi_bahan = (float)$row['total'];
        if ($row['account_id'] == 5) $realisasi_ops = (float)$row['total'];
        if ($row['account_id'] == 6) $realisasi_sewa = (float)$row['total'];
        if ($row['account_id'] == 9) $realisasi_tenaga_kerja = (float)$row['total'];
    }
    $total_realisasi = $realisasi_bahan + $realisasi_ops + $realisasi_sewa + $realisasi_tenaga_kerja;

    $lpa_data = [
        'dana_diajukan' => ['bahan' => $dana_diajukan, 'operasional' => 0, 'sewa' => 0, 'tenaga_kerja' => 0, 'total' => $dana_diajukan],
        'dana_terealisasi' => ['bahan' => $realisasi_bahan, 'operasional' => $realisasi_ops, 'sewa' => $realisasi_sewa, 'tenaga_kerja' => $realisasi_tenaga_kerja, 'total' => $total_realisasi],
        'sisa_dana' => ['total' => $dana_diajukan - $total_realisasi]
    ];
    
    // Helper
    $formatCurrency = function($val) {
        return number_format($val ?: 0, 0, ',', '.');
    };

    // 2. Buat HTML
    $html = "
    <html>
    <head>
        <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
        <style>
            @page { margin: 20mm; size: A4 portrait; }
            body { font-family: 'serif', Times, serif; font-size: 11pt; color: #000; }
            h1, h2, h3, p, table { margin: 0 0 15px 0; padding: 0; }
            h1 { font-size: 16pt; text-align: center; text-transform: uppercase; }
            h2 { font-size: 14pt; text-align: center; text-transform: uppercase; }
            p { line-height: 1.5; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .font-bold { font-weight: bold; }
            .uppercase { text-transform: uppercase; }
            .underline { text-decoration: underline; }
            .mb-20 { margin-bottom: 80px; }
            .mb-4 { margin-bottom: 20px; }
            .mb-6 { margin-bottom: 30px; }
            .mb-2 { margin-bottom: 10px; }
            .mt-24 { margin-top: 100px; }
            .w-1-4 { width: 25%; }
            .w-full { width: 100%; }
            .justify-end { text-align: right; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid black; padding: 5px 8px; }
            thead th { background-color: #f3f4f6; }
            tfoot td { background-color: #f3f4f6; font-weight: bold; }
        </style>
    </head>
    <body>
        <h1>Laporan Penggunaan Anggaran</h1>
        <p class='text-center mb-6'>Nomor: 01/LPA/" . date('Y') . "</p>
        <p class='mb-4'>Periode: $periodString</p>
        <p class='mb-2'>Yang bertanda tangan di bawah ini:</p>
        <table class='w-full text-sm mb-4' style='border: none;'>
            <tr style='border: none;'><td style='border: none; width: 25%;'>Nama</td><td style='border: none;'>: {$organization_info['director_name']}</td></tr>
            <tr style='border: none;'><td style='border: none;'>Jabatan</td><td style='border: none;'>: Ketua Yayasan</td></tr>
            <tr style='border: none;'><td style='border: none;'>Yayasan/SPPG</td><td style='border: none;'>: {$organization_info['org_name']}</td></tr>
        </table>
        <p class='mb-4'>Dengan ini menyatakan bahwa laporan penggunaan dana sebagai berikut:</p>
        <p class='font-bold mb-2'>I. RINCIAN KEGIATAN</p>
        <table class='w-full text-sm border-collapse border border-black mb-4'>
            <thead>
                <tr class='bg-gray-100'>
                    <th class='border border-black p-2'>Kegiatan</th>
                    <th class='border border-black p-2'>Dana Diajukan (Rp)</th>
                    <th class='border border-black p-2'>Dana Terealisasi</th>
                    <th class='border border-black p-2'>Sisa Dana (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class='border border-black p-2'>Bahan Baku</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['bahan'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_terealisasi']['bahan'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['bahan'] - $lpa_data['dana_terealisasi']['bahan'])}</td>
                </tr>
                <tr>
                    <td class='border border-black p-2'>Operasional</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['operasional'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_terealisasi']['operasional'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['operasional'] - $lpa_data['dana_terealisasi']['operasional'])}</td>
                </tr>
                <tr>
                    <td class='border border-black p-2'>Tenaga Kerja</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['tenaga_kerja'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_terealisasi']['tenaga_kerja'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['tenaga_kerja'] - $lpa_data['dana_terealisasi']['tenaga_kerja'])}</td>
                </tr>
                <tr>
                    <td class='border border-black p-2'>Sewa</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['sewa'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_terealisasi']['sewa'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['sewa'] - $lpa_data['dana_terealisasi']['sewa'])}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class='font-bold bg-gray-100'>
                    <td class='border border-black p-2 text-center'>Total</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_diajukan']['total'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['dana_terealisasi']['total'])}</td>
                    <td class='border border-black p-2 text-right'>{$formatCurrency($lpa_data['sisa_dana']['total'])}</td>
                </tr>
            </tfoot>
        </table>
        <div class='justify-end mt-24' style='text-align: right; width: 100%;'>
            <div class='text-center' style='display: inline-block; text-align: center;'>
                <p>" . date('d F Y') . "</p>
                <p class='mb-20'>Ketua Yayasan,</p>
                <p class='font-bold underline'>{$organization_info['director_name']}</p>
            </div>
        </div>
    </body>
    </html>
    ";

    // 3. Render PDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    ob_clean(); // Hapus semua output sebelumnya
    $dompdf->stream("LPA_" . $organization_info['org_name'] . "_" . $end_date . ".pdf", ["Attachment" => true]);
    exit;

} catch (Throwable $e) {
    ob_clean(); // Hapus buffer jika terjadi error
    http_response_code(500);
    error_log("Error generating LPA PDF: " . $e->getMessage());
    echo "Terjadi error saat membuat PDF: " . $e->getMessage();
} finally {
    if (isset($conn)) $conn->close();
    ob_end_flush(); // Kirim output (baik PDF atau pesan error)
}
?>