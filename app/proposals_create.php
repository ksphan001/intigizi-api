<?php
// File: app/proposals_create.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->start_date) || !isset($data->end_date) || !isset($data->target_recipients)) {
    http_response_code(400);
    echo json_encode(['message' => 'Tanggal mulai, tanggal akhir, dan target penerima wajib diisi.']);
    exit();
}

$start_date = $data->start_date;
$end_date = $data->end_date;
$target_recipients = (int)$data->target_recipients;
$proposal_code = "PROP-" . date("Ymd") . "-" . strtoupper(substr(md5(time()), 0, 5));

$sql = "INSERT INTO proposals (organization_id, proposal_code, start_date, end_date, target_recipients, created_by, status) VALUES (?, ?, ?, ?, ?, ?, 'Draft')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("isssii", $org_id, $proposal_code, $start_date, $end_date, $target_recipients, $user_id);

if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode(['message' => 'Proposal berhasil dibuat.', 'id' => $conn->insert_id, 'proposal_code' => $proposal_code]);
} else {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal membuat proposal: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
