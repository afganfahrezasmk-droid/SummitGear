<?php
session_start();
/** @var mysqli $koneksi */

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'petugas'
) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/koneksi.php';

// Menghitung jumlah data
function hitungJumlah($koneksi, $query)
{
    $hasil = mysqli_query($koneksi, $query);

    if (!$hasil) {
        return 0;
    }

    $data = mysqli_fetch_assoc($hasil);

    return (int) ($data['total'] ?? 0);
}

$total_menunggu = hitungJumlah(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM peminjaman
     WHERE status = 'menunggu'"
);

$total_disetujui = hitungJumlah(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM peminjaman
     WHERE status = 'disetujui'"
);

$total_ditolak = hitungJumlah(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM peminjaman
     WHERE status = 'ditolak'"
);

$total_pengembalian = hitungJumlah(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM pengembalian"
);

// Mengambil lima pengajuan terbaru
$query = "SELECT
            p.id_peminjaman,
            p.tanggal_pengajuan,
            p.tanggal_pinjam,
            p.tanggal_rencana_kembali,
            p.status,
            u.nama
          FROM peminjaman p
          INNER JOIN users u
            ON p.id_user = u.id_user
          WHERE p.status = 'menunggu'
          ORDER BY p.tanggal_pengajuan DESC
          LIMIT 5";

$hasil_pengajuan = mysqli_query($koneksi, $query);

$judul_halaman = "Dashboard Petugas";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="mb-4">
        <h2 class="fw-bold">Dashboard Petugas</h2>

        <p class="text-secondary">
            Selamat datang,
            <?= htmlspecialchars($_SESSION['nama'] ?? 'Petugas'); ?>.
            Kelola pengajuan peminjaman dan pengembalian alat SummitGear.
        </p>
    </div>

    <!-- RINGKASAN DATA -->
    <div class="row g-4 mb-5">

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Menunggu Persetujuan
                    </p>
                    <h2 class="fw-bold"><?= $total_menunggu; ?></h2>
                    <span class="badge text-bg-warning">
                        Perlu Diproses
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Peminjaman Disetujui
                    </p>
                    <h2 class="fw-bold"><?= $total_disetujui; ?></h2>
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
                        Peminjaman Ditolak
                    </p>
                    <h2 class="fw-bold"><?= $total_ditolak; ?></h2>
                    <span class="badge text-bg-danger">
                        Ditolak
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-secondary mb-2">
                        Data Pengembalian
                    </p>
                    <h2 class="fw-bold"><?= $total_pengembalian; ?></h2>
                    <span class="badge text-bg-primary">
                        Tercatat
                    </span>
                </div>
            </div>
        </div>

    </div>

    <!-- MENU PETUGAS -->
    <section class="mb-5">
        <h4 class="fw-bold mb-3">Menu Petugas</h4>

        <div class="row g-3">

            <div class="col-md-4">
                <a href="<?= BASE_URL; ?>/petugas/peminjaman.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">
                                Persetujuan Peminjaman
                            </h5>
                            <p class="text-secondary mb-0">
                                Periksa pengajuan dan tentukan
                                apakah peminjaman disetujui atau ditolak.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4">
                <a href="<?= BASE_URL; ?>/petugas/pengembalian.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">
                                Pemantauan Pengembalian
                            </h5>
                            <p class="text-secondary mb-0">
                                Pantau pengembalian dan kondisi
                                perlengkapan yang dipinjam.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-4">
                <a href="<?= BASE_URL; ?>/petugas/laporan.php"
                   class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h5 class="fw-bold">
                                Laporan Peminjaman
                            </h5>
                            <p class="text-secondary mb-0">
                                Lihat dan cetak laporan transaksi
                                peminjaman serta pengembalian.
                            </p>
                        </div>
                    </div>
                </a>
            </div>

        </div>
    </section>

    <!-- PENGAJUAN TERBARU -->
    <section>
        <h4 class="fw-bold mb-3">Pengajuan Terbaru</h4>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">

                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">ID</th>
                            <th>Nama Peminjam</th>
                            <th>Tanggal Pengajuan</th>
                            <th>Tanggal Pinjam</th>
                            <th>Rencana Kembali</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (
                            $hasil_pengajuan &&
                            mysqli_num_rows($hasil_pengajuan) > 0
                        ): ?>

                            <?php while (
                                $pengajuan = mysqli_fetch_assoc($hasil_pengajuan)
                            ): ?>

                                <tr>
                                    <td class="ps-3">
                                        <?= (int) $pengajuan['id_peminjaman']; ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($pengajuan['nama']); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($pengajuan['tanggal_pengajuan']); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($pengajuan['tanggal_pinjam']); ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($pengajuan['tanggal_rencana_kembali']); ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-warning">
                                            Menunggu
                                        </span>
                                    </td>
                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6"
                                    class="text-center text-secondary py-4">
                                    Belum ada pengajuan peminjaman yang menunggu persetujuan.
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>

                </table>
            </div>
        </div>
    </section>

</main>

<?php include '../template/footer.php'; ?>
