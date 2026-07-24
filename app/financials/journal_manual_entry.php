<?php
// File: app/financials/journal_manual_entry.php
// Penjelasan: Diperbarui untuk menggunakan helper keuangan yang terstandarisasi.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../helpers/financial_helper.php';

$userData = verify_jwt_token();
$org_id = (int) $userData['org_id'];
$user_id = (int) $userData['id'];

if (!in_array($userData['role_id'], [3, 7])) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

// Menggunakan $_POST karena ada upload file (multipart/form-data)
// $data = json_decode(file_get_contents("php://input")); // TIDAK DIGUNAKAN LAGI

// Helper untuk mengambil input baik dari JSON maupun POST (fallback jika masih ada yang kirim JSON tanpa file)
function get_input($key)
{
    return isset($_POST[$key]) ? $_POST[$key] : null;
}

$transaction_date = get_input('transaction_date');
$description = get_input('description');
$debit_account_id = get_input('debit_account_id');
$credit_account_id = get_input('credit_account_id');
$amount = get_input('amount');

// Validasi input
if (
    !$transaction_date ||
    !$description ||
    !$debit_account_id ||
    !$credit_account_id ||
    !$amount ||
    empty(trim($description)) ||
    (float) $amount <= 0
) {
    // Coba baca JSON jika POST kosong (untuk backward compatibility sementara/testing)
    $inputJSON = file_get_contents("php://input");
    $dataJSON = json_decode($inputJSON);
    if ($dataJSON) {
        $transaction_date = $dataJSON->transaction_date ?? null;
        $description = $dataJSON->description ?? null;
        $debit_account_id = $dataJSON->debit_account_id ?? null;
        $credit_account_id = $dataJSON->credit_account_id ?? null;
        $amount = $dataJSON->amount ?? null;
    }

    if (
        !$transaction_date ||
        !$description ||
        !$debit_account_id ||
        !$credit_account_id ||
        !$amount ||
        empty(trim($description)) ||
        (float) $amount <= 0
    ) {
        http_response_code(400);
        echo json_encode(['message' => 'Semua field wajib diisi dan jumlah harus lebih besar dari nol.']);
        exit();
    }
}

if ($debit_account_id == $credit_account_id) {
    http_response_code(400);
    echo json_encode(['message' => 'Akun Debet dan Kredit tidak boleh sama.']);
    exit();
}

// Handle File Upload
$proof_file_path = null;
if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $max_size = 5 * 1024 * 1024; // 5MB

    if (!in_array($_FILES['proof_file']['type'], $allowed_types)) {
        http_response_code(400);
        echo json_encode(['message' => 'Tipe file tidak didukung. Gunakan JPG, PNG, WEBP, atau PDF.']);
        exit();
    }

    if ($_FILES['proof_file']['size'] > $max_size) {
        http_response_code(400);
        echo json_encode(['message' => 'Ukuran file terlalu besar. Maksimal 5MB.']);
        exit();
    }

    $upload_dir = __DIR__ . '/../../uploads/journal_proofs/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    $file_extension = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
    $filename = 'proof_' . $org_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
    $target_file = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $target_file)) {
        // PERBAIKAN: Pastikan file bisa dibaca oleh publik/web server
        chmod($target_file, 0644);
        $proof_file_path = '/uploads/journal_proofs/' . $filename;
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal mengupload file bukti pembayaran.']);
        exit();
    }
}


$conn->begin_transaction();
try {
    record_transaction(
        $conn,
        $org_id,
        $transaction_date,
        $conn->real_escape_string($description),
        (int) $debit_account_id,
        (int) $credit_account_id,
        (float) $amount,
        $user_id,
        null, // po_id is null
        $proof_file_path // Pass the file path
    );

    $conn->commit();
    http_response_code(201);
    echo json_encode(['message' => 'Entri jurnal berhasil disimpan.']);

} catch (Throwable $e) {
    $conn->rollback();
    // Hapus file jika transaksi database gagal
    if ($proof_file_path) {
        $full_path = __DIR__ . '/../../' . $proof_file_path;
        if (file_exists($full_path)) {
            unlink($full_path);
        }
    }
    http_response_code(500);
    echo json_encode(['message' => 'Gagal menyimpan entri jurnal.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn))
        $conn->close();
}
?>