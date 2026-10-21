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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function sg_escape($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

// Membuat token untuk melindungi form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Memproses persetujuan atau penolakan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaksi_dimulai = false;

    try {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new InvalidArgumentException(
                'Permintaan tidak valid. Silakan coba kembali.'
            );
        }

        $aksi = $_POST['aksi'] ?? '';
        $id_peminjaman = filter_var(
            $_POST['id_peminjaman'] ?? '',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (
            !in_array($aksi, ['setujui', 'tolak'], true) ||
            $id_peminjaman === false
        ) {
            throw new InvalidArgumentException(
                'Data pengajuan tidak valid.'
            );
        }

        mysqli_begin_transaction($koneksi);
        $transaksi_dimulai = true;

        // Periksa status dan kunci transaksi
        $query = "SELECT status
                  FROM peminjaman
                  WHERE id_peminjaman = ?
                  FOR UPDATE";

        $stmt = mysqli_prepare($koneksi, $query);
        mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
        mysqli_stmt_execute($stmt);

        $hasil_status = mysqli_stmt_get_result($stmt);
        $data_peminjaman = mysqli_fetch_assoc($hasil_status);

        mysqli_stmt_close($stmt);

        if (!$data_peminjaman) {
            throw new InvalidArgumentException(
                'Pengajuan peminjaman tidak ditemukan.'
            );
        }

        if ($data_peminjaman['status'] !== 'menunggu') {
            throw new InvalidArgumentException(
                'Pengajuan ini sudah diproses sebelumnya.'
            );
        }

        if ($aksi === 'setujui') {
            // Ambil rincian barang dan kunci data inventaris
            $query = "SELECT
                        d.id_alat,
                        d.jumlah,
                        a.nama_alat,
                        a.stok,
                        a.kondisi
                      FROM detail_peminjaman d
                      INNER JOIN alat a
                        ON d.id_alat = a.id_alat
                      WHERE d.id_peminjaman = ?
                      ORDER BY d.id_alat ASC
                      FOR UPDATE";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);

            $hasil_detail = mysqli_stmt_get_result($stmt);
            $daftar_barang = [];

            while ($barang = mysqli_fetch_assoc($hasil_detail)) {
                $daftar_barang[] = $barang;
            }

            mysqli_stmt_close($stmt);

            if (count($daftar_barang) === 0) {
                throw new InvalidArgumentException(
                    'Pengajuan tidak memiliki rincian barang.'
                );
            }

            // Pastikan semua barang bisa dipinjam
            foreach ($daftar_barang as $barang) {
                if ($barang['kondisi'] !== 'baik') {
                    throw new InvalidArgumentException(
                        'Alat "' . $barang['nama_alat'] .
                        '" tidak dalam kondisi baik.'
                    );
                }

                if ((int) $barang['stok'] < (int) $barang['jumlah']) {
                    throw new InvalidArgumentException(
                        'Stok alat "' . $barang['nama_alat'] .
                        '" tidak mencukupi.'
                    );
                }
            }

            // Kurangi stok secara aman ketika disetujui
            $query_stok = "UPDATE alat
                           SET stok = stok - ?
                           WHERE id_alat = ?
                             AND stok >= ?
                             AND kondisi = 'baik'";

            $stmt_stok = mysqli_prepare($koneksi, $query_stok);

            foreach ($daftar_barang as $barang) {
                $jumlah = (int) $barang['jumlah'];
                $id_alat = (int) $barang['id_alat'];

                mysqli_stmt_bind_param(
                    $stmt_stok,
                    "iii",
                    $jumlah,
                    $id_alat,
                    $jumlah
                );

                mysqli_stmt_execute($stmt_stok);

                if (mysqli_stmt_affected_rows($stmt_stok) !== 1) {
                    throw new InvalidArgumentException(
                        'Stok alat berubah. Periksa kembali ketersediaan.'
                    );
                }
            }

            mysqli_stmt_close($stmt_stok);

            // Ubah status pengajuan
            $query = "UPDATE peminjaman
                      SET status = 'disetujui'
                      WHERE id_peminjaman = ?
                        AND status = 'menunggu'";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);

            if (mysqli_stmt_affected_rows($stmt) !== 1) {
                throw new InvalidArgumentException(
                    'Status pengajuan gagal diperbarui.'
                );
            }

            mysqli_stmt_close($stmt);

            $aktivitas = "Menyetujui peminjaman #" . $id_peminjaman;

            $_SESSION['pesan'] =
                'Pengajuan berhasil disetujui dan stok alat telah diperbarui.';

        } else {
            // Penolakan tidak mengubah stok
            $query = "UPDATE peminjaman
                      SET status = 'ditolak'
                      WHERE id_peminjaman = ?
                        AND status = 'menunggu'";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);

            if (mysqli_stmt_affected_rows($stmt) !== 1) {
                throw new InvalidArgumentException(
                    'Status pengajuan gagal diperbarui.'
                );
            }

            mysqli_stmt_close($stmt);

            $aktivitas = "Menolak peminjaman #" . $id_peminjaman;

            $_SESSION['pesan'] = 'Pengajuan peminjaman ditolak.';
        }

        // Catat aktivitas petugas
        $id_petugas = (int) $_SESSION['user_id'];

        $query_log = "INSERT INTO log_aktivitas (id_user, aktivitas)
                      VALUES (?, ?)";

        $stmt_log = mysqli_prepare($koneksi, $query_log);
        mysqli_stmt_bind_param(
            $stmt_log,
            "is",
            $id_petugas,
            $aktivitas
        );

        mysqli_stmt_execute($stmt_log);
        mysqli_stmt_close($stmt_log);

        // Simpan seluruh perubahan sekaligus
        mysqli_commit($koneksi);
        $transaksi_dimulai = false;

        $_SESSION['jenis_pesan'] = 'success';

    } catch (InvalidArgumentException $e) {
        if ($transaksi_dimulai) {
            mysqli_rollback($koneksi);
        }

        $_SESSION['pesan'] = $e->getMessage();
        $_SESSION['jenis_pesan'] = 'warning';

    } catch (Throwable $e) {
        if ($transaksi_dimulai) {
            mysqli_rollback($koneksi);
        }

        error_log('Proses persetujuan SummitGear: ' . $e->getMessage());

        $_SESSION['pesan'] =
            'Proses gagal. Tidak ada perubahan yang disimpan.';

        $_SESSION['jenis_pesan'] = 'danger';
    }

    header("Location: peminjaman.php");
    exit;
}

