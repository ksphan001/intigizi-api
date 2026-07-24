<?php
// File: app/financials_manage_expenses.php
// PERBAIKAN ALTERNATIF FINAL: Logika GET disederhanakan secara drastis untuk
// menghindari JOIN yang bermasalah. Frontend akan menangani pencocokan nama akun.
// Path file dipastikan benar sesuai arahan user.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];

// Keamanan: Hanya Akuntan dan Administrator
if (!in_array($userData['role_id'], [3, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
        $category_id = isset($_GET['category_id']) && !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;

        // --- PERUBAHAN UTAMA: Query disederhanakan, hanya mengambil ID sumber dana ---
        $sql = "SELECT 
                    oe.id, oe.description, oe.amount, oe.expense_date, oe.receipt_path,
                    oe.category_id, oe.source_account_id,
                    ec.name as category_name, 
                    u.full_name as created_by_name
                FROM operational_expenses oe
                LEFT JOIN expense_categories ec ON oe.category_id = ec.id
                LEFT JOIN users u ON oe.created_by = u.id
                WHERE oe.organization_id = ? ";
        
        $params = [$org_id];
        $types = "i";

        if ($start_date && $end_date) {
            $sql .= " AND oe.expense_date BETWEEN ? AND ? ";
            array_push($params, $start_date, $end_date);
            $types .= "ss";
        }
        if ($category_id) {
            $sql .= " AND oe.category_id = ? ";
            $params[] = $category_id;
            $types .= "i";
        }

        $sql .= " ORDER BY oe.expense_date DESC, oe.id DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($result);

    } elseif ($method === 'POST') {
        // Logika POST untuk CREATE dan UPDATE (tetap sama, sudah benar)
        $data = $_POST;
        $id = isset($data['id']) && !empty($data['id']) ? (int)$data['id'] : null;

        if (empty($data['description']) || empty($data['amount']) || empty($data['expense_date']) || empty($data['category_id']) || empty($data['source_account_id'])) {
            throw new Exception('Semua field termasuk Sumber Dana wajib diisi.', 400);
        }
        
        $receipt_path = $data['existing_receipt_path'] ?? null;
        if (isset($_FILES['receipt'])) {
            $target_dir = __DIR__ . "/../../uploads/receipts/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            $file_ext = strtolower(pathinfo($_FILES["receipt"]["name"], PATHINFO_EXTENSION));
            $new_filename = "receipt_{$org_id}_" . time() . "." . $file_ext;
            if (move_uploaded_file($_FILES["receipt"]["tmp_name"], $target_dir . $new_filename)) {
                $receipt_path = "/uploads/receipts/" . $new_filename;
            }
        }

        if ($id) { // Update
            $sql = "UPDATE operational_expenses SET category_id = ?, description = ?, amount = ?, expense_date = ?, receipt_path = ?, source_account_id = ? WHERE id = ? AND organization_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isdssiii", $data['category_id'], $data['description'], $data['amount'], $data['expense_date'], $receipt_path, $data['source_account_id'], $id, $org_id);
            $stmt->execute();
            echo json_encode(['message' => 'Biaya berhasil diperbarui.']);
        } else { // Create
            $sql = "INSERT INTO operational_expenses (organization_id, category_id, description, amount, expense_date, receipt_path, created_by, source_account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisdssii", $org_id, $data['category_id'], $data['description'], $data['amount'], $data['expense_date'], $receipt_path, $userData['id'], $data['source_account_id']);
            $stmt->execute();
            http_response_code(201);
            echo json_encode(['message' => 'Biaya berhasil ditambahkan.']);
        }

    } elseif ($method === 'DELETE') {
        // Logika DELETE (tetap sama, sudah benar)
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            throw new Exception('ID biaya tidak valid.', 400);
        }
        
        $sqlSelect = "SELECT receipt_path FROM operational_expenses WHERE id = ? AND organization_id = ?";
        $stmtSelect = $conn->prepare($sqlSelect);
        $stmtSelect->bind_param("ii", $id, $org_id);
        $stmtSelect->execute();
        $result = $stmtSelect->get_result();
        if ($row = $result->fetch_assoc()) {
            if ($row['receipt_path'] && file_exists(__DIR__ . "/../../" . $row['receipt_path'])) {
                unlink(__DIR__ . "/../../" . $row['receipt_path']);
            }
        }
        $stmtSelect->close();

        $sql = "DELETE FROM operational_expenses WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id, $org_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['message' => 'Data biaya berhasil dihapus.']);
        } else {
            throw new Exception('Data tidak ditemukan.', 404);
        }
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>

