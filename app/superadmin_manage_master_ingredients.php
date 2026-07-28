<?php
// File: app/superadmin_manage_master_ingredients.php
// Deskripsi: API CRUD untuk Super Admin mengelola Pustaka Bahan Baku Master (master_ingredients).
// Mendukung GET (list/search), POST (create/update), DELETE.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya Super Admin (role_id = 8)
if ((int)$userData['role_id'] !== 8) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Hanya Super Admin.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

try {
    if ($method === 'GET') {
        $search = isset($_GET['search']) ? '%' . $conn->real_escape_string(trim($_GET['search'])) . '%' : '%';

        $sql = "SELECT id, food_code, name, `group`, calories, protein, fat, carbohydrates, fiber,
                       bdd_percentage, estimated_price
                FROM master_ingredients
                WHERE name LIKE ? OR `group` LIKE ? OR food_code LIKE ?
                ORDER BY `group` ASC, name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $search, $search, $search);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Ambil juga daftar group unik
        $groupRes = $conn->query("SELECT DISTINCT `group` FROM master_ingredients WHERE `group` IS NOT NULL ORDER BY `group` ASC");
        $groups = [];
        while ($r = $groupRes->fetch_assoc()) {
            $groups[] = $r['group'];
        }

        echo json_encode(['items' => $items, 'groups' => $groups]);

    } elseif ($method === 'POST') {
        if (!isset($data->name) || empty(trim($data->name))) {
            throw new Exception('Nama bahan baku wajib diisi.', 400);
        }

        $name            = trim($data->name);
        $food_code       = trim($data->food_code ?? '');
        $group           = trim($data->group ?? '');
        $calories        = (float)($data->calories ?? 0);
        $protein         = (float)($data->protein ?? 0);
        $fat             = (float)($data->fat ?? 0);
        $carbohydrates   = (float)($data->carbohydrates ?? 0);
        $fiber           = (float)($data->fiber ?? 0);
        $bdd_percentage  = (float)($data->bdd_percentage ?? 1.00);
        $estimated_price = (float)($data->estimated_price ?? 0);

        if (isset($data->id) && (int)$data->id > 0) {
            // UPDATE
            $id = (int)$data->id;
            $sql = "UPDATE master_ingredients
                    SET food_code=?, name=?, `group`=?, calories=?, protein=?, fat=?,
                        carbohydrates=?, fiber=?, bdd_percentage=?, estimated_price=?
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssdddddddi",
                $food_code, $name, $group,
                $calories, $protein, $fat, $carbohydrates, $fiber,
                $bdd_percentage, $estimated_price, $id
            );
            $stmt->execute();
            $stmt->close();
            echo json_encode(['message' => "Bahan baku '{$name}' berhasil diperbarui."]);
        } else {
            // CREATE
            $sql = "INSERT INTO master_ingredients
                        (food_code, name, `group`, calories, protein, fat, carbohydrates, fiber, bdd_percentage, estimated_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssdddddddd",
                $food_code, $name, $group,
                $calories, $protein, $fat, $carbohydrates, $fiber,
                $bdd_percentage, $estimated_price
            );
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();
            http_response_code(201);
            echo json_encode(['message' => "Bahan baku '{$name}' berhasil ditambahkan.", 'id' => $newId]);
        }

    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) throw new Exception('ID tidak valid.', 400);

        // Cek apakah sudah dipakai di ingredients dapur mana pun
        $checkStmt = $conn->prepare("SELECT COUNT(*) as c FROM ingredients WHERE master_ingredient_id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $count = (int)$checkStmt->get_result()->fetch_assoc()['c'];
        $checkStmt->close();

        if ($count > 0) {
            throw new Exception("Bahan ini tidak dapat dihapus karena sudah digunakan oleh {$count} bahan baku di dapur.", 409);
        }

        $stmt = $conn->prepare("DELETE FROM master_ingredients WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['message' => 'Bahan baku master berhasil dihapus.']);
        } else {
            throw new Exception('Data tidak ditemukan.', 404);
        }
        $stmt->close();

    } else {
        http_response_code(405);
        echo json_encode(['message' => 'Metode tidak diizinkan.']);
    }

} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    if ($e instanceof mysqli_sql_exception && $e->getCode() == 1062) {
        http_response_code(409);
        echo json_encode(['message' => 'Nama bahan baku sudah terdaftar di pustaka.']);
    } else {
        http_response_code($code);
        echo json_encode(['message' => $e->getMessage()]);
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>
