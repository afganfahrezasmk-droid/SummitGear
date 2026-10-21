
<?php
session_start();

// Hapus semua data session pengguna
$_SESSION = [];

// Hapus cookie session jika digunakan
if (ini_get("session.use_cookies")) {
    $parameter = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parameter['path'],
        $parameter['domain'],
        $parameter['secure'],
        $parameter['httponly']
    );
}

// Hancurkan session
session_destroy();

// Kembali ke halaman login
header("Location: login.php");
exit;
