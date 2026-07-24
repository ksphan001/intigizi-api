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
    $sptj_data = null;

    $org_sql = "SELECT o.name as org_name, o.director_name FROM organizations o WHERE o.id = ?";
    $org_stmt = $conn->prepare($org_sql);
    $org_stmt->bind_param("i", $org_id);
    $org_stmt->execute();
    $organization_info = $org_stmt->get_result()->fetch_assoc();
    $org_stmt->close();
    
    $penerimaan_sql = "SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE organization_id = ? AND transaction_date BETWEEN ? AND ? AND debit_account_id IN (1,2)";
    $penerimaan_stmt = $conn->prepare($penerimaan_sql);
    $penerimaan_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $penerimaan_stmt->execute();
    $total_penerimaan = (float)$penerimaan_stmt->get_result()->fetch_assoc()['total'];
    $penerimaan_stmt->close();

    $pengeluaran_sql = "SELECT COALESCE(SUM(amount), 0) as total FROM financial_transactions WHERE organization_id = ? AND transaction_date BETWEEN ? AND ? AND credit_account_id IN (1,2)";
    $pengeluaran_stmt = $conn->prepare($pengeluaran_sql);
    $pengeluaran_stmt->bind_param("iss", $org_id, $start_date, $end_date);
    $pengeluaran_stmt->execute();
    $total_pengeluaran = (float)$pengeluaran_stmt->get_result()->fetch_assoc()['total'];
    $pengeluaran_stmt->close();
    
    $sisa_dana_kas = $total_penerimaan - $total_pengeluaran;
    $sptj_data = [ 'total_penerimaan' => $total_penerimaan, 'total_pengeluaran' => $total_pengeluaran, 'sisa_dana' => $sisa_dana_kas ];

    // Helper
    $formatCurrency = function($val) {
        return 'Rp ' . number_format($val ?: 0, 0, ',', '.');
    };

    // 2. Buat HTML
    $html = "
    <html>
    <head>
        <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
        <style>
            @page { margin: 20mm; size: A4 portrait; }
            body { font-family: 'serif', Times, serif; font-size: 11pt; color: #000; }
            h1 { font-size: 16pt; text-align: center; text-transform: uppercase; }
            p { line-height: 1.5; margin-bottom: 15px; }
            .text-center { text-align: center; }
            .mb-8 { margin-bottom: 40px; }
            .mb-4 { margin-bottom: 20px; }
            .w-1-4 { width: 25%; }
            .w-2-5 { width: 40%; }
            .w-full { width: 100%; }
            .justify-end { text-align: right; }
            .font-bold { font-weight: bold; }
            .underline { text-decoration: underline; }
            .mb-20 { margin-bottom: 80px; }
            .mt-24 { margin-top: 100px; }
            .pt-2 { padding-top: 8px; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 5px 0; }
            .border-t { border-top: 1px solid black; }
        </style>
    </head>
    <body>
        <h1>Surat Pernyataan Tanggung Jawab</h1>
        <p class='text-center mb-8'>Periode: $periodString</p>
        <p class='mb-4'>Saya yang bertanda tangan di bawah ini:</p>
        <table class='w-full text-sm mb-4'>
            <tr><td class='w-1-4'>Nama</td><td>: {$organization_info['director_name']}</td></tr>
            <tr><td>Jabatan</td><td>: Kepala {$organization_info['org_name']}</td></tr>
        </table>
        <p class='mb-4' style='text-align: justify;'>Menyatakan bertanggung jawab secara formal dan material atas penerimaan dan pengeluaran dana yang dilaksanakan dengan menggunakan dana APBN TA " . date('Y') . " melalui DIPA Badan Gizi Nasional, dengan rincian sebagai berikut:</p>
        <table class='w-full' style='margin: 30px 0;'>
            <tr><td class='w-2-5'>1. Jumlah Penerimaan</td><td>: {$formatCurrency($sptj_data['total_penerimaan'])}</td></tr>
            <tr><td>2. Jumlah Pengeluaran</td><td>: {$formatCurrency($sptj_data['total_pengeluaran'])}</td></tr>
            <tr class='font-bold border-t'><td class='pt-2'>3. Sisa Dana</td><td class='pt-2'>: {$formatCurrency($sptj_data['sisa_dana'])}</td></tr>
        </table>
        <p style='text-align: justify;'>Demikian surat ini saya buat untuk dapat dipergunakan sebagaimana mestinya dan untuk dapat dipertanggungjawabkan.</p>
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
    $dompdf->stream("SPTJ_" . $organization_info['org_name'] . "_" . $end_date . ".pdf", ["Attachment" => true]);
    exit;

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    error_log("Error generating SPTJ PDF: " . $e->getMessage());
    echo "Terjadi error saat membuat PDF: " . $e->getMessage();
} finally {
    if (isset($conn)) $conn->close();
    ob_end_flush();
}
?>