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
    $bapsd_data = null;

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
    $bapsd_data = [ 'sisa_dana' => $sisa_dana_kas ];

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
            .mb-20 { margin-bottom: 80px; }
            .mt-16 { margin-top: 70px; }
            .mt-24 { margin-top: 100px; }
            .font-bold { font-weight: bold; }
            .underline { text-decoration: underline; }
            .flex-container { display: -webkit-box; display: -webkit-flex; display: flex; justify-content: space-between; }
            .flex-item { width: 45%; }
        </style>
    </head>
    <body>
        <h1>Berita Acara Pengalihan Sisa Dana</h1>
        <p class='text-center mb-8'>Nomor: 02/BAPSD/" . date('Y') . "</p>
        <p class='mb-4' style='text-align: justify;'>
            Sehubungan dengan telah berakhirnya periode $periodString, sisa dana sebesar <strong>{$formatCurrency($bapsd_data['sisa_dana'])}</strong> akan dialihkan ke periode selanjutnya.
        </p>
        
        <div class='flex-container mt-24'>
            <div class='flex-item text-center'>
                <p class='mb-20'>Pihak Pertama,</p>
                <p class='font-bold underline'>{$organization_info['director_name']}</p>
                <p>Ketua/Mewakili</p>
            </div>
            <div class='flex-item text-center'>
                <p class='mb-20'>Pihak Kedua,</p>
                <p class='font-bold underline'>(Nama Staf Akuntansi)</p>
                <p>Staf Akuntansi SPPG</p>
            </div>
        </div>
        
        <div class='mt-16 text-center'>
            <p>Mengetahui,</p>
            <p class='mb-20'>Kepala SPPG</p>
            <p class='font-bold underline'>(Nama Kepala SPPG)</p>
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
    $dompdf->stream("BAPSD_" . $organization_info['org_name'] . "_" . $end_date . ".pdf", ["Attachment" => true]);
    exit;

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    error_log("Error generating BAPSD PDF: " . $e->getMessage());
    echo "Terjadi error saat membuat PDF: " . $e->getMessage();
} finally {
    if (isset($conn)) $conn->close();
    ob_end_flush();
}
?>