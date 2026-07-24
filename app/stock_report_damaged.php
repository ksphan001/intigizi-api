<?php
// File: app/stock_report_damaged.php
// Penjelasan: Melaporkan bahan baku yang rusak, busuk, tumpah, atau kadaluarsa di gudang.
// Sistem akan mengurangi stok fisik, mencatat mutasi stok, serta membukukan kerugian finansial di jurnal keuangan secara otomatis.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$org_id = (int)$userData['org_id'];
$user_id = (int)$userData['id'];

// Keamanan: Hanya Kepala Dapur (2) atau Admin Dapur (7) yang bisa menginput barang rusak
if (!isset($userData['role_id']) || !in_array((int)$userData['role_id'], [2, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak. Fitur ini hanya untuk Kepala Dapur atau Admin Dapur.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Metode request tidak diizinkan. Gunakan POST.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

$ingredient_id = isset($data['ingredient_id']) ? (int)$data['ingredient_id'] : 0;
$qty_to_reduce = isset($data['quantity']) ? (float)$data['quantity'] : 0.0;
$reason = isset($data['reason']) ? trim($data['reason']) : '';
$notes = isset($data['notes']) ? trim($data['notes']) : '';

if ($ingredient_id <= 0 || $qty_to_reduce <= 0) {
    http_response_code(400);
    echo json_encode(['message' => 'Bahan baku dan kuantitas rusak wajib diisi dengan benar.']);
    exit();
}

if (empty($reason)) {
    $reason = "Bahan Rusak / Kadaluarsa";
}

$conn->begin_transaction();

try {
    // 1. Cek stok saat ini & harga terbaru bahan baku tersebut
    $stockSql = "SELECT s.current_quantity, i.name as ingredient_name, i.latest_price, u.conversion_factor 
                 FROM stock s 
                 JOIN ingredients i ON s.ingredient_id = i.id 
                 JOIN units u ON i.unit_id = u.id
                 WHERE s.organization_id = ? AND s.ingredient_id = ? FOR UPDATE";
    $stockStmt = $conn->prepare($stockSql);
    $stockStmt->bind_param("ii", $org_id, $ingredient_id);
    $stockStmt->execute();
    $stockInfo = $stockStmt->get_result()->fetch_assoc();
    $stockStmt->close();

    if (!$stockInfo) {
        throw new Exception("Bahan baku tidak ditemukan di dalam stok gudang.", 404);
    }

    $current_qty = (float)$stockInfo['current_quantity'];
    $conversion_factor = (float)$stockInfo['conversion_factor'];
    
    // Konversi kuantitas yang diinput (dalam unit tampilan) ke gram/satuan terkecil di DB
    $qty_in_base_unit = $qty_to_reduce * $conversion_factor;

    if ($qty_in_base_unit > $current_qty) {
        throw new Exception("Kuantitas rusak melebihi stok yang tersedia saat ini (" . ($current_qty / $conversion_factor) . ").", 400);
    }

    // 2. Kurangi stok fisik di gudang
    $updateStockSql = "UPDATE stock SET current_quantity = current_quantity - ? WHERE organization_id = ? AND ingredient_id = ?";
    $updateStmt = $conn->prepare($updateStockSql);
    $updateStmt->bind_param("dii", $qty_in_base_unit, $org_id, $ingredient_id);
    $updateStmt->execute();
    $updateStmt->close();

    // 3. Catat di tabel mutasi stok (stock_transactions)
    $trx_notes = "Rusak: " . $reason . (!empty($notes) ? " (" . $notes . ")" : "");
    $insertTrxSql = "INSERT INTO stock_transactions (organization_id, ingredient_id, type, quantity, notes) 
                     VALUES (?, ?, 'Keluar', ?, ?)";
    $trxStmt = $conn->prepare($insertTrxSql);
    $trxStmt->bind_param("iids", $org_id, $ingredient_id, $qty_in_base_unit, $trx_notes);
    $trxStmt->execute();
    $trxStmt->close();

    // 4. Jurnal Keuangan: Catat nilai kerugian di financial_transactions
    // Nilai kerugian = Kuantitas rusak * Harga beli terbaru bahan baku tersebut
    $latest_price = (float)$stockInfo['latest_price'];
    $loss_value = $qty_to_reduce * $latest_price;

    if ($loss_value > 0) {
        // Debit: Biaya Operasional (5), Kredit: Biaya Bahan Baku (4)
        $journal_desc = "Penyesuaian Stok: Kerusakan bahan baku " . $stockInfo['ingredient_name'] . " (" . $qty_to_reduce . ") karena " . $reason;
        $journalSql = "INSERT INTO financial_transactions (organization_id, transaction_date, description, debit_account_id, credit_account_id, amount, created_by) 
                       VALUES (?, NOW(), ?, 5, 4, ?, ?)";
        $journalStmt = $conn->prepare($journalSql);
        $journalStmt->bind_param("isdi", $org_id, $journal_desc, $loss_value, $user_id);
        $journalStmt->execute();
        $journalStmt->close();
    }

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        'message' => 'Laporan bahan rusak berhasil disimpan. Stok telah disesuaikan dan kerugian dibukukan ke jurnal keuangan.'
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
