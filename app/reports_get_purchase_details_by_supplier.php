<?php
// File: app/reports_get_purchase_details_by_supplier.php
// Penjelasan: API endpoint BARU untuk mengambil rincian PO
// berdasarkan nama pemasok untuk fitur drill-down di laporan pembelian.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Ambil parameter dari query string
$supplier_name = isset($_GET['supplier_name']) ? $conn->real_escape_string($_GET['supplier_name']) : '';
$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : '';

if (empty($supplier_name) || empty($start_date) || empty($end_date)) {
    http_response_code(400);
    echo json_encode(['message' => 'Parameter supplier_name, start_date, dan end_date wajib diisi.']);
    exit();
}

try {
    // 1. Cari ID pemasok berdasarkan nama. Bisa dari tabel suppliers (internal) atau organizations (vendor eksternal)
    $supplier_id = null;
    
    // Cek di tabel suppliers internal dulu
    $stmt_s = $conn->prepare("SELECT id FROM suppliers WHERE supplier_name = ? AND organization_id = ?");
    $stmt_s->bind_param("si", $supplier_name, $org_id);
    $stmt_s->execute();
    $result_s = $stmt_s->get_result();
    if($result_s->num_rows > 0) {
        $supplier_id = $result_s->fetch_assoc()['id'];
    }
    $stmt_s->close();

    // Jika tidak ketemu, cek di tabel organizations (vendor)
    if(!$supplier_id) {
        $stmt_o = $conn->prepare("SELECT id FROM organizations WHERE name = ? AND registration_type = 'Vendor'");
        $stmt_o->bind_param("s", $supplier_name);
        $stmt_o->execute();
        $result_o = $stmt_o->get_result();
        if($result_o->num_rows > 0) {
            $supplier_id = $result_o->fetch_assoc()['id'];
        }
        $stmt_o->close();
    }
    
    if (!$supplier_id) {
        http_response_code(404);
        echo json_encode(['message' => 'Pemasok tidak ditemukan.']);
        exit();
    }

    // 2. Ambil semua PO yang relevan
    $sql = "SELECT 
                po.id,
                po.po_code,
                po.total_amount,
                po.created_at,
                COALESCE(p.proposal_code, 'PO Manual') as proposal_code,
                p.id as proposal_id
            FROM 
                purchase_orders po
            LEFT JOIN
                proposals p ON po.proposal_id = p.id
            WHERE 
                po.organization_id = ? 
                AND po.supplier_id = ?
                AND DATE(po.created_at) BETWEEN ? AND ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $org_id, $supplier_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $details = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($details);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Terjadi kesalahan saat mengambil rincian pembelian.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        if (isset($stmt)) $stmt->close();
        $conn->close();
    }
}
?>