// Ambil maksimal 100 transaksi terbaru beserta rincian barang
$query = "SELECT
            p.id_peminjaman,
            p.tanggal_pengajuan,
            p.tanggal_pinjam,
            p.tanggal_rencana_kembali,
            p.status,
            p.catatan,
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
                  status,
                  catatan
              FROM peminjaman
              ORDER BY id_peminjaman DESC
              LIMIT 100
          ) p
          INNER JOIN users u
            ON p.id_user = u.id_user
          LEFT JOIN detail_peminjaman d
            ON p.id_peminjaman = d.id_peminjaman
          LEFT JOIN alat a
            ON d.id_alat = a.id_alat
          ORDER BY p.id_peminjaman DESC, d.id_detail ASC";

$hasil = mysqli_query($koneksi, $query);

$daftar_peminjaman = [];

while ($row = mysqli_fetch_assoc($hasil)) {
    $id = (int) $row['id_peminjaman'];

    if (!isset($daftar_peminjaman[$id])) {
        $daftar_peminjaman[$id] = [
            'id_peminjaman' => $id,
            'nama_peminjam' => $row['nama_peminjam'],
            'tanggal_pengajuan' => $row['tanggal_pengajuan'],
            'tanggal_pinjam' => $row['tanggal_pinjam'],
            'tanggal_rencana_kembali' => $row['tanggal_rencana_kembali'],
            'status' => $row['status'],
            'catatan' => $row['catatan'],
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

$pesan = $_SESSION['pesan'] ?? '';
$jenis_pesan = $_SESSION['jenis_pesan'] ?? 'success';

unset($_SESSION['pesan'], $_SESSION['jenis_pesan']);

$judul_halaman = "Kelola Peminjaman";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-2 mb-4">

        <div>
            <h2 class="fw-bold">Kelola Peminjaman</h2>
            <p class="text-secondary mb-0">
                Periksa pengajuan dan proses persetujuan peminjaman alat.
            </p>
        </div>

        <a href="<?= BASE_URL; ?>/petugas/index.php"
           class="btn btn-outline-secondary">
            Kembali ke Dashboard
        </a>

    </div>

    <?php if ($pesan !== ''): ?>
        <?php
        $jenis_valid = ['success', 'warning', 'danger', 'info'];
        $kelas_alert = in_array($jenis_pesan, $jenis_valid, true)
            ? $jenis_pesan
            : 'info';
        ?>

        <div class="alert alert-<?= $kelas_alert; ?> alert-dismissible fade show"
             role="alert">

            <?= sg_escape($pesan); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Daftar Pengajuan</h5>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">

                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">ID / Peminjam</th>
                        <th>Barang</th>
                        <th>Tanggal Pinjam</th>
                        <th>Rencana Kembali</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($daftar_peminjaman) > 0): ?>

                        <?php foreach ($daftar_peminjaman as $data): ?>

                            <?php
                            $status = $data['status'];

                            $warna_status = [
                                'menunggu' => 'warning',
                                'disetujui' => 'success',
                                'ditolak' => 'danger',
                                'selesai' => 'secondary'
                            ];

                            $label_status = [
                                'menunggu' => 'Menunggu',
                                'disetujui' => 'Disetujui',
                                'ditolak' => 'Ditolak',
                                'selesai' => 'Selesai'
                            ];

                            $warna = $warna_status[$status] ?? 'secondary';
                            $label = $label_status[$status] ?? ucfirst($status);
                            ?>

                            <tr>
                                <td class="ps-3">
                                    <strong>
                                        #<?= $data['id_peminjaman']; ?>
                                    </strong>

                                    <div>
                                        <?= sg_escape($data['nama_peminjam']); ?>
                                    </div>

                                    <small class="text-secondary">
                                        Diajukan:
                                        <?= sg_escape($data['tanggal_pengajuan']); ?>
                                    </small>
                                </td>

                                <td>
                                    <?php if (count($data['barang']) > 0): ?>

                                        <ul class="mb-0 ps-3">
                                            <?php foreach ($data['barang'] as $barang): ?>
                                                <li>
                                                    <?= sg_escape($barang['nama_alat']); ?>
                                                    (<?= $barang['jumlah']; ?> unit)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>

                                    <?php else: ?>

                                        <span class="text-secondary">
                                            Tidak ada rincian barang
                                        </span>

                                    <?php endif; ?>

                                    <?php if (!empty($data['catatan'])): ?>
                                        <div class="small text-secondary mt-2">
                                            Catatan:
                                            <?= nl2br(sg_escape($data['catatan'])); ?>
                                        </div>
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

                                <td class="text-center">
                                    <?php if ($status === 'menunggu'): ?>

                                        <div class="d-flex flex-column gap-2">

                                            <form action="peminjaman.php"
                                                  method="POST"
                                                  onsubmit="return confirm('Setujui pengajuan ini? Stok alat akan dikurangi.');">

                                                <input type="hidden"
                                                       name="csrf_token"
                                                       value="<?= sg_escape($_SESSION['csrf_token']); ?>">

                                                <input type="hidden"
                                                       name="id_peminjaman"
                                                       value="<?= $data['id_peminjaman']; ?>">

                                                <input type="hidden"
                                                       name="aksi"
                                                       value="setujui">

                                                <button type="submit"
                                                        class="btn btn-sm btn-success w-100">
                                                    Setujui
                                                </button>
                                            </form>

                                            <form action="peminjaman.php"
                                                  method="POST"
                                                  onsubmit="return confirm('Yakin ingin menolak pengajuan ini?');">

                                                <input type="hidden"
                                                       name="csrf_token"
                                                       value="<?= sg_escape($_SESSION['csrf_token']); ?>">

                                                <input type="hidden"
                                                       name="id_peminjaman"
                                                       value="<?= $data['id_peminjaman']; ?>">

                                                <input type="hidden"
                                                       name="aksi"
                                                       value="tolak">

                                                <button type="submit"
                                                        class="btn btn-sm btn-outline-danger w-100">
                                                    Tolak
                                                </button>
                                            </form>

                                        </div>

                                    <?php else: ?>

                                        <span class="text-secondary small">
                                            Sudah diproses
                                        </span>

                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6"
                                class="text-center text-secondary py-5">
                                Belum ada pengajuan peminjaman.
                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

    <p class="text-secondary small mt-3">
        Halaman ini menampilkan maksimal 100 transaksi terbaru.
    </p>

</main>

<?php include '../template/footer.php'; ?>
