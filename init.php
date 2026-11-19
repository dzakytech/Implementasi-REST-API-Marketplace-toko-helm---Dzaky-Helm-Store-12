<?php
/**
 * Bootstrap & Helper untuk REST API Marketplace Dzaky Helm Store
 */

// Tandai sebagai request API untuk penanganan error di koneksi.php
define('IS_API_REQUEST', true);

// Header respons standar API
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Preflight CORS request handler
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Hubungkan ke konfigurasi & database
require_once __DIR__ . '/../config/koneksi.php';

/**
 * Mengirimkan respon JSON standar
 * 
 * @param string $status 'success' | 'error'
 * @param string $message
 * @param mixed $data
 * @param int $http_code
 */
function json_response($status, $message, $data = null, $http_code = 200)
{
    http_response_code($http_code);
    $response = [
        'status'  => $status,
        'message' => $message,
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Mengirimkan pesan error JSON
 * 
 * @param string $message
 * @param int $http_code
 * @param mixed $errors
 */
function json_error($message, $http_code = 400, $errors = null)
{
    http_response_code($http_code);
    $response = [
        'status'  => 'error',
        'message' => $message,
    ];
    if ($errors !== null) {
        $response['errors'] = $errors;
    }
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Validasi HTTP Method yang diperbolehkan
 * 
 * @param array $allowed_methods
 */
function require_method(array $allowed_methods)
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowed_methods, true)) {
        header("Allow: " . implode(', ', $allowed_methods));
        json_error("Metode HTTP '$method' tidak diizinkan. Gunakan: " . implode(', ', $allowed_methods), 405);
    }
}

/**
 * Mengambil body input baik dari JSON raw payload (php://input) maupun form-urlencoded / POST
 * 
 * @return array
 */
function get_request_body()
{
    $raw_input = file_get_contents('php://input');
    if (!empty($raw_input)) {
        // Hapus UTF-8 BOM jika ada
        $raw_input = preg_replace('/^\xEF\xBB\xBF/', '', $raw_input);
        $json_data = json_decode($raw_input, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
            return $json_data;
        }
    }
    return !empty($_POST) ? $_POST : [];
}

/**
 * Helper untuk format data produk agar tipe data presisi (integer/float) dan menambahkan full image URL
 * 
 * @param array $p
 * @return array
 */
function format_produk_data(array $p)
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $gambar_nama = (string)($p['gambar'] ?? 'default.jpg');
    $gambar_url = $protocol . $host . gambar_url($gambar_nama);

    return [
        'id'            => (int)$p['id'],
        'kategori_id'   => (int)$p['kategori_id'],
        'nama_kategori' => $p['nama_kategori'] ?? null,
        'nama_produk'   => $p['nama_produk'],
        'deskripsi'     => $p['deskripsi'],
        'harga'         => (float)$p['harga'],
        'harga_format'  => format_rupiah($p['harga']),
        'stok'          => (int)$p['stok'],
        'gambar'        => $gambar_nama,
        'gambar_url'    => $gambar_url,
        'created_at'    => $p['created_at'] ?? null,
    ];
}
