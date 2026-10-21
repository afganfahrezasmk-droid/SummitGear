<?php
session_start();

require_once 'config/koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Pengguna yang sudah login diarahkan ke dashboard sesuai role
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';

    if ($role === 'admin') {
        header("Location: admin/index.php");
    } elseif ($role === 'petugas') {
        header("Location: petugas/index.php");
    } elseif ($role === 'peminjam') {
        header("Location: peminjam/index.php");
    } else {
        header("Location: index.php");
    }

    exit;
}

function daftarEscape($nilai)
{
    return htmlspecialchars(
        (string) $nilai,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Token keamanan form
if (empty($_SESSION['csrf_daftar'])) {
    $_SESSION['csrf_daftar'] = bin2hex(random_bytes(32));
}

$pesan = '';
$jenis_pesan = 'danger';

// Pesan setelah pendaftaran berhasil
if (isset($_GET['berhasil']) && $_GET['berhasil'] === '1') {
    $pesan = 'Pendaftaran berhasil! Silakan login menggunakan akun yang sudah dibuat.';
    $jenis_pesan = 'success';
}

// Proses pendaftaran
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_daftar'], $token)
        ) {
            throw new InvalidArgumentException(
                'Permintaan tidak valid. Silakan muat ulang halaman.'
            );
        }

        $nama = trim($_POST['nama'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $konfirmasi = $_POST['konfirmasi_password'] ?? '';

        // Validasi data
        if (
            $nama === '' ||
            $username === '' ||
            $password === '' ||
            $konfirmasi === ''
        ) {
            throw new InvalidArgumentException(
                'Semua kolom wajib diisi.'
            );
        }

        if (strlen($nama) > 100) {
            throw new InvalidArgumentException(
                'Nama maksimal 100 karakter.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            throw new InvalidArgumentException(
                'Username harus 3-50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.'
            );
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException(
                'Password minimal 8 karakter.'
            );
        }

        if ($password !== $konfirmasi) {
            throw new InvalidArgumentException(
                'Konfirmasi password tidak cocok.'
            );
        }

        // Periksa apakah username sudah digunakan
        $query = "SELECT id_user
                  FROM users
                  WHERE username = ?
                  LIMIT 1";

        $stmt = mysqli_prepare($koneksi, $query);

        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);

        $hasil = mysqli_stmt_get_result($stmt);
        $username_ada = mysqli_num_rows($hasil) > 0;

        mysqli_stmt_close($stmt);

        if ($username_ada) {
            throw new InvalidArgumentException(
                'Username sudah digunakan. Silakan pilih username lain.'
            );
        }

        // Hash password sebelum disimpan
        $password_hash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        // Role ditentukan oleh sistem, bukan oleh pengunjung
        $query = "INSERT INTO users
                    (nama, username, password, role)
                  VALUES (?, ?, ?, 'peminjam')";

        $stmt = mysqli_prepare($koneksi, $query);

        mysqli_stmt_bind_param(
            $stmt,
            "sss",
            $nama,
            $username,
            $password_hash
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Arahkan kembali ke halaman pendaftaran
        header("Location: daftar.php?berhasil=1");
        exit;

    } catch (InvalidArgumentException $e) {
        $pesan = $e->getMessage();
        $jenis_pesan = 'danger';

    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            $pesan = 'Username sudah digunakan. Silakan pilih username lain.';
            $jenis_pesan = 'danger';
        } else {
            error_log('Pendaftaran SummitGear: ' . $e->getMessage());

            $pesan = 'Pendaftaran gagal diproses. Silakan coba kembali.';
            $jenis_pesan = 'danger';
        }
    }
}

$judul_halaman = "Daftar Akun";
include 'template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">

            <div class="card border-0 shadow-sm">

                <div class="card-body p-4 p-md-5">

                    <h2 class="fw-bold mb-2">Buat Akun Peminjam</h2>

                    <p class="text-secondary mb-4">
                        Daftarkan akunmu untuk mengajukan peminjaman
                        perlengkapan outdoor di SummitGear.
                    </p>

                    <?php if ($pesan !== ''): ?>
                        <div class="alert alert-<?= daftarEscape($jenis_pesan); ?>"
                             role="alert">
                            <?= daftarEscape($pesan); ?>
                        </div>
                    <?php endif; ?>

                    <form action="daftar.php" method="POST">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= daftarEscape($_SESSION['csrf_daftar']); ?>">

                        <div class="mb-3">
                            <label for="nama" class="form-label">
                                Nama Lengkap
                            </label>

                            <input type="text"
                                   class="form-control"
                                   id="nama"
                                   name="nama"
                                   maxlength="100"
                                   autocomplete="name"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label for="username" class="form-label">
                                Username
                            </label>

                            <input type="text"
                                   class="form-control"
                                   id="username"
                                   name="username"
                                   minlength="3"
                                   maxlength="50"
                                   autocomplete="username"
                                   required>

                            <div class="form-text">
                                Gunakan 3-50 karakter tanpa spasi.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">
                                Password
                            </label>

                            <input type="password"
                                   class="form-control"
                                   id="password"
                                   name="password"
                                   minlength="8"
                                   autocomplete="new-password"
                                   required>

                            <div class="form-text">
                                Password minimal 8 karakter.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="konfirmasi_password"
                                   class="form-label">
                                Konfirmasi Password
                            </label>

                            <input type="password"
                                   class="form-control"
                                   id="konfirmasi_password"
                                   name="konfirmasi_password"
                                   minlength="8"
                                   autocomplete="new-password"
                                   required>
                        </div>

                        <button type="submit"
                                class="btn btn-success w-100">
                            Daftar sebagai Peminjam
                        </button>

                    </form>

                    <div class="text-center mt-4">
                        <span class="text-secondary">
                            Sudah punya akun?
                        </span>

                        <a href="login.php"
                           class="text-decoration-none">
                            Login di sini
                        </a>
                    </div>

                </div>
            </div>

        </div>
    </div>

</main>

<?php include 'template/footer.php'; ?>
