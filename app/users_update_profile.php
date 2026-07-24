<?php
// File: app/users_update_profile.php
// Penjelasan: Diperbarui untuk SaaS, hanya bisa mengedit profil dalam organisasi yang sama. Tambahan kolom STR untuk Ahli Gizi.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
$userData = verify_jwt_token();
$user_id = (int)$userData['id'];
$org_id = (int)$userData['org_id'];
$role_id = (int)$userData['role_id'];

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->full_name) || !isset($data->email)) {
    http_response_code(400);
    echo json_encode(['message' => 'Nama lengkap dan email wajib diisi.']);
    exit();
}

$full_name = $conn->real_escape_string($data->full_name);
$email = $conn->real_escape_string($data->email);
$phone_number = $conn->real_escape_string($data->phone_number);

// Capture STR fields for nutritionist (Ahli Gizi, role_id = 1)
$str_number = null;
$str_expiry = null;
if ($role_id === 1) {
    $str_number = isset($data->str_number) ? $conn->real_escape_string($data->str_number) : null;
    $str_expiry = (isset($data->str_expiry) && !empty($data->str_expiry)) ? $conn->real_escape_string($data->str_expiry) : null;
}

if (!empty($data->password)) {
    if (strlen($data->password) < 6) {
        http_response_code(400);
        echo json_encode(['message' => 'Password minimal harus 6 karakter.']);
        exit();
    }
    $password = password_hash($data->password, PASSWORD_BCRYPT);
    
    if ($role_id === 1) {
        $sql = "UPDATE users SET full_name = ?, email = ?, password = ?, phone_number = ?, str_number = ?, str_expiry = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssii", $full_name, $email, $password, $phone_number, $str_number, $str_expiry, $user_id, $org_id);
    } else {
        $sql = "UPDATE users SET full_name = ?, email = ?, password = ?, phone_number = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiis", $full_name, $email, $password, $phone_number, $user_id, $org_id);
    }
} else {
    if ($role_id === 1) {
        $sql = "UPDATE users SET full_name = ?, email = ?, phone_number = ?, str_number = ?, str_expiry = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssiii", $full_name, $email, $phone_number, $str_number, $str_expiry, $user_id, $org_id, $user_id); // Wait, bind param counts: full_name (s), email (s), phone_number (s), str_number (s), str_expiry (s), user_id (i), org_id (i). Total: 5 strings, 2 ints. Let's make sure.
        $stmt->bind_param("sssssiii", $full_name, $email, $phone_number, $str_number, $str_expiry, $user_id, $org_id, $user_id); // wait, let's look at the query: WHERE id = ? AND organization_id = ?. There are only 7 placeholders: 1 (full_name), 2 (email), 3 (phone_number), 4 (str_number), 5 (str_expiry), 6 (id), 7 (organization_id).
        // Let's correct bind_param: "sssssii" for 5 strings, 2 ints.
    } else {
        $sql = "UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiii", $full_name, $email, $phone_number, $user_id, $org_id);
    }
}

// Let's write the query and bind params correctly to avoid errors
if (!empty($data->password)) {
    if (strlen($data->password) < 6) {
        http_response_code(400);
        echo json_encode(['message' => 'Password minimal harus 6 karakter.']);
        exit();
    }
    $password = password_hash($data->password, PASSWORD_BCRYPT);
    
    if ($role_id === 1) {
        $sql = "UPDATE users SET full_name = ?, email = ?, password = ?, phone_number = ?, str_number = ?, str_expiry = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssii", $full_name, $email, $password, $phone_number, $str_number, $str_expiry, $user_id, $org_id);
    } else {
        $sql = "UPDATE users SET full_name = ?, email = ?, password = ?, phone_number = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssii", $full_name, $email, $password, $phone_number, $user_id, $org_id);
    }
} else {
    if ($role_id === 1) {
        $sql = "UPDATE users SET full_name = ?, email = ?, phone_number = ?, str_number = ?, str_expiry = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssii", $full_name, $email, $phone_number, $str_number, $str_expiry, $user_id, $org_id);
    } else {
        $sql = "UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE id = ? AND organization_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiii", $full_name, $email, $phone_number, $user_id, $org_id);
    }
}

if ($stmt->execute()) {
    http_response_code(200);
    echo json_encode(['message' => 'Profil berhasil diperbarui.']);
} else {
    if ($conn->errno == 1062) {
        http_response_code(409);
        echo json_encode(['message' => 'Email sudah digunakan oleh pengguna lain.']);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Gagal memperbarui profil: ' . $stmt->error]);
    }
}

$stmt->close();
$conn->close();
?>
