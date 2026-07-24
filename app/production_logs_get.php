<?php
// File: app/production_logs_get.php
// Penjelasan: Diperbarui untuk SaaS, menambahkan filter organization_id.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

try {
    $proposal_id = isset($_GET['proposal_id']) ? (int)$_GET['proposal_id'] : 0;
    if ($proposal_id <= 0) {
        http_response_code(400);
        throw new Exception('ID proposal wajib disertakan.');
    }

    $sql = "SELECT production_date FROM production_logs WHERE proposal_id = ? AND organization_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $proposal_id, $org_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Query SQL Gagal: " . $conn->error);
    }

    $logs = $result->fetch_all(MYSQLI_ASSOC);
    
    http_response_code(200);
    echo json_encode($logs);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi error internal.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $stmt->close();
        $conn->close();
    }
}
?>
