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

function sg_escape($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

$id_user = (int) $_SESSION['user_id'];

// Mengambil transaksi dan rincian barang milik pengguna yang login
$query = "SELECT
            p.id_peminjaman,
            p.tanggal_pengajuan,
            p.tanggal_pinjam,
            p.tanggal_rencana_kembali,
            p.status,
            p.catatan,
            d.id_detail,
            d.jumlah,
            a.nama_alat
          FROM peminjaman p
          LEFT JOIN detail_peminjaman d
            ON p.id_peminjaman = d.id_peminjaman
          LEFT JOIN alat a
            ON d.id_alat = a.id_alat
          WHERE p.id_user = ?
          ORDER BY p.id_peminjaman DESC, d.id_detail ASC";

$stmt = mysqli_prepare($koneksi, $query);
mysqli_stmt_bind_param($stmt, "i", $id_user);
mysqli_stmt_execute($stmt);

$hasil = mysqli_stmt_get_result($stmt);

$peminjaman = [];

while ($row = mysqli_fetch_assoc($hasil)) {
    $id = (int) $row['id_peminjaman'];

    // Membuat satu kelompok untuk setiap transaksi
    if (!isset($peminjaman[$id])) {
        $peminjaman[$id] = [
            'id_peminjaman' => $id,
            'tanggal_pengajuan' => $row['tanggal_pengajuan'],
            'tanggal_pinjam' => $row['tanggal_pinjam'],
            'tanggal_rencana_kembali' => $row['tanggal_rencana_kembali'],
            'status' => $row['status'],
            'catatan' => $row['catatan'],
            'barang' => []
        ];
    }

    // Menambahkan rincian barang ke transaksi tersebut
    if ($row['id_detail'] !== null) {
        $peminjaman[$id]['barang'][] = [
            'nama_alat' => $row['nama_alat'] ?? 'Alat tidak ditemukan',
            'jumlah' => (int) $row['jumlah']
        ];
    }
}

mysqli_stmt_close($stmt);

$warna_status = [
    'menunggu' => 'warning',
    'disetujui' => 'success',
    'ditolak' => 'danger',
    'selesai' => 'secondary'
];

$label_status = [
    'menunggu' => 'Menunggu Persetujuan',
    'disetujui' => 'Disetujui',
    'ditolak' => 'Ditolak',
    'selesai' => 'Selesai'
];

$judul_halaman = "Peminjaman Saya";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-3 mb-4">

        <div>
            <h2 class="fw-bold">Peminjaman Saya</h2>
            <p class="text-secondary mb-0">
                Pantau pengajuan dan riwayat peminjaman perlengkapanmu.
            </p>
        </div>

        <a href="<?= BASE_URL; ?>/peminjam/ajukan_peminjaman.php"
           class="btn btn-success">
            + Ajukan Peminjaman
        </a>

    </div>

    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Riwayat Peminjaman</h5>
        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">ID</th>
                            <th>Barang yang Dipinjam</th>
                            <th>Tanggal Pinjam</th>
                            <th>Rencana Kembali</th>
                            <th>Status</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($peminjaman) > 0): ?>

                            <?php foreach ($peminjaman as $data): ?>

                                <?php
                                $status = $data['status'];
                                $warna = $warna_status[$status] ?? 'secondary';
                                $label = $label_status[$status] ?? ucfirst($status);
                                ?>

                                <tr>
                                    <td class="ps-3">
                                        #<?= $data['id_peminjaman']; ?>

                                        <div class="text-secondary small mt-1">
                                            <?= sg_escape($data['tanggal_pengajuan']); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?php if (count($data['barang']) > 0): ?>

                                            <ul class="mb-0 ps-3">
                                                <?php foreach ($data['barang'] as $barang): ?>
                                                    <li>
                                                        <?= sg_escape($barang['nama_alat']); ?>
                                                        <span class="text-secondary">
                                                            (<?= $barang['jumlah']; ?> unit)
                                                        </span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>

                                        <?php else: ?>

                                            <span class="text-secondary">
                                                Belum ada rincian barang
                                            </span>

                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= sg_escape($data['tanggal_pinjam']); ?>
                                    </td>

                                    <td>
                                        <?= sg_escape($data['tanggal_rencana_kembali']); ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-<?= $warna; ?>">
                                            <?= sg_escape($label); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= $data['catatan'] !== ''
                                            ? nl2br(sg_escape($data['catatan']))
                                            : '<span class="text-secondary">-</span>'; ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6"
                                    class="text-center py-5">

                                    <h5 class="fw-semibold">
                                        Belum Ada Peminjaman
                                    </h5>

                                    <p class="text-secondary">
                                        Kamu belum mengajukan peminjaman alat.
                                    </p>

                                    <a href="<?= BASE_URL; ?>/peminjam/ajukan_peminjaman.php"
                                       class="btn btn-success">
                                        Ajukan Peminjaman
                                    </a>

                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>
    </div>

    <a href="<?= BASE_URL; ?>/peminjam/index.php"
       class="btn btn-outline-secondary mt-4">
        Kembali ke Dashboard
    </a>

</main>

<?php include '../template/footer.php'; ?>
