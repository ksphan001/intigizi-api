<?php
// File: app/supplier_dashboard_summary.php
// PENJELASAN: API baru ini dibuat khusus untuk dasbor Supplier Internal.
// Logikanya mencari tahu ID supplier berdasarkan user yang login, lalu
// mengambil data PO yang relevan dengan ID supplier tersebut.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];

// Keamanan: Pastikan hanya user dengan peran Supplier (5) dan BUKAN vendor eksternal
if ($userData['role_id'] != 5 || (isset($userData['org_type']) && $userData['org_type'] === 'Vendor')) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak untuk peran ini.']);
    exit();
}

try {
    // 1. Cari supplier_id berdasarkan user_id yang sedang login
    $supplierIdSql = "SELECT id FROM suppliers WHERE user_id = ? AND organization_id = ?";
    $stmt = $conn->prepare($supplierIdSql);
    $stmt->bind_param("ii", $user_id, $org_id);
    $stmt->execute();
    $supplierResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$supplierResult) {
        throw new Exception("Data supplier untuk pengguna ini tidak ditemukan.", 404);
    }
    $supplier_id = (int)$supplierResult['id'];

    // Inisialisasi respons
    $response = [
        'pending_orders' => 0,
        'confirmed_orders' => 0,
        'recent_orders' => []
    ];

    // 2. Hitung PO yang menunggu konfirmasi
    $pendingSql = "SELECT COUNT(id) as count FROM purchase_orders WHERE supplier_id = ? AND vendor_status = 'Menunggu Konfirmasi'";
    $stmt = $conn->prepare($pendingSql);
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $response['pending_orders'] = (int)($result['count'] ?? 0);
    $stmt->close();

    // 3. Hitung PO yang sudah dikonfirmasi (harganya disetujui dapur)
    // --- PERBAIKAN: Mengganti 'Dikonfirmasi' menjadi 'Disetujui Dapur' agar sesuai alur ---
    $confirmedSql = "SELECT COUNT(id) as count FROM purchase_orders WHERE supplier_id = ? AND vendor_status = 'Disetujui Dapur'";
    $stmt = $conn->prepare($confirmedSql);
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $response['confirmed_orders'] = (int)($result['count'] ?? 0);
    $stmt->close();
    
    // 4. Ambil 5 pesanan terbaru
    $recentSql = "SELECT id, po_code, total_amount, vendor_status FROM purchase_orders WHERE supplier_id = ? ORDER BY created_at DESC LIMIT 5";
    $stmt = $conn->prepare($recentSql);
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $response['recent_orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode([
        'message' => 'Gagal mengambil data dasbor supplier.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

