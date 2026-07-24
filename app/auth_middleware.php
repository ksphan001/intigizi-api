<?php
// File: app/auth_middleware.php
// PERBAIKAN: Menambahkan pengecualian untuk Vendor agar tidak
// terkena pengecekan sistem berlangganan.
// PERBAIKAN 2: Menambahkan role_id 10 (Calon Mitra) dan 5 (Supplier) ke pengecualian.

require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function verify_jwt_token()
{
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
    $jwt_secret = $_ENV['JWT_SECRET'];

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['message' => 'Akses ditolak. Token tidak valid atau tidak ada.']);
        echo json_encode(['message' => $authHeader]);
        exit();
    }

    $jwt = $matches[1];

    try {
        $decoded = JWT::decode($jwt, new Key($jwt_secret, 'HS256'));
        $userData = (array) $decoded->data;

        if (!isset($userData['id'])) {
            throw new Exception("Token tidak berisi data yang diperlukan (ID).");
        }

        // org_id bisa NULL untuk Super Admin dan Investor
        if (!array_key_exists('org_id', $userData)) {
            $userData['org_id'] = null;
        }


        $current_script = basename($_SERVER['PHP_SELF']);
        $exempted_scripts = [
            'get_user_session.php',
            'get_subscription_status.php',
            'create_subscription_invoice.php'
        ];

        if (in_array($current_script, $exempted_scripts)) {
            return $userData;
        }

        global $conn;
        if (!$conn) {
            $conn = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
            if ($conn->connect_error) {
                throw new Exception("Koneksi database gagal untuk middleware.");
            }
        }

        // --- LOGIKA BARU: Pengecualian untuk Vendor ---
        $isVendor = isset($userData['org_type']) && $userData['org_type'] === 'Vendor';

        // --- PERBAIKAN DI SINI ---
        // Peran yang dikecualikan dari pemeriksaan langganan:
        // 8: Super Admin
        // 9: Investor
        // 10: Calon Mitra
        // 5: Supplier (mencakup Vendor Internal dan Eksternal)
        $isExempt = isset($userData['role_id']) && in_array($userData['role_id'], [5, 8, 9, 10]);
        // --- AKHIR PERBAIKAN ---

        if (!$isExempt && isset($userData['org_id'])) {
            $org_id = (int)$userData['org_id'];

            $subSql = "SELECT subscription_status, subscription_until FROM organizations WHERE id = ?";
            $stmt = $conn->prepare($subSql);
            $stmt->bind_param("i", $org_id);
            $stmt->execute();
            $subscription = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($subscription) {
                $status = $subscription['subscription_status'];
                $until = $subscription['subscription_until'];

                if ($status === 'expired' || $status === 'inactive') {
                    http_response_code(402); // 402 Payment Required
                    echo json_encode(['message' => 'Langganan Anda telah berakhir. Silakan perbarui langganan Anda.', 'subscription_required' => true]);
                    exit();
                }

                if (($status === 'trial' || $status === 'active') && $until && strtotime($until) < time()) {
                    $updateSql = "UPDATE organizations SET subscription_status = 'expired' WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("i", $org_id);
                    $updateStmt->execute();
                    $updateStmt->close();

                    http_response_code(402);
                    echo json_encode(['message' => 'Langganan Anda telah berakhir. Silakan perbarui langganan Anda.', 'subscription_required' => true]);
                    exit();
                }
            }
        }

        return $userData;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['message' => 'Akses ditolak. Token tidak valid atau kedaluwarsa.']);
        exit();
    }
}

/**
 * Mendapatkan daftar ID organisasi yang dapat diakses oleh user.
 * Jika role adalah Yayasan (4), maka ia dapat mengakses organisasi induknya sendiri
 * beserta seluruh organisasi anak (SPPG) di bawahnya.
 */
function get_accessible_organization_ids($userData, $conn)
{
    if (!isset($userData['org_id'])) {
        return [];
    }
    
    $org_id = (int)$userData['org_id'];
    $org_ids = [$org_id];

    if (isset($userData['role_id']) && (int)$userData['role_id'] === 4) {
        $sql = "SELECT id FROM organizations WHERE parent_organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $org_ids[] = (int)$row['id'];
        }
        $stmt->close();
    }

    return $org_ids;
}
?>
