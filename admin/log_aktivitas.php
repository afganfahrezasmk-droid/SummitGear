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

function logEscape($nilai)
{
    return htmlspecialchars(
        (string) $nilai,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Menghitung total aktivitas yang tercatat
$query_total = "SELECT COUNT(*) AS total FROM log_aktivitas";
$hasil_total = mysqli_query($koneksi, $query_total);
$data_total = mysqli_fetch_assoc($hasil_total);

$total_aktivitas = (int) ($data_total['total'] ?? 0);

// Mengambil maksimal 100 aktivitas terbaru
$query = "SELECT
            l.id_log,
            l.aktivitas,
            l.waktu,
            l.keterangan,
            u.nama,
            u.username,
            u.role
          FROM log_aktivitas l
          LEFT JOIN users u
            ON l.id_user = u.id_user
          ORDER BY l.id_log DESC
          LIMIT 100";

$hasil = mysqli_query($koneksi, $query);

$judul_halaman = "Log Aktivitas";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-3 mb-4">

        <div>
            <h2 class="fw-bold">Log Aktivitas</h2>

            <p class="text-secondary mb-0">
                Riwayat aktivitas pengguna SummitGear.
            </p>
        </div>

        <a href="<?= BASE_URL; ?>/admin/index.php"
           class="btn btn-outline-secondary">
            Kembali ke Dashboard
        </a>

    </div>

    <!-- RINGKASAN -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">

            <p class="text-secondary mb-2">
                Total Aktivitas Tercatat
            </p>

            <h2 class="fw-bold mb-0">
                <?= $total_aktivitas; ?>
            </h2>

        </div>
    </div>

    <!-- TABEL AKTIVITAS -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Riwayat Aktivitas</h5>
        </div>

        <div class="table-responsive">

            <table class="table table-striped table-hover align-middle mb-0">

                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">No.</th>
                        <th>Pengguna</th>
                        <th>Role</th>
                        <th>Aktivitas</th>
                        <th>Keterangan</th>
                        <th>Waktu</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (mysqli_num_rows($hasil) > 0): ?>

                        <?php $no = 1; ?>

                        <?php while ($log = mysqli_fetch_assoc($hasil)): ?>

                            <?php
                            $warna_role = [
                                'admin' => 'danger',
                                'petugas' => 'primary',
                                'peminjam' => 'success'
                            ];

                            $role = $log['role'] ?? '';
                            $warna = $warna_role[$role] ?? 'secondary';
                            ?>

                            <tr>
                                <td class="ps-3">
                                    <?= $no++; ?>
                                </td>

                                <td>
                                    <?php if ($log['nama'] !== null): ?>

                                        <strong>
                                            <?= logEscape($log['nama']); ?>
                                        </strong>

                                        <div class="small text-secondary">
                                            @<?= logEscape($log['username']); ?>
                                        </div>

                                    <?php else: ?>

                                        <span class="text-secondary">
                                            Pengguna tidak ditemukan
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge text-bg-<?= $warna; ?>">
                                        <?= $role !== ''
                                            ? ucfirst(logEscape($role))
                                            : '-'; ?>
                                    </span>
                                </td>

                                <td>
                                    <?= logEscape($log['aktivitas']); ?>
                                </td>

                                <td>
                                    <?= $log['keterangan'] !== null
                                        && $log['keterangan'] !== ''
                                        ? nl2br(logEscape($log['keterangan']))
                                        : '-'; ?>
                                </td>

                                <td>
                                    <?= logEscape($log['waktu']); ?>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6"
                                class="text-center text-secondary py-5">

                                <h5 class="fw-semibold">
                                    Belum Ada Aktivitas
                                </h5>

                                <p class="mb-0">
                                    Aktivitas pengguna akan muncul
                                    setelah fitur aplikasi digunakan.
                                </p>

                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>
    </div>

    <p class="small text-secondary mt-3">
        Halaman ini menampilkan maksimal 100 aktivitas terbaru.
    </p>

</main>

<?php include '../template/footer.php'; ?>
