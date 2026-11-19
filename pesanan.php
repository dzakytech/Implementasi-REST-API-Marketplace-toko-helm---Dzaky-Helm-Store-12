<?php
/**
 * REST API Endpoint: Pesanan
 * 
 * Mendukung:
 * - POST /api/pesanan.php       -> Membuat pesanan baru (Checkout / Order creation)
 * - GET  /api/pesanan.php?id=1  -> Mengambil detail pesanan berdasarkan ID
 * - GET  /api/pesanan.php?user_id=2 -> Mengambil daftar pesanan milik user tertentu
 */

require_once __DIR__ . '/init.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Validasi method HTTP: hanya POST dan GET
require_method(['POST', 'GET']);

/* ============================================================
 * HANDLER: POST /api/pesanan.php (Buat Pesanan Baru)
 * ============================================================ */
if ($method === 'POST') {
    $input = get_request_body();

    // 1. Validasi User ID
    $user_id_raw = $input['user_id'] ?? null;
    if ($user_id_raw === null || !ctype_digit((string)$user_id_raw) || (int)$user_id_raw <= 0) {
        json_error("Field 'user_id' wajib diisi dengan ID pengguna yang valid.", 400);
    }
    $user_id = (int)$user_id_raw;

    // Pastikan user terdaftar di database
    $stmt = mysqli_prepare($conn, "SELECT id, nama, email, alamat FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        json_error('Gagal menyiapkan query verifikasi pengguna: ' . mysqli_error($conn), 500);
    }
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $res_user = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($res_user);
    mysqli_stmt_close($stmt);

    if (!$user) {
        json_error("Pengguna dengan ID $user_id tidak ditemukan di database.", 404);
    }

    // 2. Validasi Alamat Pengiriman
    $alamat_pengiriman = trim($input['alamat_pengiriman'] ?? '');
    if ($alamat_pengiriman === '') {
        // Fallback ke alamat profil user jika ada
        $alamat_pengiriman = trim($user['alamat'] ?? '');
    }
    if ($alamat_pengiriman === '') {
        json_error("Field 'alamat_pengiriman' wajib diisi.", 400);
    }

    // 3. Validasi Metode Pembayaran
    $metode_valid = ['transfer_bank', 'cod', 'ewallet'];
    $metode_pembayaran = trim($input['metode_pembayaran'] ?? 'transfer_bank');
    if (!in_array($metode_pembayaran, $metode_valid, true)) {
        json_error("Metode pembayaran '$metode_pembayaran' tidak valid. Pilihan yang tersedia: " . implode(', ', $metode_valid), 400);
    }

    // 4. Parsing Items yang dipesan
    $raw_items = [];
    $is_from_cart = false;

    if (!empty($input['items']) && is_array($input['items'])) {
        $raw_items = $input['items'];
    } elseif (isset($input['produk_id'])) {
        // Format single item sederhana: {"produk_id": 1, "jumlah": 2}
        $raw_items = [
            [
                'produk_id' => $input['produk_id'],
                'jumlah'    => $input['jumlah'] ?? 1
            ]
        ];
    } else {
        // Coba periksa apakah user memiliki item di keranjang
        $stmt = mysqli_prepare($conn, "SELECT produk_id, jumlah FROM keranjang WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $user_id);
            mysqli_stmt_execute($stmt);
            $res_cart = mysqli_stmt_get_result($stmt);
            while ($c = mysqli_fetch_assoc($res_cart)) {
                $raw_items[] = [
                    'produk_id' => (int)$c['produk_id'],
                    'jumlah'    => (int)$c['jumlah']
                ];
            }
            mysqli_stmt_close($stmt);
            if (!empty($raw_items)) {
                $is_from_cart = true;
            }
        }
    }

    if (empty($raw_items)) {
        json_error("Item pesanan tidak boleh kosong. Harap kirim array 'items' (contoh: [{\"produk_id\": 1, \"jumlah\": 2}]) atau tambahkan produk ke keranjang.", 400);
    }

    // Normalisasi dan konsolidasi kuantitas item
    $items_grouped = [];
    foreach ($raw_items as $index => $item) {
        $pid = isset($item['produk_id']) ? (int)$item['produk_id'] : 0;
        $qty = isset($item['jumlah']) ? (int)$item['jumlah'] : (isset($item['qty']) ? (int)$item['qty'] : 0);

        if ($pid <= 0) {
            json_error("Data item ke-" . ($index + 1) . " tidak memiliki 'produk_id' yang valid.", 400);
        }
        if ($qty <= 0) {
            json_error("Jumlah pesanan untuk produk ID $pid harus minimal 1.", 400);
        }

        if (isset($items_grouped[$pid])) {
            $items_grouped[$pid] += $qty;
        } else {
            $items_grouped[$pid] = $qty;
        }
    }

    // 5. Eksekusi Transaksi Database (ACID)
    mysqli_begin_transaction($conn);

    try {
        $verified_items = [];
        $total_harga = 0.0;

        foreach ($items_grouped as $pid => $qty) {
            // Kunci baris produk dengan FOR UPDATE untuk mencegah race condition stok
            $stmt = mysqli_prepare($conn, "SELECT id, nama_produk, harga, stok FROM produk WHERE id = ? FOR UPDATE");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query cek produk ID $pid: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, 'i', $pid);
            mysqli_stmt_execute($stmt);
            $res_p = mysqli_stmt_get_result($stmt);
            $p = mysqli_fetch_assoc($res_p);
            mysqli_stmt_close($stmt);

            if (!$p) {
                http_response_code(404);
                throw new Exception("Produk dengan ID $pid tidak ditemukan.");
            }

            $stok_aktual = (int)$p['stok'];
            if ($qty > $stok_aktual) {
                http_response_code(400);
                throw new Exception("Stok untuk produk '{$p['nama_produk']}' (ID: $pid) tidak mencukupi. Sisa stok: $stok_aktual, dipesan: $qty.");
            }

            $harga_satuan = (float)$p['harga'];
            $subtotal = $harga_satuan * $qty;
            $total_harga += $subtotal;

            $verified_items[] = [
                'produk_id'    => $pid,
                'nama_produk'  => $p['nama_produk'],
                'harga_satuan' => $harga_satuan,
                'jumlah'       => $qty,
                'subtotal'     => $subtotal,
            ];
        }

        // Simpan header pesanan
        $status_awal = 'pending';
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO pesanan (user_id, total_harga, status, alamat_pengiriman, metode_pembayaran)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new Exception("Gagal menyiapkan insert pesanan: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'idsss', $user_id, $total_harga, $status_awal, $alamat_pengiriman, $metode_pembayaran);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Gagal menyimpan header pesanan: " . mysqli_stmt_error($stmt));
        }
        $pesanan_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        // Simpan rincian detail pesanan dan kurangi stok produk
        foreach ($verified_items as $item) {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO detail_pesanan (pesanan_id, produk_id, jumlah, harga_satuan, subtotal)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan insert detail pesanan: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, 'iiidd', $pesanan_id, $item['produk_id'], $item['jumlah'], $item['harga_satuan'], $item['subtotal']);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Gagal menyimpan detail produk ID {$item['produk_id']}: " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);

            // Kurangi stok
            $stmt = mysqli_prepare($conn, "UPDATE produk SET stok = stok - ? WHERE id = ? AND stok >= ?");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan update stok: " . mysqli_error($conn));
            }
            mysqli_stmt_bind_param($stmt, 'iii', $item['jumlah'], $item['produk_id'], $item['jumlah']);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) < 1) {
                throw new Exception("Gagal mengurangi stok produk ID {$item['produk_id']}. Kemungkinan stok berkurang.");
            }
            mysqli_stmt_close($stmt);
        }

        // Jika pesanan berasal dari keranjang, kosongkan keranjang pengguna
        if ($is_from_cart) {
            $stmt = mysqli_prepare($conn, "DELETE FROM keranjang WHERE user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }

        // Commit transaksi ACID
        mysqli_commit($conn);

        // Siapkan respons sukses
        $created_order = [
            'pesanan_id'         => (int)$pesanan_id,
            'user'               => [
                'id'    => (int)$user['id'],
                'nama'  => $user['nama'],
                'email' => $user['email'],
            ],
            'total_harga'        => (float)$total_harga,
            'total_harga_format' => format_rupiah($total_harga),
            'status'             => $status_awal,
            'alamat_pengiriman'  => $alamat_pengiriman,
            'metode_pembayaran'  => $metode_pembayaran,
            'jumlah_item'        => count($verified_items),
            'items'              => $verified_items,
            'created_at'         => date('Y-m-d H:i:s'),
        ];

        json_response('success', 'Pesanan berhasil dibuat.', $created_order, 201);

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $code = http_response_code();
        if ($code < 400 || $code > 599) {
            $code = 500;
        }
        json_error($e->getMessage(), $code);
    }
}

