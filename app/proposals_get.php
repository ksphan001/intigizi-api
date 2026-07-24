<?php
// File: app/proposals_get.php
// Penjelasan: Diperbarui untuk mengambil data `last_edited_by` (siapa yang terakhir mengedit)
// dan tetap menyertakan flag `has_po_generated` untuk integritas data di frontend.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

$proposal_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$sql = "SELECT
            p.id, p.proposal_code, p.start_date, p.end_date, p.target_recipients,
            p.status, p.created_at, p.updated_at,
            creator.full_name as created_by_name,
            approver.full_name as approved_by_name,
            editor.full_name as last_edited_by_name,
            (SELECT COUNT(id) FROM purchase_orders WHERE proposal_id = p.id) > 0 AS has_po_generated
        FROM proposals p
        JOIN users creator ON p.created_by = creator.id
        LEFT JOIN users approver ON p.approved_by = approver.id
        LEFT JOIN users editor ON p.last_edited_by = editor.id
        WHERE p.organization_id = ?";

if ($proposal_id > 0) {
    $sql .= " AND p.id = ? ORDER BY p.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $org_id, $proposal_id);
} else {
    $sql .= " ORDER BY p.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $org_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $proposals = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($proposals as &$p) {
        $p['has_po_generated'] = (bool)$p['has_po_generated'];
    }
    echo json_encode($proposals);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Query ke database gagal.']);
}

$stmt->close();
$conn->close();
?>

