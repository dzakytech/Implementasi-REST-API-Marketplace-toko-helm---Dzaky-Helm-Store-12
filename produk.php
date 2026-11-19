<?php
/**
 * REST API Endpoint: Produk
 * 
 * Mendukung:
 * - GET /api/produk.php         -> Mengambil semua data produk (bisa filter ?kategori_id= & ?q=)
 * - GET /api/produk.php?id=1    -> Mengambil detail 1 produk berdasarkan ID
 */

require_once __DIR__ . '/init.php';

// Validasi method HTTP: hanya GET
require_method(['GET']);

// Kasus 1: Detail Produk Tunggal (GET /api/produk.php?id=...)
if (isset($_GET['id'])) {
    $id_raw = trim($_GET['id']);
    
    if ($id_raw === '' || !ctype_digit($id_raw) || (int)$id_raw <= 0) {
        json_error('Parameter ID produk harus berupa bilangan bulat positif.', 400);
    }

    $id = (int)$id_raw;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT p.*, k.nama_kategori 
         FROM produk p 
         LEFT JOIN kategori k ON p.kategori_id = k.id 
         WHERE p.id = ? 
         LIMIT 1"
    );

    if (!$stmt) {
        json_error('Gagal menyiapkan query database: ' . mysqli_error($conn), 500);
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $produk = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$produk) {
        json_error("Produk dengan ID $id tidak ditemukan.", 404);
    }

    json_response('success', 'Detail produk berhasil diambil.', format_produk_data($produk), 200);
}

// Kasus 2: Daftar Semua Produk (GET /api/produk.php)
$sql = "SELECT p.*, k.nama_kategori 
        FROM produk p 
        LEFT JOIN kategori k ON p.kategori_id = k.id";

$conditions = [];
$params = [];
$types = "";

// Filter berdasarkan kategori_id jika tersedia
if (isset($_GET['kategori_id']) && trim($_GET['kategori_id']) !== '') {
    $kategori_id = (int)$_GET['kategori_id'];
    $conditions[] = "p.kategori_id = ?";
    $params[] = $kategori_id;
    $types .= "i";
}

// Pencarian nama produk jika tersedia (?q= atau ?search=)
$search = trim($_GET['q'] ?? $_GET['search'] ?? '');
if ($search !== '') {
    $conditions[] = "(p.nama_produk LIKE ? OR p.deskripsi LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

// Pengurutan (default: id ASC)
$sql .= " ORDER BY p.id ASC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        json_error('Gagal menyiapkan query database: ' . mysqli_error($conn), 500);
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        json_error('Gagal mengeksekusi query database: ' . mysqli_error($conn), 500);
    }
}

$daftar_produk = [];
while ($row = mysqli_fetch_assoc($result)) {
    $daftar_produk[] = format_produk_data($row);
}

if (isset($stmt) && $stmt) {
    mysqli_stmt_close($stmt);
}

$response_data = [
    'total'  => count($daftar_produk),
    'produk' => $daftar_produk
];

json_response('success', 'Daftar produk berhasil diambil.', $response_data, 200);