/* ============================================================
 * HANDLER: GET /api/pesanan.php (Ambil Pesanan untuk Verifikasi)
 * ============================================================ */
if ($method === 'GET') {
    // Detail satu pesanan: ?id=...
    if (isset($_GET['id'])) {
        $id_raw = trim($_GET['id']);
        if ($id_raw === '' || !ctype_digit($id_raw) || (int)$id_raw <= 0) {
            json_error('Parameter ID pesanan harus berupa bilangan bulat positif.', 400);
        }
        $id = (int)$id_raw;

        $stmt = mysqli_prepare(
            $conn,
            "SELECT p.*, u.nama AS nama_pembeli, u.email AS email_pembeli
             FROM pesanan p
             JOIN users u ON p.user_id = u.id
             WHERE p.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            json_error('Gagal menyiapkan query database: ' . mysqli_error($conn), 500);
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $order = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$order) {
            json_error("Pesanan dengan ID $id tidak ditemukan.", 404);
        }

        // Ambil item rincian pesanan
        $stmt = mysqli_prepare(
            $conn,
            "SELECT dp.*, pr.nama_produk, pr.gambar
             FROM detail_pesanan dp
             JOIN produk pr ON dp.produk_id = pr.id
             WHERE dp.pesanan_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res_items = mysqli_stmt_get_result($stmt);
        $items = [];
        while ($it = mysqli_fetch_assoc($res_items)) {
            $items[] = [
                'produk_id'    => (int)$it['produk_id'],
                'nama_produk'  => $it['nama_produk'],
                'gambar'       => $it['gambar'],
                'harga_satuan' => (float)$it['harga_satuan'],
                'jumlah'       => (int)$it['jumlah'],
                'subtotal'     => (float)$it['subtotal'],
            ];
        }
        mysqli_stmt_close($stmt);

        $data = [
            'pesanan_id'         => (int)$order['id'],
            'user'               => [
                'id'    => (int)$order['user_id'],
                'nama'  => $order['nama_pembeli'],
                'email' => $order['email_pembeli'],
            ],
            'total_harga'        => (float)$order['total_harga'],
            'total_harga_format' => format_rupiah($order['total_harga']),
            'status'             => $order['status'],
            'alamat_pengiriman'  => $order['alamat_pengiriman'],
            'metode_pembayaran'  => $order['metode_pembayaran'],
            'created_at'         => $order['created_at'],
            'items'              => $items,
        ];

        json_response('success', 'Detail pesanan berhasil diambil.', $data, 200);
    }

    // Daftar pesanan (bisa difilter berdasarkan ?user_id=...)
    $sql = "SELECT p.*, u.nama AS nama_pembeli, u.email AS email_pembeli
            FROM pesanan p
            JOIN users u ON p.user_id = u.id";
    $params = [];
    $types = "";

    if (isset($_GET['user_id']) && trim($_GET['user_id']) !== '') {
        $sql .= " WHERE p.user_id = ?";
        $params[] = (int)$_GET['user_id'];
        $types .= "i";
    }

    $sql .= " ORDER BY p.id DESC LIMIT 50";

    if (!empty($params)) {
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    } else {
        $res = mysqli_query($conn, $sql);
    }

    $daftar_pesanan = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $daftar_pesanan[] = [
            'pesanan_id'         => (int)$row['id'],
            'user_id'            => (int)$row['user_id'],
            'nama_pembeli'       => $row['nama_pembeli'],
            'total_harga'        => (float)$row['total_harga'],
            'total_harga_format' => format_rupiah($row['total_harga']),
            'status'             => $row['status'],
            'alamat_pengiriman'  => $row['alamat_pengiriman'],
            'metode_pembayaran'  => $row['metode_pembayaran'],
            'created_at'         => $row['created_at'],
        ];
    }

    if (isset($stmt) && $stmt) {
        mysqli_stmt_close($stmt);
    }

    $response_data = [
        'total'   => count($daftar_pesanan),
        'pesanan' => $daftar_pesanan,
    ];

    json_response('success', 'Daftar pesanan berhasil diambil.', $response_data, 200);
}
