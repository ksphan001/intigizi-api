<?php
// File: app/purchase_orders_get.php
// Penjelasan: Diperbarui untuk menyertakan flag `has_been_reviewed` pada detail PO.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    if ($po_id > 0) {
        // Logika untuk mengambil detail satu PO
        $effective_supplier_id = 0;
        if ($userData['role_id'] == 5) {
            $supSql = "(SELECT id FROM organizations WHERE id = ? AND registration_type = 'Vendor') UNION (SELECT id FROM suppliers WHERE user_id = ?)";
            $supStmt = $conn->prepare($supSql);
            $supStmt->bind_param("ii", $org_id, $user_id);
            $supStmt->execute();
            $supRes = $supStmt->get_result()->fetch_assoc();
            if ($supRes) $effective_supplier_id = (int)$supRes['id'];
            $supStmt->close();
        }

        // --- PERBAIKAN: Menambahkan LEFT JOIN ke vendor_reviews ---
        $sql = "SELECT 
                    po.*, 
                    prop.proposal_code, 
                    COALESCE(s.supplier_name, o.name) as supplier_name,
                    CASE WHEN vr.id IS NOT NULL THEN 1 ELSE 0 END as has_been_reviewed
                FROM purchase_orders po
                LEFT JOIN proposals prop ON po.proposal_id = prop.id
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN organizations o ON po.supplier_id = o.id
                LEFT JOIN vendor_reviews vr ON po.id = vr.po_id
                WHERE po.id = ? AND (po.organization_id = ? OR po.supplier_id = ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $po_id, $org_id, $effective_supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);

    } else {
        // Logika untuk mengambil daftar semua PO
        $sql = "SELECT 
                    po.id, po.po_code, po.total_amount, po.status, po.vendor_status, po.created_at,
                    COALESCE(s.supplier_name, o.name) as supplier_name
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                LEFT JOIN organizations o ON po.supplier_id = o.id
                WHERE po.organization_id = ? 
                ORDER BY po.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
    }

    http_response_code(200);
    echo json_encode($data);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan pada server.', 'error' => $e->getMessage()]);
} finally {
    if(isset($stmt)) $stmt->close();
    if(isset($conn)) $conn->close();
}
?>

