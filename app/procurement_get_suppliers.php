<?php
// File: app/procurement_get_suppliers.php
// Penjelasan: PERBAIKAN FINAL V8. Logika ditulis ulang sepenuhnya menggunakan query UNION
// untuk menggabungkan Vendor publik dan Supplier internal secara langsung di database.
// Ini adalah pendekatan yang paling efisien dan anti-gagal.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();
$dapur_org_id = (int)$userData['org_id']; // ID Organisasi dapur yang sedang login

try {
    // Query UNION untuk menggabungkan dua set data:
    // 1. Semua Vendor yang aktif dari tabel 'organizations'.
    // 2. Semua Supplier internal yang terdaftar di bawah 'organization_id' dapur saat ini.
    $sql = "(SELECT
                o.id,
                o.name,
                'Vendor' AS type
            FROM
                organizations o
            WHERE
                o.registration_type = 'Vendor' AND o.is_active = 1)
            UNION ALL
            (SELECT
                s.id,
                s.supplier_name AS name,
                'Supplier' AS type
            FROM
                suppliers s
            WHERE
                s.organization_id = ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $dapur_org_id);
    $stmt->execute();
    $all_suppliers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Urutkan hasil gabungan berdasarkan nama di PHP
    usort($all_suppliers, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    http_response_code(200);
    echo json_encode($all_suppliers);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Gagal mengambil data pemasok.', 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

