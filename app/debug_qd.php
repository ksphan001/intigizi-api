<?php
require_once 'config.php';
header('Content-Type: application/json');

$org_id = 1; // Assuming org_id 1 for test
$sql = "SELECT * FROM quick_distributions ORDER BY id DESC LIMIT 5";
$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data, JSON_PRETTY_PRINT);
?>