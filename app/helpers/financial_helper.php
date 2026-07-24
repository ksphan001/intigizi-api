<?php
// File: app/helpers/financial_helper.php
// Penjelasan: Diperbarui menjadi satu-satunya fungsi untuk mencatat semua transaksi keuangan.
// Menambahkan parameter opsional untuk po_id.

/**
 * Mencatat transaksi keuangan ke dalam tabel financial_transactions.
 *
 * @param mysqli $conn Objek koneksi database.
 * @param int $org_id ID organisasi.
 * @param string $date Tanggal transaksi (Y-m-d).
 * @param string $description Deskripsi transaksi.
 * @param int $debit_account_id ID akun yang di-debet.
 * @param int $credit_account_id ID akun yang di-kredit.
 * @param float $amount Jumlah transaksi.
 * @param int $created_by ID pengguna yang membuat transaksi.
 * @param int|null $po_id ID Purchase Order terkait (opsional).
 * @param string|null $proof_file Path file bukti transaksi (opsional).
 * @return void
 * @throws Exception Jika query gagal.
 */
function record_transaction($conn, $org_id, $date, $description, $debit_account_id, $credit_account_id, $amount, $created_by, $po_id = null, $proof_file = null) {
    $sql = "INSERT INTO financial_transactions 
                (organization_id, transaction_date, description, debit_account_id, credit_account_id, amount, created_by, po_id, proof_file) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Gagal mempersiapkan statement: " . $conn->error);
    }
    
    // Tipe data disesuaikan menjadi "issiiidis" (s untuk string proof_file)
    $stmt->bind_param("issiiidis", $org_id, $date, $description, $debit_account_id, $credit_account_id, $amount, $created_by, $po_id, $proof_file);
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal menjalankan statement pencatatan transaksi: " . $stmt->error);
    }
    
    $stmt->close();
}
?>
