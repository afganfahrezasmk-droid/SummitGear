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

function laporanAdminEscape($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

// Ringkasan peminjaman berdasarkan status
$query_ringkasan = "SELECT
    COUNT(*) AS total,
    SUM(status = 'menunggu') AS menunggu,
    SUM(status = 'disetujui') AS disetujui,
    SUM(status = 'ditolak') AS ditolak,
    SUM(status = 'selesai') AS selesai
    FROM peminjaman";

$hasil_ringkasan = mysqli_query($koneksi, $query_ringkasan);
$ringkasan = mysqli_fetch_assoc($hasil_ringkasan);

// Ringkasan pengembalian dan total denda
$query_denda = "SELECT
    COUNT(*) AS total_pengembalian,
    COALESCE(SUM(denda), 0) AS total_denda
    FROM pengembalian";

$hasil_denda = mysqli_query($koneksi, $query_denda);
$data_denda = mysqli_fetch_assoc($hasil_denda);

// Ambil maksimal 100 transaksi peminjaman terbaru
$query_peminjaman = "SELECT
    p.id_peminjaman,
    p.tanggal_pengajuan,
    p.tanggal_pinjam,
    p.tanggal_rencana_kembali,
    p.status,
    u.nama AS nama_peminjam,
    d.id_detail,
    d.jumlah,
    a.nama_alat
FROM (
    SELECT
        id_peminjaman,
        id_user,
        tanggal_pengajuan,
        tanggal_pinjam,
        tanggal_rencana_kembali,
        status
    FROM peminjaman
    ORDER BY id_peminjaman DESC
    LIMIT 100
) AS p
INNER JOIN users u
    ON p.id_user = u.id_user
LEFT JOIN detail_peminjaman d
    ON p.id_peminjaman = d.id_peminjaman
LEFT JOIN alat a
    ON d.id_alat = a.id_alat
ORDER BY p.id_peminjaman DESC, d.id_detail ASC";

$hasil_peminjaman = mysqli_query($koneksi, $query_peminjaman);

// Kelompokkan beberapa barang dalam satu transaksi
$daftar_peminjaman = [];

while ($row = mysqli_fetch_assoc($hasil_peminjaman)) {
    $id = (int) $row['id_peminjaman'];

    if (!isset($daftar_peminjaman[$id])) {
        $daftar_peminjaman[$id] = [
            'id_peminjaman' => $id,
            'nama_peminjam' => $row['nama_peminjam'],
            'tanggal_pengajuan' => $row['tanggal_pengajuan'],
            'tanggal_pinjam' => $row['tanggal_pinjam'],
            'tanggal_rencana_kembali' => $row['tanggal_rencana_kembali'],
            'status' => $row['status'],
            'barang' => []
        ];
    }

    if ($row['id_detail'] !== null) {
        $daftar_peminjaman[$id]['barang'][] = [
            'nama_alat' => $row['nama_alat'] ?? 'Alat tidak ditemukan',
            'jumlah' => (int) $row['jumlah']
        ];
    }
}

// Ambil maksimal 100 catatan pengembalian terbaru
$query_pengembalian = "SELECT
    r.id_pengembalian,
    r.tanggal_pengembalian,
    r.kondisi_kembali,
    r.denda,
    r.catatan,
    p.id_peminjaman,
    u.nama AS nama_peminjam,
    a.nama_alat,
    d.jumlah
FROM (
    SELECT
        id_pengembalian,
        id_detail,
        tanggal_pengembalian,
        kondisi_kembali,
        denda,
        catatan
    FROM pengembalian
    ORDER BY id_pengembalian DESC
    LIMIT 100
) AS r
INNER JOIN detail_peminjaman d
    ON r.id_detail = d.id_detail
INNER JOIN peminjaman p
    ON d.id_peminjaman = p.id_peminjaman
INNER JOIN users u
    ON p.id_user = u.id_user
INNER JOIN alat a
    ON d.id_alat = a.id_alat
ORDER BY r.id_pengembalian DESC";

$hasil_pengembalian = mysqli_query($koneksi, $query_pengembalian);

$warna_status = [
    'menunggu' => 'warning',
    'disetujui' => 'success',
    'ditolak' => 'danger',
    'selesai' => 'secondary'
];

$warna_kondisi = [
    'baik' => 'success',
    'rusak ringan' => 'warning',
    'rusak berat' => 'danger'
];

$judul_halaman = "Laporan Admin";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-3 mb-4">

        <div>
            <h2 class="fw-bold">Laporan SummitGear</h2>
            <p class="text-secondary mb-0">
                Ringkasan dan riwayat peminjaman perlengkapan outdoor.
            </p>
        </div>

        <div class="d-flex gap-2 d-print-none">
            <button type="button"
                    class="btn btn-success"
                    onclick="window.print()">
                Cetak Laporan
            </button>

            <a href="<?= BASE_URL; ?>/admin/index.php"
               class="btn btn-outline-secondary">
                Kembali
            </a>
        </div>

    </div>

    <p class="small text-secondary">
        Tanggal laporan: <?= date('d-m-Y'); ?>
    </p>

    <!-- RINGKASAN PEMINJAMAN -->
    <section class="mb-5">

        <h5 class="fw-bold mb-3">Ringkasan Peminjaman</h5>

        <div class="row g-3">

            <div class="col-6 col-lg">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="small text-secondary mb-2">
                            Total Pengajuan
                        </p>
                        <h3 class="fw-bold">
                            <?= (int) ($ringkasan['total'] ?? 0); ?>
                        </h3>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="small text-secondary mb-2">
                            Menunggu
                        </p>
                        <h3 class="fw-bold text-warning">
                            <?= (int) ($ringkasan['menunggu'] ?? 0); ?>
                        </h3>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="small text-secondary mb-2">
                            Disetujui
                        </p>
                        <h3 class="fw-bold text-success">
                            <?= (int) ($ringkasan['disetujui'] ?? 0); ?>
                        </h3>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="small text-secondary mb-2">
                            Ditolak
                        </p>
                        <h3 class="fw-bold text-danger">
                            <?= (int) ($ringkasan['ditolak'] ?? 0); ?>
                        </h3>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="small text-secondary mb-2">
                            Selesai
                        </p>
                        <h3 class="fw-bold">
                            <?= (int) ($ringkasan['selesai'] ?? 0); ?>
                        </h3>
                    </div>
                </div>
            </div>

        </div>

    </section>

    <!-- TABEL PEMINJAMAN -->
    <section class="mb-5">

        <h5 class="fw-bold mb-3">Data Peminjaman</h5>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">

                <table class="table table-striped table-hover align-middle mb-0">

                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">ID</th>
                            <th>Peminjam</th>
                            <th>Barang</th>
                            <th>Tanggal Pinjam</th>
                            <th>Rencana Kembali</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($daftar_peminjaman) > 0): ?>

                            <?php foreach ($daftar_peminjaman as $data): ?>

                                <?php
                                $status = $data['status'];
                                $warna = $warna_status[$status] ?? 'secondary';
                                ?>

                                <tr>
                                    <td class="ps-3">
                                        #<?= $data['id_peminjaman']; ?>
                                        <div class="small text-secondary">
                                            <?= laporanAdminEscape(
                                                $data['tanggal_pengajuan']
                                            ); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= laporanAdminEscape(
                                            $data['nama_peminjam']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?php if (count($data['barang']) > 0): ?>

                                            <ul class="mb-0 ps-3">
                                                <?php foreach ($data['barang'] as $barang): ?>
                                                    <li>
                                                        <?= laporanAdminEscape(
                                                            $barang['nama_alat']
                                                        ); ?>
                                                        (<?= $barang['jumlah']; ?> unit)
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>

                                        <?php else: ?>
                                            <span class="text-secondary">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= laporanAdminEscape(
                                            $data['tanggal_pinjam']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= laporanAdminEscape(
                                            $data['tanggal_rencana_kembali']
                                        ); ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-<?= $warna; ?>">
                                            <?= ucfirst(
                                                laporanAdminEscape($status)
                                            ); ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6"
                                    class="text-center text-secondary py-4">
                                    Belum ada data peminjaman.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>
        </div>

        <p class="small text-secondary mt-2">
            Menampilkan maksimal 100 transaksi terbaru.
        </p>

    </section>

    <!-- RINGKASAN PENGEMBALIAN -->
    <section>

        <h5 class="fw-bold mb-3">Laporan Pengembalian</h5>

        <div class="row g-3 mb-3">

            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-secondary mb-2">
                            Total Pengembalian
                        </p>
                        <h3 class="fw-bold">
                            <?= (int) ($data_denda['total_pengembalian'] ?? 0); ?>
                        </h3>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <p class="text-secondary mb-2">
                            Total Denda Tercatat
                        </p>
                        <h3 class="fw-bold">
                            Rp<?= number_format(
                                (float) ($data_denda['total_denda'] ?? 0),
                                0,
                                ',',
                                '.'
                            ); ?>
                        </h3>
                    </div>
                </div>
            </div>

        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">

                <table class="table table-striped table-hover align-middle mb-0">

                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">Tanggal Kembali</th>
                            <th>Peminjam</th>
                            <th>Barang</th>
                            <th>Kondisi</th>
                            <th>Denda</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (mysqli_num_rows($hasil_pengembalian) > 0): ?>

                            <?php while (
                                $data = mysqli_fetch_assoc($hasil_pengembalian)
                            ): ?>

                                <?php
                                $warna = $warna_kondisi[
                                    $data['kondisi_kembali']
                                ] ?? 'secondary';
                                ?>

                                <tr>
                                    <td class="ps-3">
                                        <?= laporanAdminEscape(
                                            $data['tanggal_pengembalian']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= laporanAdminEscape(
                                            $data['nama_peminjam']
                                        ); ?>

                                        <div class="small text-secondary">
                                            Peminjaman #<?= (int) $data['id_peminjaman']; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= laporanAdminEscape(
                                            $data['nama_alat']
                                        ); ?>

                                        <div class="small text-secondary">
                                            <?= (int) $data['jumlah']; ?> unit
                                        </div>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-<?= $warna; ?>">
                                            <?= ucfirst(
                                                laporanAdminEscape(
                                                    $data['kondisi_kembali']
                                                )
                                            ); ?>
                                        </span>
                                    </td>

                                    <td>
                                        Rp<?= number_format(
                                            (float) $data['denda'],
                                            0,
                                            ',',
                                            '.'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= laporanAdminEscape(
                                            $data['catatan'] ?: '-'
                                        ); ?>
                                    </td>
                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6"
                                    class="text-center text-secondary py-4">
                                    Belum ada data pengembalian.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>
        </div>

        <p class="small text-secondary mt-2">
            Menampilkan maksimal 100 catatan pengembalian terbaru.
        </p>

    </section>

</main>

<?php include '../template/footer.php'; ?>
