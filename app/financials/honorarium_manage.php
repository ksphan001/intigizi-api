<?php
// File: app/financials/honorarium_manage.php
// Penjelasan: Diperbarui. Logika GET diimplementasikan untuk mengambil riwayat pembayaran.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../helpers/financial_helper.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];
$method = $_SERVER['REQUEST_METHOD'];

// Keamanan: Hanya Akuntan & Administrator
if (!in_array($userData['role_id'], [3, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    if ($method === 'GET') {
        $start_date = $_GET['start_date'] ?? date('Y-m-01');
        $end_date = $_GET['end_date'] ?? date('Y-m-t');

        $sql = "SELECT 
                    hp.id,
                    hp.payment_date,
                    hp.description,
                    hp.total_amount,
                    v.full_name as volunteer_name,
                    u.full_name as created_by_name
                FROM honorarium_payments hp
                JOIN volunteers v ON hp.volunteer_id = v.id
                LEFT JOIN users u ON hp.created_by = u.id
                WHERE hp.organization_id = ? 
                AND hp.payment_date BETWEEN ? AND ?
                ORDER BY hp.payment_date DESC, hp.id DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $org_id, $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode($result);

    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        if (!isset($data->payment_date) || !isset($data->payments) || !is_array($data->payments) || empty($data->payments) || !isset($data->source_account_id)) {
            throw new Exception('Data tidak lengkap. Tanggal, daftar pembayaran, dan sumber dana wajib diisi.', 400);
        }

        $conn->begin_transaction();
        
        $totalHonorarium = 0;
        $payment_date = $data->payment_date;
        $source_account_id = (int)$data->source_account_id;

        foreach($data->payments as $payment) {
            $totalHonorarium += (float)($payment->total_amount ?? 0);
        }

        if ($totalHonorarium <= 0) {
            throw new Exception("Total pembayaran harus lebih besar dari nol.", 400);
        }
        
        $description = "Pembayaran Honorarium periode " . date('F Y', strtotime($payment_date));
        
        record_transaction(
            $conn, $org_id, $payment_date, $description,
            9, // DEBIT ke Akun "Biaya Tenaga Kerja" (ID 9)
            $source_account_id, // KREDIT dari akun sumber dana (Kas/Bank)
            $totalHonorarium, $user_id, null
        );
        $transaction_id = $conn->insert_id;
        
        $sql = "INSERT INTO honorarium_payments (organization_id, volunteer_id, payment_date, description, honorarium_amount, health_fund_amount, tax_amount, total_amount, transaction_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        foreach($data->payments as $p) {
            $desc_item = "Honorarium untuk " . $p->full_name;
            $stmt->bind_param("iisssdddis", $org_id, $p->volunteer_id, $payment_date, $desc_item, $p->honorarium_amount, $p->health_fund_amount, $p->tax_amount, $p->total_amount, $transaction_id, $user_id);
            $stmt->execute();
        }
        $stmt->close();
        
        $conn->commit();
        http_response_code(201);
        echo json_encode(['message' => 'Pembayaran honorarium berhasil dicatat.']);
    }

} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollback();
    $code = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>

