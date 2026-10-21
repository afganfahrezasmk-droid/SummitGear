<?php
session_start();
/** @var mysqli $koneksi */

require_once '../config/koneksi.php';

// Pastikan halaman diakses melalui form POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php");
    exit;
}

// Ambil data dari form login
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// Periksa apakah input kosong
if ($username === '' || $password === '') {
    $_SESSION['login_error'] = "Username dan password wajib diisi.";
    header("Location: ../login.php");
    exit;
}

// Cari akun berdasarkan username
$query = "SELECT id_user, nama, username, password, role
          FROM users
          WHERE username = ?";

$stmt = mysqli_prepare($koneksi, $query);

if (!$stmt) {
    $_SESSION['login_error'] = "Terjadi kesalahan sistem. Silakan coba lagi.";
    header("Location: ../login.php");
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

// Periksa akun dan verifikasi password
if (!$user || !password_verify($password, $user['password'])) {
    $_SESSION['login_error'] = "Username atau password salah.";
    header("Location: ../login.php");
    exit;
}

// Buat session baru setelah login berhasil
session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id_user'];
$_SESSION['nama'] = $user['nama'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];

// Arahkan pengguna sesuai role
switch ($user['role']) {
    case 'admin':
        header("Location: ../admin/index.php");
        break;

    case 'petugas':
        header("Location: ../petugas/index.php");
        break;

    case 'peminjam':
        header("Location: ../peminjam/index.php");
        break;

    default:
        session_unset();
        session_destroy();
        session_start();

        $_SESSION['login_error'] = "Role pengguna tidak valid.";
        header("Location: ../login.php");
        break;
}

exit;
