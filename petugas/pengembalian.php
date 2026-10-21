<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
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

// Contoh denda: Rp10.000 per hari keterlambatan.
// Sesuaikan nilainya dengan ketentuan yang kamu gunakan.
const DENDA_PER_HARI = 10000;

function pengembalianEscape($nilai)
{
    return htmlspecialchars(
        (string) $nilai,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Membuat token keamanan untuk form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Memproses pengembalian alat
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

        $id_detail = filter_var(
            $_POST['id_detail'] ?? '',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $kondisi_kembali = $_POST['kondisi_kembali'] ?? '';
        $catatan = trim($_POST['catatan'] ?? '');

        if (
            $id_detail === false ||
            !in_array(
                $kondisi_kembali,
                ['baik', 'rusak ringan', 'rusak berat'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Data pengembalian tidak valid.'
            );
        }

        mysqli_begin_transaction($koneksi);
        $transaksi_dimulai = true;

        // Mengunci data rincian dan transaksi selama proses
        $query = "SELECT
                    d.id_detail,
                    d.id_peminjaman,
                    d.id_alat,
                    d.jumlah,
                    p.status,
                    p.tanggal_rencana_kembali,
                    a.nama_alat,
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
                'Data peminjaman tidak ditemukan.'
            );
        }

        if ($data['status'] !== 'disetujui') {
            throw new InvalidArgumentException(
                'Peminjaman ini tidak dalam status aktif.'
            );
        }

        if ($data['id_pengembalian'] !== null) {
            throw new InvalidArgumentException(
                'Alat ini sudah tercatat dikembalikan.'
            );
        }

        // Tanggal pengembalian menggunakan tanggal sistem
        $tanggal_pengembalian = date('Y-m-d');

        $tanggal_rencana = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $data['tanggal_rencana_kembali']
        );

        $tanggal_aktual = new DateTimeImmutable('today');

        if ($tanggal_rencana === false) {
            throw new RuntimeException(
                'Tanggal rencana pengembalian tidak valid.'
            );
        }

        // Menghitung jumlah hari keterlambatan
        $selisih_hari = (int) $tanggal_rencana
            ->diff($tanggal_aktual)
            ->format('%r%a');

        $hari_terlambat = max(0, $selisih_hari);

        $denda = $hari_terlambat * DENDA_PER_HARI;

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
            $tanggal_pengembalian,
            $kondisi_kembali,
            $denda,
            $catatan
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Stok bertambah kembali hanya jika alat dalam kondisi baik.
        // Alat rusak tidak otomatis dianggap tersedia.
        if ($kondisi_kembali === 'baik') {
            $id_alat = (int) $data['id_alat'];
            $jumlah = (int) $data['jumlah'];

            $query = "UPDATE alat
                      SET stok = stok + ?
                      WHERE id_alat = ?";

            $stmt = mysqli_prepare($koneksi, $query);

            mysqli_stmt_bind_param(
                $stmt,
                "ii",
                $jumlah,
                $id_alat
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        // Periksa apakah masih ada barang yang belum dikembalikan
        $id_peminjaman = (int) $data['id_peminjaman'];

        $query = "SELECT COUNT(*) AS sisa
                  FROM detail_peminjaman d
                  LEFT JOIN pengembalian r
                    ON d.id_detail = r.id_detail
                  WHERE d.id_peminjaman = ?
                    AND r.id_pengembalian IS NULL";

        $stmt = mysqli_prepare($koneksi, $query);
        mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
        mysqli_stmt_execute($stmt);

        $hasil_sisa = mysqli_stmt_get_result($stmt);
        $data_sisa = mysqli_fetch_assoc($hasil_sisa);

        mysqli_stmt_close($stmt);

        // Tandai transaksi selesai jika semua rincian sudah kembali
        if ((int) $data_sisa['sisa'] === 0) {
            $query = "UPDATE peminjaman
                      SET status = 'selesai'
                      WHERE id_peminjaman = ?
                        AND status = 'disetujui'";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_peminjaman);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        // Catat aktivitas petugas
        $id_petugas = (int) $_SESSION['user_id'];

        $aktivitas = "Mencatat pengembalian alat: "
            . $data['nama_alat']
            . " pada peminjaman #"
            . $id_peminjaman;

        $query = "INSERT INTO log_aktivitas (id_user, aktivitas)
                  VALUES (?, ?)";

        $stmt = mysqli_prepare($koneksi, $query);

        mysqli_stmt_bind_param(
            $stmt,
            "is",
            $id_petugas,
            $aktivitas
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Simpan seluruh perubahan
        mysqli_commit($koneksi);
        $transaksi_dimulai = false;

        $pesan_sukses = 'Pengembalian alat berhasil dicatat.';

        if ($hari_terlambat > 0) {
            $pesan_sukses .= ' Terlambat '
                . $hari_terlambat
                . ' hari dengan denda Rp'
                . number_format($denda, 0, ',', '.')
                . '.';
        } else {
            $pesan_sukses .= ' Tidak ada denda keterlambatan.';
        }

        if ($kondisi_kembali !== 'baik') {
            $pesan_sukses .=
                ' Stok alat tidak ditambahkan kembali karena kondisinya rusak.';
        }

        $_SESSION['pesan'] = $pesan_sukses;
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

        error_log(
            'Proses pengembalian SummitGear: ' . $e->getMessage()
        );

        $_SESSION['pesan'] =
            'Pengembalian gagal diproses. Tidak ada perubahan yang disimpan.';

        $_SESSION['jenis_pesan'] = 'danger';
    }

    header("Location: pengembalian.php");
    exit;
}

// Mengambil barang yang masih menunggu pengembalian
$query = "SELECT
            d.id_detail,
            d.id_peminjaman,
            d.jumlah,
            p.tanggal_pinjam,
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
          ORDER BY p.tanggal_rencana_kembali ASC,
                   p.id_peminjaman DESC
          LIMIT 100";

$hasil_belum_kembali = mysqli_query($koneksi, $query);

// Mengambil riwayat pengembalian terakhir
$query_riwayat = "SELECT
                    r.tanggal_pengembalian,
                    r.kondisi_kembali,
                    r.denda,
                    r.catatan,
                    p.id_peminjaman,
                    u.nama AS nama_peminjam,
                    a.nama_alat,
                    d.jumlah
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
                  LIMIT 50";

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
                Catat alat yang kembali dan periksa kondisinya.
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

            <?= pengembalianEscape($pesan); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- BARANG YANG BELUM DIKEMBALIKAN -->
    <div class="card border-0 shadow-sm mb-5">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Menunggu Pengembalian</h5>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">

                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Peminjam</th>
                        <th>Barang</th>
                        <th>Jumlah</th>
                        <th>Rencana Kembali</th>
                        <th>Proses Pengembalian</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (mysqli_num_rows($hasil_belum_kembali) > 0): ?>

                        <?php while (
                            $barang = mysqli_fetch_assoc($hasil_belum_kembali)
                        ): ?>

                            <?php
                            $tanggal_rencana = new DateTimeImmutable(
                                $barang['tanggal_rencana_kembali']
                            );

                            $hari_ini = new DateTimeImmutable('today');

                            $selisih = (int) $tanggal_rencana
                                ->diff($hari_ini)
                                ->format('%r%a');

                            $terlambat = max(0, $selisih);
                            ?>

                            <tr>
                                <td class="ps-3">
                                    <strong>
                                        <?= pengembalianEscape($barang['nama_peminjam']); ?>
                                    </strong>

                                    <div class="small text-secondary">
                                        Peminjaman #<?= (int) $barang['id_peminjaman']; ?>
                                    </div>
                                </td>

                                <td>
                                    <?= pengembalianEscape($barang['nama_alat']); ?>
                                </td>

                                <td>
                                    <?= (int) $barang['jumlah']; ?> unit
                                </td>

                                <td>
                                    <?= pengembalianEscape(
                                        $barang['tanggal_rencana_kembali']
                                    ); ?>

                                    <?php if ($terlambat > 0): ?>
                                        <div class="small text-danger mt-1">
                                            Terlambat <?= $terlambat; ?> hari
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="min-width: 260px;">
                                    <form action="pengembalian.php"
                                          method="POST">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= pengembalianEscape($_SESSION['csrf_token']); ?>">

                                        <input type="hidden"
                                               name="id_detail"
                                               value="<?= (int) $barang['id_detail']; ?>">

                                        <div class="mb-2">
                                            <label class="form-label small">
                                                Kondisi Saat Kembali
                                            </label>

                                            <select name="kondisi_kembali"
                                                    class="form-select form-select-sm"
                                                    required>

                                                <option value="baik">
                                                    Baik
                                                </option>

                                                <option value="rusak ringan">
                                                    Rusak ringan
                                                </option>

                                                <option value="rusak berat">
                                                    Rusak berat
                                                </option>

                                            </select>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label small">
                                                Catatan
                                            </label>

                                            <input type="text"
                                                   name="catatan"
                                                   class="form-control form-control-sm"
                                                   placeholder="Opsional">
                                        </div>

                                        <button type="submit"
                                                class="btn btn-sm btn-success w-100"
                                                onclick="return confirm('Catat pengembalian barang ini?');">

                                            Catat Pengembalian

                                        </button>

                                    </form>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5"
                                class="text-center text-secondary py-5">

                                Belum ada alat yang menunggu pengembalian.

                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

    <!-- RIWAYAT PENGEMBALIAN -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Riwayat Pengembalian</h5>
        </div>

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
                    <?php if (mysqli_num_rows($hasil_riwayat) > 0): ?>

                        <?php while (
                            $riwayat = mysqli_fetch_assoc($hasil_riwayat)
                        ): ?>

                            <?php
                            $warna_kondisi = [
                                'baik' => 'success',
                                'rusak ringan' => 'warning',
                                'rusak berat' => 'danger'
                            ];

                            $warna = $warna_kondisi[
                                $riwayat['kondisi_kembali']
                            ] ?? 'secondary';
                            ?>

                            <tr>
                                <td class="ps-3">
                                    <?= pengembalianEscape(
                                        $riwayat['tanggal_pengembalian']
                                    ); ?>
                                </td>

                                <td>
                                    <?= pengembalianEscape(
                                        $riwayat['nama_peminjam']
                                    ); ?>
                                </td>

                                <td>
                                    <?= pengembalianEscape(
                                        $riwayat['nama_alat']
                                    ); ?>

                                    <div class="small text-secondary">
                                        <?= (int) $riwayat['jumlah']; ?> unit
                                    </div>
                                </td>

                                <td>
                                    <span class="badge text-bg-<?= $warna; ?>">
                                        <?= ucfirst(
                                            pengembalianEscape(
                                                $riwayat['kondisi_kembali']
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    Rp<?= number_format(
                                        (float) $riwayat['denda'],
                                        0,
                                        ',',
                                        '.'
                                    ); ?>
                                </td>

                                <td>
                                    <?= $riwayat['catatan'] !== ''
                                        ? pengembalianEscape($riwayat['catatan'])
                                        : '-'; ?>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6"
                                class="text-center text-secondary py-4">

                                Belum ada riwayat pengembalian.

                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

    <p class="small text-secondary mt-3">
        Riwayat menampilkan maksimal 50 pengembalian terakhir.
    </p>

</main>

<?php include '../template/footer.php'; ?>
