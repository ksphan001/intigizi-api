<?php
// File: app/slug_helper.php
// Deskripsi: Helper baru untuk menghasilkan slug yang unik untuk URL.

/**
 * Menghasilkan slug yang unik dari sebuah string untuk tabel organizations.
 *
 * @param string $name Nama organisasi.
 * @param mysqli $conn Objek koneksi database.
 * @return string Slug yang unik.
 */
function generate_unique_slug($name, $conn) {
    // 1. Konversi ke huruf kecil dan ganti spasi/simbol dengan '-'
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug); // Hapus tanda hubung ganda
    $slug = trim($slug, '-');

    // Jika slug kosong setelah dibersihkan (misal, hanya berisi simbol), buat default
    if (empty($slug)) {
        $slug = 'dapur';
    }

    // 2. Cek keunikan slug di database
    $baseSlug = $slug;
    $counter = 1;
    while (true) {
        $checkSql = "SELECT id FROM organizations WHERE slug = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("s", $slug);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $checkStmt->close();

        if ($result->num_rows == 0) {
            // Jika slug belum ada, slug ini unik dan bisa digunakan
            return $slug;
        }

        // Jika sudah ada, tambahkan angka di belakangnya dan cek lagi
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}
?>
