<?php
// File: app/volunteers_manage.php
// Penjelasan: API BARU untuk mengelola data sukarelawan (CRUD).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

// Keamanan: Hanya Kepala Dapur & Administrator
if (!in_array($userData['role_id'], [2, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

header('Content-Type: application/json');

try {
    if ($method === 'GET') {
        $sql = "SELECT id, full_name, job_type, phone_number, address, is_active FROM volunteers WHERE organization_id = ? ORDER BY full_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $volunteers = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($volunteers);
        $stmt->close();
    } elseif ($method === 'POST') {
        if (!isset($data->full_name) || !isset($data->job_type) || empty(trim($data->full_name)) || empty(trim($data->job_type))) {
            throw new Exception('Nama lengkap dan jenis pekerjaan wajib diisi.', 400);
        }

        $is_active = isset($data->is_active) ? (int)$data->is_active : 1;
        
        if (isset($data->id)) { // Update
            $sql = "UPDATE volunteers SET full_name = ?, job_type = ?, phone_number = ?, address = ?, is_active = ? WHERE id = ? AND organization_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssiii", $data->full_name, $data->job_type, $data->phone_number, $data->address, $is_active, $data->id, $org_id);
            $message = 'Data sukarelawan berhasil diperbarui.';
        } else { // Create
            $sql = "INSERT INTO volunteers (organization_id, full_name, job_type, phone_number, address, is_active) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issssi", $org_id, $data->full_name, $data->job_type, $data->phone_number, $data->address, $is_active);
            http_response_code(201);
            $message = 'Sukarelawan berhasil ditambahkan.';
        }

        if ($stmt->execute()) {
            echo json_encode(['message' => $message]);
        } else {
            throw new Exception('Gagal menyimpan data ke database.');
        }
        $stmt->close();

    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            throw new Exception('ID sukarelawan tidak valid.', 400);
        }
        $sql = "DELETE FROM volunteers WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id, $org_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(['message' => 'Data sukarelawan berhasil dihapus.']);
        } else {
            throw new Exception('Data tidak ditemukan atau Anda tidak memiliki akses.', 404);
        }
        $stmt->close();
    } else {
        http_response_code(405);
        echo json_encode(['message' => 'Metode tidak diizinkan.']);
    }

} catch (Throwable $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>
