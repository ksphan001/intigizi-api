<?php
// File: app/beneficiaries_manage.php
// Penjelasan: Diperbarui untuk menangani kolom baru:
// 1. Mengubah 'nik' menjadi 'nik_nisn'.
// 2. Menambahkan 'current_weight_kg', 'current_height_cm', 'current_bmi' saat Create/Update.
// 3. Menambahkan 'category_id'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id']; // Diperlukan untuk mencatat riwayat BMI
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

try {
    $accessible_org_ids = get_accessible_organization_ids($userData, $conn);

    if ($method === 'GET') {
        $selected_sppg_id = $_GET['sppg_id'] ?? 'all';
        $target_org_ids = [];

        if ($selected_sppg_id !== 'all' && is_numeric($selected_sppg_id)) {
            $selected_sppg_id = (int)$selected_sppg_id;
            if (in_array($selected_sppg_id, $accessible_org_ids)) {
                $target_org_ids = [$selected_sppg_id];
            } else {
                $target_org_ids = [$org_id];
            }
        } else {
            $target_org_ids = $accessible_org_ids;
        }

        $org_ids_str = implode(',', $target_org_ids);
        if (empty($org_ids_str)) {
            $org_ids_str = '0';
        }

        // Ambil data, termasuk data BMI terbaru dan nama kategori
        $sql = "SELECT 
                    b.id, b.full_name, b.nik_nisn, b.address, b.distribution_point_id, b.organization_id,
                    b.phone_number, b.email, b.category_id,
                    b.current_weight_kg, b.current_height_cm, b.current_bmi,
                    dp.name as distribution_point_name,
                    bc.name as category_name,
                    o.name as organization_name
                FROM beneficiaries b
                LEFT JOIN distribution_points dp ON b.distribution_point_id = dp.id
                LEFT JOIN beneficiary_categories bc ON b.category_id = bc.id
                LEFT JOIN organizations o ON b.organization_id = o.id
                WHERE b.organization_id IN ($org_ids_str)
                ORDER BY b.full_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($data);

    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"));
        $action = $data->action ?? (isset($data->id) ? 'update' : 'create');

        if ($action === 'delete') {
            $id = (int)$data->id;
            $org_ids_str = implode(',', $accessible_org_ids);
            if (empty($org_ids_str)) $org_ids_str = '0';
            
            $sql = "DELETE FROM beneficiaries WHERE id = ? AND organization_id IN ($org_ids_str)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode(['message' => 'Data penerima manfaat berhasil dihapus.']);
                } else {
                    throw new Exception('Data tidak ditemukan atau Anda tidak memiliki akses.', 404);
                }
            } else {
                throw new Exception('Gagal menghapus data.');
            }
        } else { // Create or Update
            // Tentukan target organization_id (jika Yayasan ingin menambahkan/mengedit data SPPG anaknya)
            $target_org_id = $org_id;
            if (isset($userData['role_id']) && (int)$userData['role_id'] === 4 && isset($data->organization_id)) {
                $check_org_id = (int)$data->organization_id;
                if (in_array($check_org_id, $accessible_org_ids)) {
                    $target_org_id = $check_org_id;
                }
            }

            // Validasi input
            if (empty($data->full_name) || empty($data->address) || empty($data->distribution_point_id) || empty($data->category_id)) {
                throw new Exception('Nama, alamat, kategori, dan titik distribusi wajib diisi.', 400);
            }
            
            $nik_nisn = !empty($data->nik_nisn) ? $conn->real_escape_string($data->nik_nisn) : null;
            $weight = !empty($data->current_weight_kg) ? (float)$data->current_weight_kg : null;
            $height = !empty($data->current_height_cm) ? (float)$data->current_height_cm : null;
            $bmi = null;

            if ($weight > 0 && $height > 0) {
                $height_m = $height / 100;
                $bmi = $weight / ($height_m * $height_m);
            }

            // Validasi kepemilikan Titik Distribusi
            $point_id = (int)$data->distribution_point_id;
            $checkPointSql = "SELECT id FROM distribution_points WHERE id = ? AND organization_id = ?";
            $checkPointStmt = $conn->prepare($checkPointSql);
            $checkPointStmt->bind_param("ii", $point_id, $target_org_id);
            $checkPointStmt->execute();
            if ($checkPointStmt->get_result()->num_rows === 0) {
                $checkPointStmt->close();
                throw new Exception("Titik distribusi tidak valid atau tidak dapat diakses.", 404);
            }
            $checkPointStmt->close();

            $conn->begin_transaction();
            try {
                if ($action === 'create') {
                    $sql = "INSERT INTO beneficiaries (
                                organization_id, full_name, nik_nisn, address, distribution_point_id, category_id, 
                                phone_number, email, current_weight_kg, current_height_cm, current_bmi
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                                "isssiisssdd", 
                        $target_org_id, $data->full_name, $nik_nisn, $data->address, $data->distribution_point_id, $data->category_id,
                        $data->phone_number, $data->email, $weight, $height, $bmi
                    );
                    
                    if ($stmt->execute()) {
                        $beneficiary_id = $conn->insert_id;
                        if ($weight > 0 && $height > 0) {
                            $sql_history = "INSERT INTO beneficiary_bmi_history 
                                                (organization_id, beneficiary_id, measurement_date, weight_kg, height_cm, bmi, recorded_by_user_id) 
                                            VALUES (?, ?, CURDATE(), ?, ?, ?, ?)";
                            $stmt_history = $conn->prepare($sql_history);
                            $stmt_history->bind_param("iidddi", $target_org_id, $beneficiary_id, $weight, $height, $bmi, $user_id);
                            $stmt_history->execute();
                            $stmt_history->close();
                        }
                        $conn->commit();
                        http_response_code(201);
                        echo json_encode(['message' => 'Penerima manfaat berhasil ditambahkan.', 'id' => $beneficiary_id]);
                    } else {
                        if ($conn->errno == 1062) {
                            throw new Exception('NIK/NISN sudah terdaftar di organisasi Anda.', 409);
                        }
                        throw new Exception('Gagal menyimpan data.');
                    }
                } else { // update
                    $id = (int)$data->id;
                    $sql = "UPDATE beneficiaries SET 
                                full_name = ?, nik_nisn = ?, address = ?, distribution_point_id = ?, category_id = ?, 
                                phone_number = ?, email = ?, current_weight_kg = ?, current_height_cm = ?, current_bmi = ?
                            WHERE id = ? AND organization_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        "sssiisssddii", 
                        $data->full_name, $nik_nisn, $data->address, $data->distribution_point_id, $data->category_id,
                        $data->phone_number, $data->email, $weight, $height, $bmi, 
                        $id, $target_org_id
                    );
                    
                    if ($stmt->execute()) {
                        if ($weight > 0 && $height > 0) {
                             $sql_history = "INSERT INTO beneficiary_bmi_history 
                                                (organization_id, beneficiary_id, measurement_date, weight_kg, height_cm, bmi, recorded_by_user_id) 
                                            VALUES (?, ?, CURDATE(), ?, ?, ?, ?)";
                            $stmt_history = $conn->prepare($sql_history);
                            $stmt_history->bind_param("iidddi", $target_org_id, $id, $weight, $height, $bmi, $user_id);
                            $stmt_history->execute();
                            $stmt_history->close();
                        }
                        $conn->commit();
                        echo json_encode(['message' => 'Data penerima manfaat berhasil diperbarui.']);
                    } else {
                         if ($conn->errno == 1062) throw new Exception('NIK/NISN sudah terdaftar oleh penerima lain di organisasi Anda.', 409);
                        throw new Exception('Gagal memperbarui data.');
                    }
                }
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }
    } else {
        http_response_code(405);
        echo json_encode(['message' => 'Metode tidak diizinkan.']);
    }

} catch (Throwable $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($stmt)) $stmt->close();
    $conn->close();
}
?>