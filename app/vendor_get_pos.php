<?php
// File: app/vendor_get_pos.php
// PENJELASAN: Logika dirombak total untuk menangani dua jenis supplier:
// 1. Vendor Publik (sebuah organisasi)
// 2. Supplier Internal (seorang pengguna di dalam organisasi dapur)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';

$userData = verify_jwt_token();

// Keamanan: Hanya pengguna dengan peran Supplier (role_id = 5) yang bisa mengakses
if ($userData['role_id'] != 5) {
    http_response_code(403);
    echo json_encode(['message' => 'Akses ditolak.']);
    exit();
}

try {
    $orders = [];

    // Cek apakah pengguna ini adalah Vendor publik (organisasi sendiri)
    // atau Supplier internal (bagian dari organisasi lain).
    if (isset($userData['org_type']) && $userData['org_type'] === 'Vendor') {
        // --- LOGIKA UNTUK VENDOR PUBLIK ---
        $vendor_org_id = (int)$userData['org_id'];
        
        $sql = "SELECT 
                    po.id, po.po_code, po.total_amount, 
                    po.vendor_status, po.created_at,
                    o.name as kitchen_name
                FROM purchase_orders po
                JOIN organizations o ON po.organization_id = o.id
                WHERE po.supplier_id = ?
                ORDER BY po.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $vendor_org_id);
        
    } else {
        // --- LOGIKA BARU UNTUK SUPPLIER INTERNAL ---
        $user_id = (int)$userData['id'];

        // 1. Cari dulu ID supplier berdasarkan user_id
        $supplierIdSql = "SELECT id FROM suppliers WHERE user_id = ?";
        $supplierIdStmt = $conn->prepare($supplierIdSql);
        $supplierIdStmt->bind_param("i", $user_id);
        $supplierIdStmt->execute();
        $supplierResult = $supplierIdStmt->get_result();
        
        if ($supplierResult->num_rows > 0) {
            $supplier_id = (int)$supplierResult->fetch_assoc()['id'];
            $supplierIdStmt->close();

            // 2. Gunakan ID supplier tersebut untuk mencari PO
            $sql = "SELECT 
                        po.id, po.po_code, po.total_amount, 
                        po.vendor_status, po.created_at,
                        o.name as kitchen_name
                    FROM purchase_orders po
                    JOIN organizations o ON po.organization_id = o.id
                    WHERE po.supplier_id = ?
                    ORDER BY po.created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $supplier_id);
        } else {
            // Jika user ini tidak terhubung ke entitas supplier manapun,
            // kembalikan array kosong.
            $supplierIdStmt->close();
            http_response_code(200);
            echo json_encode([]);
            exit();
        }
    }

    // Eksekusi query yang sudah disiapkan
    if (isset($stmt)) {
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    
    http_response_code(200);
    echo json_encode($orders);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Gagal mengambil data pesanan.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
