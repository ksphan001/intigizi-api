<?php
// File: app/vendor_review_create.php
// Penjelasan: API baru untuk membuat ulasan vendor, memeriksa hak akses,
// dan mengkalkulasi ulang rating rata-rata.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];
$role_id = (int)$userData['role_id'];

// Keamanan: Hanya peran tertentu yang bisa memberi ulasan
$allowed_roles = [2, 3, 7]; // Kepala Dapur, Akuntan, Administrator
if (!in_array($role_id, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['message' => 'Anda tidak memiliki hak akses untuk memberikan ulasan.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->po_id) || !isset($data->rating) || $data->rating < 1 || $data->rating > 5) {
    http_response_code(400);
    echo json_encode(['message' => 'ID PO dan rating (1-5) wajib diisi.']);
    exit();
}

$po_id = (int)$data->po_id;
$rating = (int)$data->rating;
$comment = isset($data->comment) ? $conn->real_escape_string($data->comment) : null;

$conn->begin_transaction();
try {
    // 1. Validasi PO: Pastikan ada, milik organisasi ini, dan sudah Selesai
    $poSql = "SELECT supplier_id, status FROM purchase_orders WHERE id = ? AND organization_id = ?";
    $poStmt = $conn->prepare($poSql);
    $poStmt->bind_param("ii", $po_id, $org_id);
    $poStmt->execute();
    $po = $poStmt->get_result()->fetch_assoc();
    $poStmt->close();

    if (!$po) {
        throw new Exception("Purchase Order tidak ditemukan atau Anda tidak memiliki akses.", 404);
    }
    if ($po['status'] !== 'Selesai') {
        throw new Exception("Hanya pesanan yang sudah selesai yang dapat diberi ulasan.", 409);
    }
    
    $vendor_id = (int)$po['supplier_id'];

    // 2. Simpan ulasan baru
    $insertSql = "INSERT INTO vendor_reviews (po_id, vendor_id, reviewer_org_id, reviewer_user_id, rating, comment) VALUES (?, ?, ?, ?, ?, ?)";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->bind_param("iiiiis", $po_id, $vendor_id, $org_id, $user_id, $rating, $comment);
    
    if (!$insertStmt->execute()) {
        if ($conn->errno == 1062) { // Error duplikat dari constraint DB
            throw new Exception("Ulasan untuk pesanan ini sudah pernah diberikan.", 409);
        }
        throw new Exception("Gagal menyimpan ulasan: " . $insertStmt->error);
    }
    $insertStmt->close();

    // 3. Update rating rata-rata di tabel organizations
    $updateRatingSql = "UPDATE organizations o SET 
                            o.review_count = (SELECT COUNT(id) FROM vendor_reviews WHERE vendor_id = o.id),
                            o.average_rating = (SELECT AVG(rating) FROM vendor_reviews WHERE vendor_id = o.id)
                        WHERE o.id = ?";
    $updateStmt = $conn->prepare($updateRatingSql);
    $updateStmt->bind_param("i", $vendor_id);
    $updateStmt->execute();
    $updateStmt->close();

    $conn->commit();

    // 4. Sinkronisasikan ulasan ke Marketplace terpusat jika supplier terhubung
    try {
        $supSql = "SELECT marketplace_id FROM suppliers WHERE id = ? LIMIT 1";
        $supStmt = $conn->prepare($supSql);
        $supStmt->bind_param("i", $vendor_id);
        $supStmt->execute();
        $supResult = $supStmt->get_result()->fetch_assoc();
        $supStmt->close();

        if ($supResult && !empty($supResult['marketplace_id'])) {
            $marketplace_id = (int)$supResult['marketplace_id'];
            
            // Ambil nama dapur peninjau
            $orgSql = "SELECT name FROM organizations WHERE id = ? LIMIT 1";
            $orgStmt = $conn->prepare($orgSql);
            $orgStmt->bind_param("i", $org_id);
            $orgStmt->execute();
            $orgResult = $orgStmt->get_result()->fetch_assoc();
            $orgStmt->close();
            $kitchen_name = $orgResult['name'] ?? "Dapur Gizi #" . $org_id;

            // Kirim ulasan ke API terpusat Marketplace
            $ch = curl_init("http://intigizi-supplier-api.test/app/submit_review.php");
            $postData = json_encode([
                'supplier_id' => $marketplace_id,
                'kitchen_name' => $kitchen_name,
                'rating' => $rating,
                'comment' => $comment
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $sync_err) {
        // Biarkan gagal tanpa menggagalkan pengembalian respon sukses lokal
    }

    http_response_code(201);
    echo json_encode(['message' => 'Terima kasih, ulasan Anda berhasil disimpan.']);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode() > 0 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if(isset($conn)) $conn->close();
}
?>

