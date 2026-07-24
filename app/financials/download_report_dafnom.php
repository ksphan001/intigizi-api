<?php
define('IS_FILE_DOWNLOAD', true);
ob_start();

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
    // 1. Ambil Data
    $organization_info = null;
    $dafnom_data = null;

    $org_sql = "SELECT o.name as org_name, o.director_name FROM organizations o WHERE o.id = ?";
    $org_stmt = $conn->prepare($org_sql);
    $org_stmt->bind_param("i", $org_id);
    $org_stmt->execute();
    $organization_info = $org_stmt->get_result()->fetch_assoc();
    $org_stmt->close();
    
    $dafnom_sql = "SELECT v.full_name, v.job_type, hp.honorarium_amount, hp.health_fund_amount, hp.tax_amount, hp.total_amount
                   FROM honorarium_payments hp
                   JOIN volunteers v ON hp.volunteer_id = v.id
                   WHERE hp.organization_id = ? AND hp.payment_date BETWEEN ? AND ?";
    $dafnom_stmt = $conn->prepare($dafnom_sql);
    $dafnom_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $dafnom_stmt->execute();
    $dafnom_data = $dafnom_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dafnom_stmt->close();

    $formatCurrency = function($val) {
        return number_format($val ?: 0, 0, ',', '.');
    };
    $total_keseluruhan = 0;

    // 2. Buat HTML
    $html = "
    <html>
    <head>
        <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
        <style>
            @page { margin: 20mm; size: A4 portrait; }
            body { font-family: 'serif', Times, serif; font-size: 10pt; color: #000; }
            h1 { font-size: 14pt; text-align: center; text-transform: uppercase; }
            h2 { font-size: 12pt; text-align: center; text-transform: uppercase; }
            p { line-height: 1.5; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .font-bold { font-weight: bold; }
            .uppercase { text-transform: uppercase; }
            .underline { text-decoration: underline; }
            .mb-6 { margin-bottom: 30px; }
            .mb-20 { margin-bottom: 80px; }
            .mt-24 { margin-top: 100px; }
            .w-full { width: 100%; }
            .justify-end { text-align: right; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid black; padding: 4px; }
            thead th { background-color: #f3f4f6; text-align: center; }
            tfoot td { background-color: #f3f4f6; font-weight: bold; }
        </style>
    </head>
    <body>
        <h1>DAFTAR NOMINATIF</h1>
        <h2 class='mb-6'>PEMBAYARAN UPAH SUKARELAWAN</h2>
        <p>PERIODE: $periodString</p>
        
        <table class='w-full text-xs border-collapse border border-black mb-4'>
            <thead>
                <tr class='bg-gray-100 font-bold'>
                    <th class='border border-black p-1'>No</th>
                    <th class='border border-black p-1'>Nama</th>
                    <th class='border border-black p-1'>Pekerjaan</th>
                    <th class='border border-black p-1 text-right'>Honorarium (Rp)</th>
                    <th class='border border-black p-1 text-right'>Dana Kesehatan (Rp)</th>
                    <th class='border border-black p-1 text-right'>Pajak (Rp)</th>
                    <th class='border border-black p-1 text-right'>Total Diterima (Rp)</th>
                </tr>
            </thead>
            <tbody>";
    
    foreach ($dafnom_data as $index => $item) {
        $total_keseluruhan += (float)$item['total_amount'];
        $html .= "
            <tr>
                <td class='border border-black p-1 text-center'>" . ($index + 1) . "</td>
                <td class='border border-black p-1'>{$item['full_name']}</td>
                <td class='border border-black p-1'>{$item['job_type']}</td>
                <td class='border border-black p-1 text-right'>{$formatCurrency($item['honorarium_amount'])}</td>
                <td class='border border-black p-1 text-right'>{$formatCurrency($item['health_fund_amount'])}</td>
                <td class='border border-black p-1 text-right'>{$formatCurrency($item['tax_amount'])}</td>
                <td class='border border-black p-1 text-right font-semibold'>{$formatCurrency($item['total_amount'])}</td>
            </tr>
        ";
    }

    $html .= "
            </tbody>
             <tfoot>
                <tr class='font-bold bg-gray-100'>
                    <td colSpan='6' class='border border-black p-1 text-center'>TOTAL KESELURUHAN</td>
                    <td class='border border-black p-1 text-right'>{$formatCurrency($total_keseluruhan)}</td>
                </tr>
            </tfoot>
        </table>

        <div class='justify-end mt-24' style='text-align: right; width: 100%;'>
            <div class='text-center' style='display: inline-block; text-align: center;'>
                <p>" . date('d F Y') . "</p>
                <p class='mb-20'>Kepala {$organization_info['org_name']},</p>
                <p class='font-bold underline'>{$organization_info['director_name']}</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // 3. Render PDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    ob_clean();
    $dompdf->stream("DafNom_" . $organization_info['org_name'] . "_" . $end_date . ".pdf", ["Attachment" => true]);
    exit;

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    error_log("Error generating DafNom PDF: " . $e->getMessage());
    echo "Terjadi error saat membuat PDF: " . $e->getMessage();
} finally {
    if (isset($conn)) $conn->close();
    ob_end_flush();
}
?>