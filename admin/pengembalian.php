<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
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

const ADMIN_DENDA_PER_HARI = 10000;

function adminKembaliEscape($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

function adminKembaliTanggal($nilai)
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

function adminKembaliHitungDenda($tanggal_rencana, $tanggal_aktual)
{
    $selisih = (int) $tanggal_rencana
        ->diff($tanggal_aktual)
        ->format('%r%a');

    $hari_terlambat = max(0, $selisih);

    return $hari_terlambat * ADMIN_DENDA_PER_HARI;
}

function adminKembaliLog($koneksi, $id_user, $aktivitas)
{
    $query = "INSERT INTO log_aktivitas (id_user, aktivitas)
              VALUES (?, ?)";

    $stmt = mysqli_prepare($koneksi, $query);
    mysqli_stmt_bind_param($stmt, "is", $id_user, $aktivitas);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$daftar_kondisi = ['baik', 'rusak ringan', 'rusak berat'];

// Proses CRUD pengembalian
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

        // TAMBAH PENGEMBALIAN
        if ($aksi === 'tambah') {
            $id_detail = filter_var(
                $_POST['id_detail'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $tanggal_input = trim(
                $_POST['tanggal_pengembalian'] ?? ''
            );

            $kondisi = $_POST['kondisi_kembali'] ?? '';
            $catatan = trim($_POST['catatan'] ?? '');

            $tanggal_aktual = adminKembaliTanggal($tanggal_input);

            if (
                $id_detail === false ||
                !$tanggal_aktual ||
                !in_array($kondisi, $daftar_kondisi, true)
            ) {
                throw new InvalidArgumentException(
                    'Data pengembalian tidak valid.'
                );
            }

            if ($tanggal_aktual > new DateTimeImmutable('today')) {
                throw new InvalidArgumentException(
                    'Tanggal pengembalian tidak boleh di masa depan.'
                );
            }

            mysqli_begin_transaction($koneksi);
            $transaksi_dimulai = true;

            // Kunci rincian peminjaman dan periksa statusnya
            $query = "SELECT
                        d.id_detail,
                        d.id_peminjaman,
                        d.id_alat,
                        d.jumlah,
                        p.status,
                        p.tanggal_rencana_kembali,
                        a.nama_alat,
                        a.stok,
                        r.id_pengembalian
                      FROM detail_peminjaman d
                      INNER JOIN peminjaman p
                        ON d.id_peminjaman = p.id_peminjaman
                      INNER JOIN alat a
                        ON d.id_alat = a.id_alat
                      LEFT JOIN pengembalian r
                        ON d.id_detail = r.id_detail
                      WHERE d.id_detail = ?
                      FOR UPDATE";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_detail);
            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $data = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($stmt);

            if (!$data) {
                throw new InvalidArgumentException(
                    'Rincian peminjaman tidak ditemukan.'
                );
            }

            if ($data['status'] !== 'disetujui') {
                throw new InvalidArgumentException(
                    'Hanya peminjaman yang disetujui yang dapat dikembalikan.'
                );
            }

            if ($data['id_pengembalian'] !== null) {
                throw new InvalidArgumentException(
                    'Pengembalian untuk barang ini sudah tercatat.'
                );
            }

            $tanggal_rencana = adminKembaliTanggal(
                $data['tanggal_rencana_kembali']
            );

            if (!$tanggal_rencana) {
                throw new RuntimeException(
                    'Tanggal rencana pengembalian tidak valid.'
                );
            }

            $denda = adminKembaliHitungDenda(
                $tanggal_rencana,
                $tanggal_aktual
            );

            // Simpan data pengembalian
            $query = "INSERT INTO pengembalian
                        (
                            id_detail,
                            tanggal_pengembalian,
                            kondisi_kembali,
                            denda,
                            catatan
                        )
                      VALUES (?, ?, ?, ?, ?)";

            $stmt = mysqli_prepare($koneksi, $query);

            mysqli_stmt_bind_param(
                $stmt,
                "issds",
                $id_detail,
                $tanggal_input,
                $kondisi,
                $denda,
                $catatan
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Stok dikembalikan hanya jika kondisinya baik,
            // sesuai logika halaman pengembalian petugas.
            if ($kondisi === 'baik') {
                $id_alat = (int) $data['id_alat'];
                $jumlah = (int) $data['jumlah'];

                $stmt = mysqli_prepare(
                    $koneksi,
                    "UPDATE alat SET stok = stok + ? WHERE id_alat = ?"
                );

                mysqli_stmt_bind_param($stmt, "ii", $jumlah, $id_alat);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            // Periksa apakah seluruh barang telah kembali
            $id_peminjaman = (int) $data['id_peminjaman'];

            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT COUNT(*) AS sisa
                 FROM detail_peminjaman d
                 LEFT JOIN pengembalian r
                    ON d.id_detail = r.id_detail
                 WHERE d.id_peminjaman = ?
                   AND r.id_pengembalian IS NULL"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);

            $hasil_sisa = mysqli_stmt_get_result($stmt);
            $data_sisa = mysqli_fetch_assoc($hasil_sisa);

            mysqli_stmt_close($stmt);

            if ((int) $data_sisa['sisa'] === 0) {
                $stmt = mysqli_prepare(
                    $koneksi,
                    "UPDATE peminjaman
                     SET status = 'selesai'
                     WHERE id_peminjaman = ?
                       AND status = 'disetujui'"
                );

                mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            adminKembaliLog(
                $koneksi,
                $id_admin,
                "Admin mencatat pengembalian alat: "
                . $data['nama_alat']
                . " pada peminjaman #"
                . $id_peminjaman
            );

            mysqli_commit($koneksi);
            $transaksi_dimulai = false;

            $_SESSION['pesan'] =
                'Pengembalian berhasil ditambahkan. Denda: Rp'
                . number_format($denda, 0, ',', '.');

            $_SESSION['jenis_pesan'] = 'success';

        // EDIT PENGEMBALIAN
        } elseif ($aksi === 'edit') {
            $id_pengembalian = filter_var(
                $_POST['id_pengembalian'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $tanggal_input = trim(
                $_POST['tanggal_pengembalian'] ?? ''
            );

            $kondisi_baru = $_POST['kondisi_kembali'] ?? '';
            $catatan = trim($_POST['catatan'] ?? '');

            $tanggal_aktual = adminKembaliTanggal($tanggal_input);

            if (
                $id_pengembalian === false ||
                !$tanggal_aktual ||
                !in_array($kondisi_baru, $daftar_kondisi, true)
            ) {
                throw new InvalidArgumentException(
                    'Data pengembalian tidak valid.'
                );
            }

            if ($tanggal_aktual > new DateTimeImmutable('today')) {
                throw new InvalidArgumentException(
                    'Tanggal pengembalian tidak boleh di masa depan.'
                );
            }

            mysqli_begin_transaction($koneksi);
            $transaksi_dimulai = true;

            $query = "SELECT
                        r.id_pengembalian,
                        r.id_detail,
                        r.tanggal_pengembalian,
                        r.kondisi_kembali,
                        d.id_alat,
                        d.id_peminjaman,
                        d.jumlah,
                        p.tanggal_rencana_kembali,
                        a.nama_alat,
                        a.stok
                      FROM pengembalian r
                      INNER JOIN detail_peminjaman d
                        ON r.id_detail = d.id_detail
                      INNER JOIN peminjaman p
                        ON d.id_peminjaman = p.id_peminjaman
                      INNER JOIN alat a
                        ON d.id_alat = a.id_alat
                      WHERE r.id_pengembalian = ?
                      FOR UPDATE";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_pengembalian);
            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $data = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($stmt);

            if (!$data) {
                throw new InvalidArgumentException(
                    'Data pengembalian tidak ditemukan.'
                );
            }

            $kondisi_lama = $data['kondisi_kembali'];
            $id_alat = (int) $data['id_alat'];
            $jumlah = (int) $data['jumlah'];

            // Jika kondisi berubah, sesuaikan stok.
            if (
                $kondisi_lama === 'baik' &&
                $kondisi_baru !== 'baik'
            ) {
                $stmt = mysqli_prepare(
                    $koneksi,
                    "UPDATE alat
                     SET stok = stok - ?
                     WHERE id_alat = ?
                       AND stok >= ?"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "iii",
                    $jumlah,
                    $id_alat,
                    $jumlah
                );

                mysqli_stmt_execute($stmt);
                $berhasil = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($berhasil !== 1) {
                    throw new InvalidArgumentException(
                        'Stok tidak mencukupi untuk mengubah kondisi pengembalian.'
                    );
                }

            } elseif (
                $kondisi_lama !== 'baik' &&
                $kondisi_baru === 'baik'
            ) {
                $stmt = mysqli_prepare(
                    $koneksi,
                    "UPDATE alat SET stok = stok + ? WHERE id_alat = ?"
                );

                mysqli_stmt_bind_param($stmt, "ii", $jumlah, $id_alat);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $tanggal_rencana = adminKembaliTanggal(
                $data['tanggal_rencana_kembali']
            );

            if (!$tanggal_rencana) {
                throw new RuntimeException(
                    'Tanggal rencana pengembalian tidak valid.'
                );
            }

            $denda = adminKembaliHitungDenda(
                $tanggal_rencana,
                $tanggal_aktual
            );

            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE pengembalian
                 SET tanggal_pengembalian = ?,
                     kondisi_kembali = ?,
                     denda = ?,
                     catatan = ?
                 WHERE id_pengembalian = ?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ssdsi",
                $tanggal_input,
                $kondisi_baru,
                $denda,
                $catatan,
                $id_pengembalian
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            adminKembaliLog(
                $koneksi,
                $id_admin,
                "Admin mengubah data pengembalian #"
                . $id_pengembalian
            );

            mysqli_commit($koneksi);
            $transaksi_dimulai = false;

            $_SESSION['pesan'] =
                'Data pengembalian berhasil diperbarui. Denda: Rp'
                . number_format($denda, 0, ',', '.');

            $_SESSION['jenis_pesan'] = 'success';

        // HAPUS PENGEMBALIAN
        } elseif ($aksi === 'hapus') {
            $id_pengembalian = filter_var(
                $_POST['id_pengembalian'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($id_pengembalian === false) {
                throw new InvalidArgumentException(
                    'ID pengembalian tidak valid.'
                );
            }

            mysqli_begin_transaction($koneksi);
            $transaksi_dimulai = true;

            $query = "SELECT
                        r.id_pengembalian,
                        r.kondisi_kembali,
                        d.id_detail,
                        d.id_peminjaman,
                        d.id_alat,
                        d.jumlah,
                        a.nama_alat
                      FROM pengembalian r
                      INNER JOIN detail_peminjaman d
                        ON r.id_detail = d.id_detail
                      INNER JOIN alat a
                        ON d.id_alat = a.id_alat
                      WHERE r.id_pengembalian = ?
                      FOR UPDATE";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_pengembalian);
            mysqli_stmt_execute($stmt);

            $hasil = mysqli_stmt_get_result($stmt);
            $data = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($stmt);

            if (!$data) {
                throw new InvalidArgumentException(
                    'Data pengembalian tidak ditemukan.'
                );
            }

            // Batalkan penambahan stok sebelumnya apabila kondisi baik.
            if ($data['kondisi_kembali'] === 'baik') {
                $id_alat = (int) $data['id_alat'];
                $jumlah = (int) $data['jumlah'];

                $stmt = mysqli_prepare(
                    $koneksi,
                    "UPDATE alat
                     SET stok = stok - ?
                     WHERE id_alat = ?
                       AND stok >= ?"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    "iii",
                    $jumlah,
                    $id_alat,
                    $jumlah
                );

                mysqli_stmt_execute($stmt);
                $berhasil = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($berhasil !== 1) {
                    throw new InvalidArgumentException(
                        'Stok tidak mencukupi untuk membatalkan pengembalian. Periksa transaksi lain terlebih dahulu.'
                    );
                }
            }

            // Hapus catatan pengembalian
            $stmt = mysqli_prepare(
                $koneksi,
                "DELETE FROM pengembalian
                 WHERE id_pengembalian = ?"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_pengembalian);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Transaksi kembali aktif karena barang perlu dikembalikan lagi.
            $id_peminjaman = (int) $data['id_peminjaman'];

            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE peminjaman
                 SET status = 'disetujui'
                 WHERE id_peminjaman = ?"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            adminKembaliLog(
                $koneksi,
                $id_admin,
                "Admin menghapus catatan pengembalian alat: "
                . $data['nama_alat']
                . " pada peminjaman #"
                . $id_peminjaman
            );

            mysqli_commit($koneksi);
            $transaksi_dimulai = false;

            $_SESSION['pesan'] =
                'Catatan pengembalian dihapus dan transaksi kembali aktif.';

            $_SESSION['jenis_pesan'] = 'success';

        } else {
            throw new InvalidArgumentException(
                'Aksi pengembalian tidak valid.'
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

        error_log('Kelola pengembalian SummitGear: ' . $e->getMessage());

        $_SESSION['pesan'] =
            'Data gagal diproses. Periksa kembali database dan relasinya.';

        $_SESSION['jenis_pesan'] = 'danger';
    }

    header("Location: pengembalian.php");
    exit;
}

// Ambil data yang akan diedit
$edit_pengembalian = null;

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
                r.id_pengembalian,
                r.id_detail,
                r.tanggal_pengembalian,
                r.kondisi_kembali,
                r.denda,
                r.catatan,
                d.id_peminjaman,
                d.jumlah,
                p.tanggal_rencana_kembali,
                u.nama AS nama_peminjam,
                a.nama_alat
             FROM pengembalian r
             INNER JOIN detail_peminjaman d
                ON r.id_detail = d.id_detail
             INNER JOIN peminjaman p
                ON d.id_peminjaman = p.id_peminjaman
             INNER JOIN users u
                ON p.id_user = u.id_user
             INNER JOIN alat a
                ON d.id_alat = a.id_alat
             WHERE r.id_pengembalian = ?"
        );

        mysqli_stmt_bind_param($stmt, "i", $id_edit);
        mysqli_stmt_execute($stmt);

        $hasil_edit = mysqli_stmt_get_result($stmt);
        $edit_pengembalian = mysqli_fetch_assoc($hasil_edit);

        mysqli_stmt_close($stmt);

        if (!$edit_pengembalian) {
            $_SESSION['pesan'] = 'Data pengembalian tidak ditemukan.';
            $_SESSION['jenis_pesan'] = 'warning';

            header("Location: pengembalian.php");
            exit;
        }
    }
}

// Rincian yang masih menunggu pengembalian
$query_detail = "SELECT
                    d.id_detail,
                    d.id_peminjaman,
                    d.jumlah,
                    p.tanggal_rencana_kembali,
                    u.nama AS nama_peminjam,
                    a.nama_alat
                 FROM detail_peminjaman d
                 INNER JOIN peminjaman p
                    ON d.id_peminjaman = p.id_peminjaman
                 INNER JOIN users u
                    ON p.id_user = u.id_user
                 INNER JOIN alat a
                    ON d.id_alat = a.id_alat
                 LEFT JOIN pengembalian r
                    ON d.id_detail = r.id_detail
                 WHERE p.status = 'disetujui'
                   AND r.id_pengembalian IS NULL
                 ORDER BY p.tanggal_rencana_kembali ASC
                 LIMIT 100";

$hasil_detail = mysqli_query($koneksi, $query_detail);

// Daftar pengembalian terbaru
$query_riwayat = "SELECT
                    r.id_pengembalian,
                    r.id_detail,
                    r.tanggal_pengembalian,
                    r.kondisi_kembali,
                    r.denda,
                    r.catatan,
                    d.id_peminjaman,
                    d.jumlah,
                    u.nama AS nama_peminjam,
                    a.nama_alat
                  FROM pengembalian r
                  INNER JOIN detail_peminjaman d
                    ON r.id_detail = d.id_detail
                  INNER JOIN peminjaman p
                    ON d.id_peminjaman = p.id_peminjaman
                  INNER JOIN users u
                    ON p.id_user = u.id_user
                  INNER JOIN alat a
                    ON d.id_alat = a.id_alat
                  ORDER BY r.id_pengembalian DESC
                  LIMIT 100";

$hasil_riwayat = mysqli_query($koneksi, $query_riwayat);

$pesan = $_SESSION['pesan'] ?? '';
$jenis_pesan = $_SESSION['jenis_pesan'] ?? 'success';

unset($_SESSION['pesan'], $_SESSION['jenis_pesan']);

$judul_halaman = "Kelola Pengembalian";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-2 mb-4">

        <div>
            <h2 class="fw-bold">Kelola Pengembalian</h2>

            <p class="text-secondary mb-0">
                Kelola catatan pengembalian perlengkapan SummitGear.
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
            <?= adminKembaliEscape($pesan); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM TAMBAH ATAU EDIT -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">
                <?= $edit_pengembalian
                    ? 'Edit Pengembalian'
                    : 'Tambah Pengembalian'; ?>
            </h5>
        </div>

        <div class="card-body p-4">

            <form action="pengembalian.php" method="POST">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= adminKembaliEscape($_SESSION['csrf_token']); ?>">

                <input type="hidden"
                       name="aksi"
                       value="<?= $edit_pengembalian ? 'edit' : 'tambah'; ?>">

                <?php if ($edit_pengembalian): ?>

                    <input type="hidden"
                           name="id_pengembalian"
                           value="<?= (int) $edit_pengembalian['id_pengembalian']; ?>">

                    <div class="alert alert-info">
                        <strong>Peminjam:</strong>
                        <?= adminKembaliEscape($edit_pengembalian['nama_peminjam']); ?>
                        <br>

                        <strong>Barang:</strong>
                        <?= adminKembaliEscape($edit_pengembalian['nama_alat']); ?>
                        (<?= (int) $edit_pengembalian['jumlah']; ?> unit)
                        <br>

                        <strong>ID Peminjaman:</strong>
                        #<?= (int) $edit_pengembalian['id_peminjaman']; ?>
                    </div>

                <?php else: ?>

                    <div class="mb-3">
                        <label for="id_detail" class="form-label">
                            Barang yang Dikembalikan
                        </label>

                        <select name="id_detail"
                                id="id_detail"
                                class="form-select"
                                required>

                            <option value="">Pilih barang</option>

                            <?php while ($detail = mysqli_fetch_assoc($hasil_detail)): ?>

                                <option value="<?= (int) $detail['id_detail']; ?>">
                                    Peminjaman #<?= (int) $detail['id_peminjaman']; ?>
                                    —
                                    <?= adminKembaliEscape($detail['nama_peminjam']); ?>
                                    —
                                    <?= adminKembaliEscape($detail['nama_alat']); ?>
                                    (<?= (int) $detail['jumlah']; ?> unit)
                                </option>

                            <?php endwhile; ?>

                        </select>
                    </div>

                <?php endif; ?>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="tanggal_pengembalian"
                               class="form-label">
                            Tanggal Pengembalian
                        </label>

                        <input type="date"
                               class="form-control"
                               name="tanggal_pengembalian"
                               id="tanggal_pengembalian"
                               max="<?= date('Y-m-d'); ?>"
                               value="<?= adminKembaliEscape(
                                   $edit_pengembalian['tanggal_pengembalian']
                                   ?? date('Y-m-d')
                               ); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label for="kondisi_kembali"
                               class="form-label">
                            Kondisi Saat Dikembalikan
                        </label>

                        <select class="form-select"
                                name="kondisi_kembali"
                                id="kondisi_kembali"
                                required>

                            <?php foreach ($daftar_kondisi as $kondisi): ?>

                                <option value="<?= adminKembaliEscape($kondisi); ?>"
                                    <?= (
                                        ($edit_pengembalian['kondisi_kembali'] ?? 'baik')
                                        === $kondisi
                                    ) ? 'selected' : ''; ?>>

                                    <?= ucfirst(adminKembaliEscape($kondisi)); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12">
                        <label for="catatan" class="form-label">
                            Catatan
                        </label>

                        <textarea class="form-control"
                                  name="catatan"
                                  id="catatan"
                                  rows="3"
                                  placeholder="Catatan tambahan (opsional)"><?= adminKembaliEscape(
                                      $edit_pengembalian['catatan'] ?? ''
                                  ); ?></textarea>
                    </div>

                </div>

                <div class="alert alert-light border mt-3 mb-3">
                    Denda dihitung otomatis sebesar
                    Rp<?= number_format(
                        ADMIN_DENDA_PER_HARI,
                        0,
                        ',',
                        '.'
                    ); ?>
                    per hari keterlambatan, berdasarkan tanggal rencana
                    pengembalian dan tanggal aktual.
                    <?php if ($edit_pengembalian): ?>
                        Denda saat ini:
                        <strong>
                            Rp<?= number_format(
                                (float) $edit_pengembalian['denda'],
                                0,
                                ',',
                                '.'
                            ); ?>
                        </strong>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-success">
                    <?= $edit_pengembalian
                        ? 'Simpan Perubahan'
                        : 'Tambah Pengembalian'; ?>
                </button>

                <?php if ($edit_pengembalian): ?>
                    <a href="pengembalian.php"
                       class="btn btn-outline-secondary ms-1">
                        Batal
                    </a>
                <?php endif; ?>

            </form>

        </div>
    </div>

    <!-- DAFTAR PENGEMBALIAN -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Riwayat Pengembalian</h5>
        </div>

        <div class="table-responsive">

            <table class="table table-striped table-hover align-middle mb-0">

                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Tanggal Kembali</th>
                        <th>Peminjam</th>
                        <th>Barang</th>
                        <th>Kondisi</th>
                        <th>Denda</th>
                        <th>Catatan</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (mysqli_num_rows($hasil_riwayat) > 0): ?>

                        <?php while ($data = mysqli_fetch_assoc($hasil_riwayat)): ?>

                            <?php
                            $warna_kondisi = [
                                'baik' => 'success',
                                'rusak ringan' => 'warning',
                                'rusak berat' => 'danger'
                            ];

                            $warna = $warna_kondisi[
                                $data['kondisi_kembali']
                            ] ?? 'secondary';
                            ?>

                            <tr>
                                <td class="ps-3">
                                    #<?= (int) $data['id_pengembalian']; ?>

                                    <div class="small text-secondary">
                                        Peminjaman #<?= (int) $data['id_peminjaman']; ?>
                                    </div>
                                </td>

                                <td>
                                    <?= adminKembaliEscape(
                                        $data['tanggal_pengembalian']
                                    ); ?>
                                </td>

                                <td>
                                    <?= adminKembaliEscape($data['nama_peminjam']); ?>
                                </td>

                                <td>
                                    <?= adminKembaliEscape($data['nama_alat']); ?>

                                    <div class="small text-secondary">
                                        <?= (int) $data['jumlah']; ?> unit
                                    </div>
                                </td>

                                <td>
                                    <span class="badge text-bg-<?= $warna; ?>">
                                        <?= ucfirst(
                                            adminKembaliEscape(
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
                                    <?= adminKembaliEscape(
                                        $data['catatan'] ?: '-'
                                    ); ?>
                                </td>

                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">

                                        <a href="pengembalian.php?edit=<?= (int) $data['id_pengembalian']; ?>"
                                           class="btn btn-sm btn-warning">
                                            Edit
                                        </a>

                                        <form action="pengembalian.php"
                                              method="POST"
                                              onsubmit="return confirm('Yakin ingin menghapus catatan pengembalian ini? Transaksi akan dibuka kembali.');">

                                            <input type="hidden"
                                                   name="csrf_token"
                                                   value="<?= adminKembaliEscape($_SESSION['csrf_token']); ?>">

                                            <input type="hidden"
                                                   name="aksi"
                                                   value="hapus">

                                            <input type="hidden"
                                                   name="id_pengembalian"
                                                   value="<?= (int) $data['id_pengembalian']; ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-danger">
                                                Hapus
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8"
                                class="text-center text-secondary py-5">
                                Belum ada data pengembalian.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>
    </div>

    <p class="text-secondary small mt-3">
        Halaman menampilkan maksimal 100 catatan pengembalian terbaru.
    </p>

</main>

<?php include '../template/footer.php'; ?>
