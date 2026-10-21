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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function admPinjamEscape($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

function admPinjamTanggal($nilai)
{
    if (!is_string($nilai)) {
        return false;
    }

    $tanggal = DateTimeImmutable::createFromFormat('!Y-m-d', $nilai);
    $error = DateTimeImmutable::getLastErrors();

    if (
        $tanggal === false ||
        $tanggal->format('Y-m-d') !== $nilai ||
        (
            $error !== false &&
            (
                $error['warning_count'] > 0 ||
                $error['error_count'] > 0
            )
        )
    ) {
        return false;
    }

    return $tanggal;
}

function admPinjamLog($koneksi, $id_user, $aktivitas)
{
    $query = "INSERT INTO log_aktivitas (id_user, aktivitas)
              VALUES (?, ?)";

    $stmt = mysqli_prepare($koneksi, $query);

    mysqli_stmt_bind_param($stmt, "is", $id_user, $aktivitas);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Membuat token keamanan form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$daftar_role = ['admin', 'petugas', 'peminjam'];

// Memproses tambah, edit, dan hapus peminjaman
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaksi_dimulai = false;

    try {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new InvalidArgumentException(
                'Permintaan tidak valid. Silakan muat ulang halaman.'
            );
        }

        $aksi = $_POST['aksi'] ?? '';
        $id_admin = (int) $_SESSION['user_id'];

        // TAMBAH PEMINJAMAN
        if ($aksi === 'tambah') {
            $id_user = filter_var(
                $_POST['id_user'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $tanggal_pinjam = trim($_POST['tanggal_pinjam'] ?? '');
            $tanggal_kembali = trim(
                $_POST['tanggal_rencana_kembali'] ?? ''
            );
            $catatan = trim($_POST['catatan'] ?? '');
            $jumlah_input = $_POST['jumlah'] ?? [];

            if (
                $id_user === false ||
                !is_array($jumlah_input)
            ) {
                throw new InvalidArgumentException(
                    'Data peminjaman tidak valid.'
                );
            }

            $tgl_pinjam = admPinjamTanggal($tanggal_pinjam);
            $tgl_kembali = admPinjamTanggal($tanggal_kembali);

            if (!$tgl_pinjam || !$tgl_kembali) {
                throw new InvalidArgumentException(
                    'Tanggal peminjaman dan pengembalian tidak valid.'
                );
            }

            if ($tgl_pinjam < new DateTimeImmutable('today')) {
                throw new InvalidArgumentException(
                    'Tanggal peminjaman tidak boleh di masa lalu.'
                );
            }

            if ($tgl_kembali < $tgl_pinjam) {
                throw new InvalidArgumentException(
                    'Tanggal pengembalian tidak boleh sebelum tanggal pinjam.'
                );
            }

            // Ambil alat yang dipilih dari form
            $items = [];

            foreach ($jumlah_input as $id_alat_input => $jumlah_raw) {
                $id_alat = filter_var(
                    (string) $id_alat_input,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if ($id_alat === false) {
                    throw new InvalidArgumentException(
                        'ID alat tidak valid.'
                    );
                }

                $jumlah_raw = trim((string) $jumlah_raw);

                if ($jumlah_raw === '' || $jumlah_raw === '0') {
                    continue;
                }

                if (
                    !ctype_digit($jumlah_raw) ||
                    (int) $jumlah_raw < 1
                ) {
                    throw new InvalidArgumentException(
                        'Jumlah alat harus berupa bilangan bulat positif.'
                    );
                }

                $items[] = [
                    'id_alat' => (int) $id_alat,
                    'jumlah' => (int) $jumlah_raw
                ];
            }

            if (count($items) === 0) {
                throw new InvalidArgumentException(
                    'Pilih minimal satu alat untuk dipinjam.'
                );
            }

            mysqli_begin_transaction($koneksi);
            $transaksi_dimulai = true;

            // Pastikan akun peminjam benar-benar tersedia
            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT id_user
                 FROM users
                 WHERE id_user = ? AND role = 'peminjam'
                 FOR UPDATE"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_user);
            mysqli_stmt_execute($stmt);

            $hasil_user = mysqli_stmt_get_result($stmt);
            $user_valid = mysqli_fetch_assoc($hasil_user);

            mysqli_stmt_close($stmt);

            if (!$user_valid) {
                throw new InvalidArgumentException(
                    'Akun peminjam tidak ditemukan.'
                );
            }

            // Periksa stok terbaru dan kondisi setiap alat
            $stmt_alat = mysqli_prepare(
                $koneksi,
                "SELECT id_alat, nama_alat, stok, kondisi
                 FROM alat
                 WHERE id_alat = ?
                 FOR UPDATE"
            );

            foreach ($items as $item) {
                $id_alat = $item['id_alat'];

                mysqli_stmt_bind_param($stmt_alat, "i", $id_alat);
                mysqli_stmt_execute($stmt_alat);

                $hasil_alat = mysqli_stmt_get_result($stmt_alat);
                $data_alat = mysqli_fetch_assoc($hasil_alat);

                if (!$data_alat) {
                    throw new InvalidArgumentException(
                        'Salah satu alat tidak ditemukan.'
                    );
                }

                if ($data_alat['kondisi'] !== 'baik') {
                    throw new InvalidArgumentException(
                        'Alat "' . $data_alat['nama_alat'] .
                        '" tidak dalam kondisi baik.'
                    );
                }

                if ($item['jumlah'] > (int) $data_alat['stok']) {
                    throw new InvalidArgumentException(
                        'Stok alat "' . $data_alat['nama_alat'] .
                        '" tidak mencukupi.'
                    );
                }
            }

            mysqli_stmt_close($stmt_alat);

            // Buat transaksi dalam status menunggu
            $query = "INSERT INTO peminjaman
                        (
                            id_user,
                            tanggal_pinjam,
                            tanggal_rencana_kembali,
                            status,
                            catatan
                        )
                      VALUES (?, ?, ?, 'menunggu', ?)";

            $stmt = mysqli_prepare($koneksi, $query);

            mysqli_stmt_bind_param(
                $stmt,
                "isss",
                $id_user,
                $tanggal_pinjam,
                $tanggal_kembali,
                $catatan
            );

            mysqli_stmt_execute($stmt);
            $id_peminjaman = mysqli_insert_id($koneksi);

            mysqli_stmt_close($stmt);

            // Simpan rincian alat
            $stmt_detail = mysqli_prepare(
                $koneksi,
                "INSERT INTO detail_peminjaman
                    (id_peminjaman, id_alat, jumlah)
                 VALUES (?, ?, ?)"
            );

            foreach ($items as $item) {
                $id_alat = $item['id_alat'];
                $jumlah = $item['jumlah'];

                mysqli_stmt_bind_param(
                    $stmt_detail,
                    "iii",
                    $id_peminjaman,
                    $id_alat,
                    $jumlah
                );

                mysqli_stmt_execute($stmt_detail);
            }

            mysqli_stmt_close($stmt_detail);

            admPinjamLog(
                $koneksi,
                $id_admin,
                "Admin membuat peminjaman #" . $id_peminjaman
            );

            mysqli_commit($koneksi);
            $transaksi_dimulai = false;

            $_SESSION['pesan'] =
                'Data peminjaman berhasil ditambahkan dengan status menunggu.';
            $_SESSION['jenis_pesan'] = 'success';

        // EDIT PEMINJAMAN
        } elseif ($aksi === 'edit') {
            $id_peminjaman = filter_var(
                $_POST['id_peminjaman'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $tanggal_pinjam = trim($_POST['tanggal_pinjam'] ?? '');
            $tanggal_kembali = trim(
                $_POST['tanggal_rencana_kembali'] ?? ''
            );
            $catatan = trim($_POST['catatan'] ?? '');

            if ($id_peminjaman === false) {
                throw new InvalidArgumentException(
                    'ID peminjaman tidak valid.'
                );
            }

            $tgl_pinjam = admPinjamTanggal($tanggal_pinjam);
            $tgl_kembali = admPinjamTanggal($tanggal_kembali);

            if (!$tgl_pinjam || !$tgl_kembali) {
                throw new InvalidArgumentException(
                    'Tanggal peminjaman dan pengembalian tidak valid.'
                );
            }

            if ($tgl_pinjam < new DateTimeImmutable('today')) {
                throw new InvalidArgumentException(
                    'Tanggal peminjaman tidak boleh di masa lalu.'
                );
            }

            if ($tgl_kembali < $tgl_pinjam) {
                throw new InvalidArgumentException(
                    'Rencana pengembalian tidak boleh sebelum tanggal pinjam.'
                );
            }

            mysqli_begin_transaction($koneksi);
            $transaksi_dimulai = true;

            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT status
                 FROM peminjaman
                 WHERE id_peminjaman = ?
                 FOR UPDATE"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $transaksi = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($stmt);

            if (!$transaksi) {
                throw new InvalidArgumentException(
                    'Data peminjaman tidak ditemukan.'
                );
            }

            if ($transaksi['status'] !== 'menunggu') {
                throw new InvalidArgumentException(
                    'Hanya peminjaman yang menunggu persetujuan yang dapat diedit.'
                );
            }

            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE peminjaman
                 SET tanggal_pinjam = ?,
                     tanggal_rencana_kembali = ?,
                     catatan = ?
                 WHERE id_peminjaman = ?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "sssi",
                $tanggal_pinjam,
                $tanggal_kembali,
                $catatan,
                $id_peminjaman
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            admPinjamLog(
                $koneksi,
                $id_admin,
                "Admin mengubah peminjaman #" . $id_peminjaman
            );

            mysqli_commit($koneksi);
            $transaksi_dimulai = false;

            $_SESSION['pesan'] =
                'Data peminjaman berhasil diperbarui.';
            $_SESSION['jenis_pesan'] = 'success';

        // HAPUS PEMINJAMAN
        } elseif ($aksi === 'hapus') {
            $id_peminjaman = filter_var(
                $_POST['id_peminjaman'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($id_peminjaman === false) {
                throw new InvalidArgumentException(
                    'ID peminjaman tidak valid.'
                );
            }

            mysqli_begin_transaction($koneksi);
            $transaksi_dimulai = true;

            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT status
                 FROM peminjaman
                 WHERE id_peminjaman = ?
                 FOR UPDATE"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $transaksi = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($stmt);

            if (!$transaksi) {
                throw new InvalidArgumentException(
                    'Data peminjaman tidak ditemukan.'
                );
            }

            if (
                !in_array(
                    $transaksi['status'],
                    ['menunggu', 'ditolak'],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Peminjaman yang sudah disetujui atau selesai tidak boleh dihapus karena terkait stok dan riwayat.'
                );
            }

            // Pastikan tidak ada pengembalian yang merujuk detail
            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT COUNT(*) AS total
                 FROM detail_peminjaman d
                 INNER JOIN pengembalian r
                    ON d.id_detail = r.id_detail
                 WHERE d.id_peminjaman = ?"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);

            $hasil_cek = mysqli_stmt_get_result($stmt);
            $cek_pengembalian = mysqli_fetch_assoc($hasil_cek);

            mysqli_stmt_close($stmt);

            if ((int) $cek_pengembalian['total'] > 0) {
                throw new InvalidArgumentException(
                    'Transaksi memiliki riwayat pengembalian dan tidak dapat dihapus.'
                );
            }

            // Hapus detail terlebih dahulu karena foreign key
            $stmt = mysqli_prepare(
                $koneksi,
                "DELETE FROM detail_peminjaman
                 WHERE id_peminjaman = ?"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $koneksi,
                "DELETE FROM peminjaman
                 WHERE id_peminjaman = ?"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            admPinjamLog(
                $koneksi,
                $id_admin,
                "Admin menghapus peminjaman #" . $id_peminjaman
            );

            mysqli_commit($koneksi);
            $transaksi_dimulai = false;

            $_SESSION['pesan'] =
                'Data peminjaman berhasil dihapus.';
            $_SESSION['jenis_pesan'] = 'success';

        } else {
            throw new InvalidArgumentException(
                'Aksi peminjaman tidak valid.'
            );
        }

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

        error_log('Kelola peminjaman SummitGear: ' . $e->getMessage());

        $_SESSION['pesan'] =
            'Data gagal diproses. Periksa kembali database dan relasinya.';
        $_SESSION['jenis_pesan'] = 'danger';
    }

    header("Location: peminjaman.php");
    exit;
}

// Ambil data transaksi yang sedang diedit
$edit_peminjaman = null;

if (isset($_GET['edit'])) {
    $id_edit = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id_edit !== false) {
        $stmt = mysqli_prepare(
            $koneksi,
            "SELECT
                id_peminjaman,
                tanggal_pinjam,
                tanggal_rencana_kembali,
                catatan,
                status
             FROM peminjaman
             WHERE id_peminjaman = ?"
        );

        mysqli_stmt_bind_param($stmt, "i", $id_edit);
        mysqli_stmt_execute($stmt);

        $hasil_edit = mysqli_stmt_get_result($stmt);
        $edit_peminjaman = mysqli_fetch_assoc($hasil_edit);

        mysqli_stmt_close($stmt);

        if (
            !$edit_peminjaman ||
            $edit_peminjaman['status'] !== 'menunggu'
        ) {
            $_SESSION['pesan'] =
                'Hanya peminjaman berstatus menunggu yang dapat diedit.';
            $_SESSION['jenis_pesan'] = 'warning';

            header("Location: peminjaman.php");
            exit;
        }
    }
}

// Daftar akun peminjam untuk form tambah
$hasil_user = mysqli_query(
    $koneksi,
    "SELECT id_user, nama, username
     FROM users
     WHERE role = 'peminjam'
     ORDER BY nama ASC"
);

// Daftar alat tersedia
$hasil_alat = mysqli_query(
    $koneksi,
    "SELECT a.id_alat, a.nama_alat, a.stok, k.nama_kategori
     FROM alat a
     LEFT JOIN kategori k ON a.id_kategori = k.id_kategori
     WHERE a.stok > 0 AND a.kondisi = 'baik'
     ORDER BY a.nama_alat ASC"
);

// Ambil maksimal 100 transaksi terbaru
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
              SELECT *
              FROM peminjaman
              ORDER BY id_peminjaman DESC
              LIMIT 100
          ) p
          INNER JOIN users u ON p.id_user = u.id_user
          LEFT JOIN detail_peminjaman d
              ON p.id_peminjaman = d.id_peminjaman
          LEFT JOIN alat a ON d.id_alat = a.id_alat
          ORDER BY p.id_peminjaman DESC, d.id_detail ASC";

$hasil_peminjaman = mysqli_query($koneksi, $query);

// Kelompokkan barang dalam satu transaksi
$daftar_peminjaman = [];

while ($row = mysqli_fetch_assoc($hasil_peminjaman)) {
    $id = (int) $row['id_peminjaman'];

    if (!isset($daftar_peminjaman[$id])) {
        $daftar_peminjaman[$id] = [
            'id_peminjaman' => $id,
            'nama_peminjam' => $row['nama_peminjam'],
            'tanggal_pinjam' => $row['tanggal_pinjam'],
            'tanggal_rencana_kembali' => $row['tanggal_rencana_kembali'],
            'tanggal_pengajuan' => $row['tanggal_pengajuan'],
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
                Kelola pengajuan dan riwayat peminjaman SummitGear.
            </p>
        </div>

        <a href="<?= BASE_URL; ?>/admin/index.php"
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
            <?= admPinjamEscape($pesan); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM TAMBAH / EDIT -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">
                <?= $edit_peminjaman
                    ? 'Edit Data Peminjaman'
                    : 'Tambah Peminjaman'; ?>
            </h5>
        </div>

        <div class="card-body p-4">
            <form action="peminjaman.php" method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= admPinjamEscape($_SESSION['csrf_token']); ?>">

                <input type="hidden"
                       name="aksi"
                       value="<?= $edit_peminjaman ? 'edit' : 'tambah'; ?>">

                <?php if ($edit_peminjaman): ?>
                    <input type="hidden"
                           name="id_peminjaman"
                           value="<?= (int) $edit_peminjaman['id_peminjaman']; ?>">
                <?php endif; ?>

                <div class="row g-3">

                    <?php if (!$edit_peminjaman): ?>
                        <div class="col-md-6">
                            <label for="id_user" class="form-label">
                                Peminjam
                            </label>

                            <select name="id_user"
                                    id="id_user"
                                    class="form-select"
                                    required>
                                <option value="">Pilih peminjam</option>

                                <?php while ($user = mysqli_fetch_assoc($hasil_user)): ?>
                                    <option value="<?= (int) $user['id_user']; ?>">
                                        <?= admPinjamEscape($user['nama']); ?>
                                        (<?= admPinjamEscape($user['username']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-6">
                        <label for="tanggal_pinjam" class="form-label">
                            Tanggal Pinjam
                        </label>

                        <input type="date"
                               name="tanggal_pinjam"
                               id="tanggal_pinjam"
                               class="form-control"
                               min="<?= date('Y-m-d'); ?>"
                               value="<?= admPinjamEscape(
                                   $edit_peminjaman['tanggal_pinjam'] ?? ''
                               ); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label for="tanggal_rencana_kembali"
                               class="form-label">
                            Rencana Pengembalian
                        </label>

                        <input type="date"
                               name="tanggal_rencana_kembali"
                               id="tanggal_rencana_kembali"
                               class="form-control"
                               min="<?= date('Y-m-d'); ?>"
                               value="<?= admPinjamEscape(
                                   $edit_peminjaman['tanggal_rencana_kembali'] ?? ''
                               ); ?>"
                               required>
                    </div>

                    <div class="col-12">
                        <label for="catatan" class="form-label">
                            Catatan
                        </label>

                        <textarea name="catatan"
                                  id="catatan"
                                  class="form-control"
                                  rows="2"
                                  placeholder="Catatan tambahan (opsional)"><?= admPinjamEscape(
                                      $edit_peminjaman['catatan'] ?? ''
                                  ); ?></textarea>
                    </div>

                    <?php if (!$edit_peminjaman): ?>

                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Pilih Alat
                            </label>

                            <p class="text-secondary small">
                                Isi jumlah alat yang akan diajukan.
                                Stok baru akan dikurangi ketika petugas menyetujui.
                            </p>

                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Nama Alat</th>
                                            <th>Kategori</th>
                                            <th>Stok</th>
                                            <th style="min-width: 130px;">
                                                Jumlah
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php if (mysqli_num_rows($hasil_alat) > 0): ?>

                                            <?php while ($alat = mysqli_fetch_assoc($hasil_alat)): ?>
                                                <tr>
                                                    <td>
                                                        <?= admPinjamEscape($alat['nama_alat']); ?>
                                                    </td>

                                                    <td>
                                                        <?= admPinjamEscape(
                                                            $alat['nama_kategori'] ?? '-'
                                                        ); ?>
                                                    </td>

                                                    <td>
                                                        <?= (int) $alat['stok']; ?>
                                                    </td>

                                                    <td>
                                                        <input
                                                            type="number"
                                                            class="form-control"
                                                            name="jumlah[<?= (int) $alat['id_alat']; ?>]"
                                                            min="0"
                                                            max="<?= (int) $alat['stok']; ?>"
                                                            value="0"
                                                            step="1"
                                                            aria-label="Jumlah <?= admPinjamEscape($alat['nama_alat']); ?>">
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>

                                        <?php else: ?>

                                            <tr>
                                                <td colspan="4"
                                                    class="text-center text-secondary">
                                                    Belum ada alat yang tersedia.
                                                </td>
                                            </tr>

                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php else: ?>

                        <div class="col-12">
                            <div class="alert alert-info mb-0">
                                Pada mode edit, barang yang diajukan tetap.
                                Kamu dapat mengubah tanggal dan catatan selama
                                status masih menunggu persetujuan.
                            </div>
                        </div>

                    <?php endif; ?>

                    <div class="col-12">
                        <button type="submit"
                                class="btn btn-success">
                            <?= $edit_peminjaman
                                ? 'Simpan Perubahan'
                                : 'Tambah Peminjaman'; ?>
                        </button>

                        <?php if ($edit_peminjaman): ?>
                            <a href="peminjaman.php"
                               class="btn btn-outline-secondary ms-1">
                                Batal
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <!-- TABEL DATA PEMINJAMAN -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Daftar Peminjaman</h5>
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
                            $warna_status = [
                                'menunggu' => 'warning',
                                'disetujui' => 'success',
                                'ditolak' => 'danger',
                                'selesai' => 'secondary'
                            ];

                            $status = $data['status'];
                            $warna = $warna_status[$status] ?? 'secondary';
                            ?>

                            <tr>
                                <td class="ps-3">
                                    <strong>
                                        #<?= $data['id_peminjaman']; ?>
                                    </strong>

                                    <div>
                                        <?= admPinjamEscape($data['nama_peminjam']); ?>
                                    </div>

                                    <div class="small text-secondary">
                                        Diajukan:
                                        <?= admPinjamEscape($data['tanggal_pengajuan']); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (count($data['barang']) > 0): ?>
                                        <ul class="mb-0 ps-3">
                                            <?php foreach ($data['barang'] as $barang): ?>
                                                <li>
                                                    <?= admPinjamEscape($barang['nama_alat']); ?>
                                                    (<?= $barang['jumlah']; ?> unit)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= admPinjamEscape($data['tanggal_pinjam']); ?>
                                </td>

                                <td>
                                    <?= admPinjamEscape(
                                        $data['tanggal_rencana_kembali']
                                    ); ?>
                                </td>

                                <td>
                                    <span class="badge text-bg-<?= $warna; ?>">
                                        <?= ucfirst(admPinjamEscape($status)); ?>
                                    </span>
                                </td>

                                <td class="text-center">
                                    <div class="d-flex justify-content-center flex-wrap gap-1">

                                        <?php if ($status === 'menunggu'): ?>

                                            <a href="peminjaman.php?edit=<?= $data['id_peminjaman']; ?>"
                                               class="btn btn-sm btn-warning">
                                                Edit
                                            </a>

                                        <?php endif; ?>

                                        <?php if (in_array($status, ['menunggu', 'ditolak'], true)): ?>

                                            <form action="peminjaman.php"
                                                  method="POST"
                                                  onsubmit="return confirm('Yakin ingin menghapus data peminjaman ini?');">

                                                <input type="hidden"
                                                       name="csrf_token"
                                                       value="<?= admPinjamEscape($_SESSION['csrf_token']); ?>">

                                                <input type="hidden"
                                                       name="aksi"
                                                       value="hapus">

                                                <input type="hidden"
                                                       name="id_peminjaman"
                                                       value="<?= $data['id_peminjaman']; ?>">

                                                <button type="submit"
                                                        class="btn btn-sm btn-danger">
                                                    Hapus
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <span class="small text-secondary">
                                                Riwayat aktif
                                            </span>

                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6"
                                class="text-center text-secondary py-5">
                                Belum ada data peminjaman.
                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

    <p class="text-secondary small mt-3">
        Maksimal 100 transaksi terbaru ditampilkan.
    </p>

</main>

<script>
const tanggalPinjam = document.getElementById('tanggal_pinjam');
const tanggalKembali = document.getElementById('tanggal_rencana_kembali');

if (tanggalPinjam && tanggalKembali) {
    function aturBatasTanggal() {
        tanggalKembali.min = tanggalPinjam.value || '<?= date('Y-m-d'); ?>';

        if (
            tanggalKembali.value &&
            tanggalKembali.value < tanggalKembali.min
        ) {
            tanggalKembali.value = tanggalKembali.min;
        }
    }

    tanggalPinjam.addEventListener('change', aturBatasTanggal);
    aturBatasTanggal();
}
</script>

<?php include '../template/footer.php'; ?>
