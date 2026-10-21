<?php
session_start();
/** @var mysqli $koneksi */

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'peminjam'
) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/koneksi.php';

$id_user = (int) $_SESSION['user_id'];

// Menghitung jumlah peminjaman milik pengguna
function hitungPeminjaman($koneksi, $id_user, $status = null)
{
    if ($status !== null) {
        $query = "SELECT COUNT(*) AS total
                  FROM peminjaman
                  WHERE id_user = ? AND status = ?";

        $stmt = mysqli_prepare($koneksi, $query);
        mysqli_stmt_bind_param($stmt, "is", $id_user, $status);
    } else {
        $query = "SELECT COUNT(*) AS total
                  FROM peminjaman
                  WHERE id_user = ?";

        $stmt = mysqli_prepare($koneksi, $query);
        mysqli_stmt_bind_param($stmt, "i", $id_user);
    }

    mysqli_stmt_execute($stmt);

    $hasil = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($hasil);

    mysqli_stmt_close($stmt);

    return (int) ($data['total'] ?? 0);
}

$total_peminjaman = hitungPeminjaman($koneksi, $id_user);
$total_menunggu = hitungPeminjaman($koneksi, $id_user, 'menunggu');
$total_disetujui = hitungPeminjaman($koneksi, $id_user, 'disetujui');
$total_selesai = hitungPeminjaman($koneksi, $id_user, 'selesai');

// Mengambil lima transaksi terbaru milik pengguna
$query = "SELECT
            id_peminjaman,
            tanggal_pengajuan,
            tanggal_pinjam,
            tanggal_rencana_kembali,
            status
          FROM peminjaman
          WHERE id_user = ?
          ORDER BY id_peminjaman DESC
          LIMIT 5";

$stmt = mysqli_prepare($koneksi, $query);
mysqli_stmt_bind_param($stmt, "i", $id_user);
mysqli_stmt_execute($stmt);

$hasil_peminjaman = mysqli_stmt_get_result($stmt);

$judul_halaman = "Dashboard Peminjam";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="mb-4">
        <h2 class="fw-bold">Dashboard Peminjam</h2>

        <p class="text-secondary">
            Selamat datang,
            <?= htmlspecialchars($_SESSION['nama'] ?? 'Peminjam'); ?>.
            Kelola peminjaman perlengkapan outdoor kamu di sini.
        </p>
    </div>

    <!-- RINGKASAN PEMINJAMAN -->
    <div class="row g-4 mb-5">

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Total Peminjaman
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_peminjaman; ?>
                    </h2>

                    <span class="badge text-bg-primary">
                        Semua Transaksi
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Menunggu Persetujuan
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_menunggu; ?>
                    </h2>

                    <span class="badge text-bg-warning">
                        Menunggu
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Disetujui
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_disetujui; ?>
                    </h2>

                    <span class="badge text-bg-success">
                        Disetujui
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Selesai
                    </p>

                    <h2 class="fw-bold">
                        <?= $total_selesai; ?>
                    </h2>

                    <span class="badge text-bg-secondary">
                        Selesai
                    </span>
                </div>
            </div>
        </div>

    </div>

    <!-- MENU PEMINJAM -->
    <section class="mb-5">
        <h4 class="fw-bold mb-3">Menu Peminjam</h4>

        <div class="row g-3">

            <div class="col-md-4">
                <a href="<?= BASE_URL; ?>/alat.php"
                   class="text-decoration-none text-dark">

                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Daftar Alat</h5>

                            <p class="text-secondary mb-0">
                                Lihat perlengkapan outdoor,
                                stok, dan kondisi barang.
                            </p>
                        </div>
                    </div>

                </a>
            </div>

            <div class="col-md-4">
                <a href="<?= BASE_URL; ?>/peminjam/ajukan_peminjaman.php"
                   class="text-decoration-none text-dark">

                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Ajukan Peminjaman</h5>

                            <p class="text-secondary mb-0">
                                Pilih perlengkapan dan ajukan
                                peminjaman sesuai kebutuhan.
                            </p>
                        </div>
                    </div>

                </a>
            </div>

            <div class="col-md-4">
                <a href="<?= BASE_URL; ?>/peminjam/peminjaman_saya.php"
                   class="text-decoration-none text-dark">

                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">Peminjaman Saya</h5>

                            <p class="text-secondary mb-0">
                                Periksa status pengajuan dan
                                riwayat peminjaman pribadi.
                            </p>
                        </div>
                    </div>

                </a>
            </div>

        </div>
    </section>

    <!-- RIWAYAT PEMINJAMAN -->
    <section>
        <h4 class="fw-bold mb-3">Peminjaman Terbaru</h4>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">

                <table class="table table-striped table-hover align-middle mb-0">

                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">ID</th>
                            <th>Tanggal Pengajuan</th>
                            <th>Tanggal Pinjam</th>
                            <th>Rencana Kembali</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (mysqli_num_rows($hasil_peminjaman) > 0): ?>

                            <?php while (
                                $peminjaman = mysqli_fetch_assoc($hasil_peminjaman)
                            ): ?>

                                <?php
                                $warna_status = [
                                    'menunggu' => 'warning',
                                    'disetujui' => 'success',
                                    'ditolak' => 'danger',
                                    'selesai' => 'secondary'
                                ];

                                $status = $peminjaman['status'];
                                $warna = $warna_status[$status] ?? 'secondary';
                                ?>

                                <tr>
                                    <td class="ps-3">
                                        <?= (int) $peminjaman['id_peminjaman']; ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $peminjaman['tanggal_pengajuan']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $peminjaman['tanggal_pinjam']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $peminjaman['tanggal_rencana_kembali']
                                        ); ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-<?= $warna; ?>">
                                            <?= ucfirst(
                                                htmlspecialchars($status)
                                            ); ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="5"
                                    class="text-center text-secondary py-4">
                                    Kamu belum memiliki transaksi peminjaman.
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>

                </table>

            </div>
        </div>
    </section>

    <div class="mt-4">
        <a href="<?= BASE_URL; ?>/logout.php"
           class="btn btn-outline-secondary">
            Logout
        </a>
    </div>

</main>

<?php
mysqli_stmt_close($stmt);
include '../template/footer.php';
?>
