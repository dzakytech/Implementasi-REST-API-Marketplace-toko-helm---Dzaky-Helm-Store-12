<?php
/**
 * REST API Endpoint: Kategori
 * 
 * Mendukung:
 * - GET /api/kategori.php        -> Mengambil seluruh daftar kategori produk
 * - GET /api/kategori.php?id=1   -> Mengambil detail 1 kategori tertentu
 */

require_once __DIR__ . '/init.php';

// Validasi method HTTP: hanya GET
require_method(['GET']);

// Kasus 1: Detail Kategori Tunggal (GET /api/kategori.php?id=...)
if (isset($_GET['id'])) {
    $id_raw = trim($_GET['id']);

    if ($id_raw === '' || !ctype_digit($id_raw) || (int)$id_raw <= 0) {
        json_error('Parameter ID kategori harus berupa bilangan bulat positif.', 400);
    }

    $id = (int)$id_raw;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT k.id, k.nama_kategori, k.deskripsi, k.created_at,
                COUNT(p.id) AS jumlah_produk
         FROM kategori k
         LEFT JOIN produk p ON k.id = p.kategori_id
         WHERE k.id = ?
         GROUP BY k.id
         LIMIT 1"
    );

    if (!$stmt) {
        json_error('Gagal menyiapkan query database: ' . mysqli_error($conn), 500);
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $kategori = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$kategori) {
        json_error("Kategori dengan ID $id tidak ditemukan.", 404);
    }

    $data = [
        'id'            => (int)$kategori['id'],
        'nama_kategori' => $kategori['nama_kategori'],
        'deskripsi'     => $kategori['deskripsi'],
        'jumlah_produk' => (int)$kategori['jumlah_produk'],
        'created_at'    => $kategori['created_at'],
    ];

    json_response('success', 'Detail kategori berhasil diambil.', $data, 200);
}

// Kasus 2: Seluruh Kategori (GET /api/kategori.php)
$sql = "SELECT k.id, k.nama_kategori, k.deskripsi, k.created_at,
               COUNT(p.id) AS jumlah_produk
        FROM kategori k
        LEFT JOIN produk p ON k.id = p.kategori_id
        GROUP BY k.id
        ORDER BY k.id ASC";

$result = mysqli_query($conn, $sql);
if (!$result) {
    json_error('Gagal mengeksekusi query database: ' . mysqli_error($conn), 500);
}

$daftar_kategori = [];
while ($row = mysqli_fetch_assoc($result)) {
    $daftar_kategori[] = [
        'id'            => (int)$row['id'],
        'nama_kategori' => $row['nama_kategori'],
        'deskripsi'     => $row['deskripsi'],
        'jumlah_produk' => (int)$row['jumlah_produk'],
        'created_at'    => $row['created_at'],
    ];
}

$response_data = [
    'total'    => count($daftar_kategori),
    'kategori' => $daftar_kategori
];

json_response('success', 'Daftar kategori berhasil diambil.', $response_data, 200);
