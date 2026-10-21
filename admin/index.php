
<?php
session_start();
/** @var mysqli $koneksi */

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/koneksi.php';

// Menghitung jumlah data pada setiap tabel
function hitungJumlah($koneksi, $tabel)
{
    $query = "SELECT COUNT(*) AS total FROM `$tabel`";
    $hasil = mysqli_query($koneksi, $query);

    if (!$hasil) {
        return 0;
    }

    $data = mysqli_fetch_assoc($hasil);

    return (int) ($data['total'] ?? 0);
}

$total_user = hitungJumlah($koneksi, 'users');
$total_alat = hitungJumlah($koneksi, 'alat');
$total_peminjaman = hitungJumlah($koneksi, 'peminjaman');
$total_pengembalian = hitungJumlah($koneksi, 'pengembalian');

$judul_halaman = "Dashboard Admin";

include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="mb-4">
        <h2 class="fw-bold">Dashboard Admin</h2>

        <p class="text-secondary">
            Selamat datang,
            <?= htmlspecialchars($_SESSION['nama'] ?? 'Admin'); ?>.
            Kelola data SummitGear melalui halaman ini.
        </p>
    </div>

    <div class="row g-4 mb-5">

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Total Pengguna
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_user; ?>
                    </h2>

                    <span class="badge text-bg-primary">
                        Data Akun
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Total Alat
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_alat; ?>
                    </h2>

                    <span class="badge text-bg-success">
                        Inventaris
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Total Peminjaman
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_peminjaman; ?>
                    </h2>

                    <span class="badge text-bg-warning">
                        Transaksi
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Total Pengembalian
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_pengembalian; ?>
                    </h2>

                    <span class="badge text-bg-info">
                        Pengembalian
                    </span>
                </div>
            </div>
        </div>

    </div>

    <section>
        <h4 class="fw-bold mb-3">Menu Pengelolaan</h4>

        <div class="row g-3">

            <div class="col-md-6 col-lg-4">
                <a href="<?= BASE_URL; ?>/admin/users.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Kelola Pengguna</h5>
                            <p class="text-secondary mb-0">
                                Tambah, lihat, ubah, dan hapus akun pengguna.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-lg-4">
                <a href="<?= BASE_URL; ?>/admin/kategori.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Kelola Kategori</h5>
                            <p class="text-secondary mb-0">
                                Atur kategori perlengkapan outdoor.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-lg-4">
                <a href="<?= BASE_URL; ?>/admin/alat.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Kelola Alat</h5>
                            <p class="text-secondary mb-0">
                                Atur inventaris, stok, dan kondisi alat.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-lg-4">
                <a href="<?= BASE_URL; ?>/admin/peminjaman.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Data Peminjaman</h5>
                            <p class="text-secondary mb-0">
                                Lihat dan kelola transaksi peminjaman.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-lg-4">
                <a href="<?= BASE_URL; ?>/admin/pengembalian.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Data Pengembalian</h5>
                            <p class="text-secondary mb-0">
                                Pantau pengembalian dan kondisi alat.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-lg-4">
                <a href="<?= BASE_URL; ?>/admin/laporan.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Laporan</h5>
                            <p class="text-secondary mb-0">
                                Lihat dan cetak laporan peminjaman.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

        </div>
    </section>

</main>

<?php include '../template/footer.php'; ?>
