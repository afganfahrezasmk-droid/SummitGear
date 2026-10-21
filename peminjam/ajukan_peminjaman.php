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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function sg_escape($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

// Memproses pengajuan peminjaman
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaksi_dimulai = false;

    try {
        $id_user = (int) $_SESSION['user_id'];
        $tanggal_pinjam = trim($_POST['tanggal_pinjam'] ?? '');
        $tanggal_rencana_kembali = trim(
            $_POST['tanggal_rencana_kembali'] ?? ''
        );
        $catatan = trim($_POST['catatan'] ?? '');
        $jumlah_input = $_POST['jumlah'] ?? [];

        // Validasi tanggal
        $tanggal_pinjam_obj = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $tanggal_pinjam
        );

        $tanggal_kembali_obj = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $tanggal_rencana_kembali
        );

        $error_pinjam = DateTimeImmutable::getLastErrors();

        $valid_pinjam = $tanggal_pinjam_obj !== false
            && $tanggal_pinjam_obj->format('Y-m-d') === $tanggal_pinjam
            && (
                $error_pinjam === false ||
                (
                    $error_pinjam['warning_count'] === 0 &&
                    $error_pinjam['error_count'] === 0
                )
            );

        $error_kembali = DateTimeImmutable::getLastErrors();

        $valid_kembali = $tanggal_kembali_obj !== false
            && $tanggal_kembali_obj->format('Y-m-d')
                === $tanggal_rencana_kembali
            && (
                $error_kembali === false ||
                (
                    $error_kembali['warning_count'] === 0 &&
                    $error_kembali['error_count'] === 0
                )
            );

        if (!$valid_pinjam || !$valid_kembali) {
            throw new InvalidArgumentException(
                'Tanggal peminjaman dan pengembalian wajib diisi dengan benar.'
            );
        }

        $hari_ini = new DateTimeImmutable('today');

        if ($tanggal_pinjam_obj < $hari_ini) {
            throw new InvalidArgumentException(
                'Tanggal peminjaman tidak boleh di masa lalu.'
            );
        }

        if ($tanggal_kembali_obj < $tanggal_pinjam_obj) {
            throw new InvalidArgumentException(
                'Rencana pengembalian tidak boleh sebelum tanggal peminjaman.'
            );
        }

        if (!is_array($jumlah_input)) {
            throw new InvalidArgumentException(
                'Data jumlah alat tidak valid.'
            );
        }

        // Memeriksa barang yang dipilih
        $alat_dipinjam = [];

        $query_cek = "SELECT nama_alat, stok, kondisi
                      FROM alat
                      WHERE id_alat = ?";

        $stmt_cek = mysqli_prepare($koneksi, $query_cek);

        foreach ($jumlah_input as $id_alat_input => $jumlah_raw) {
            $id_alat = filter_var(
                (string) $id_alat_input,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($id_alat === false) {
                throw new InvalidArgumentException(
                    'Data alat yang dipilih tidak valid.'
                );
            }

            $jumlah_raw = trim((string) $jumlah_raw);

            // Alat dengan jumlah 0 tidak dimasukkan ke pengajuan
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

            $jumlah = (int) $jumlah_raw;

            mysqli_stmt_bind_param($stmt_cek, "i", $id_alat);
            mysqli_stmt_execute($stmt_cek);

            $hasil_cek = mysqli_stmt_get_result($stmt_cek);
            $data_alat = mysqli_fetch_assoc($hasil_cek);

            if (!$data_alat) {
                throw new InvalidArgumentException(
                    'Salah satu alat yang dipilih tidak ditemukan.'
                );
            }

            if ($data_alat['kondisi'] !== 'baik') {
                throw new InvalidArgumentException(
                    'Alat "' . $data_alat['nama_alat'] .
                    '" tidak dapat dipinjam karena kondisinya perlu diperiksa.'
                );
            }

            if ($jumlah > (int) $data_alat['stok']) {
                throw new InvalidArgumentException(
                    'Jumlah alat "' . $data_alat['nama_alat'] .
                    '" melebihi stok yang tersedia.'
                );
            }

            $alat_dipinjam[] = [
                'id_alat' => (int) $id_alat,
                'jumlah' => $jumlah,
                'nama_alat' => $data_alat['nama_alat']
            ];
        }

        mysqli_stmt_close($stmt_cek);

        if (count($alat_dipinjam) === 0) {
            throw new InvalidArgumentException(
                'Pilih minimal satu alat yang ingin dipinjam.'
            );
        }

        // Mulai transaksi database
        mysqli_begin_transaction($koneksi);
        $transaksi_dimulai = true;

        // Simpan data utama peminjaman
        $query_pinjam = "INSERT INTO peminjaman
                            (
                                id_user,
                                tanggal_pinjam,
                                tanggal_rencana_kembali,
                                status,
                                catatan
                            )
                         VALUES (?, ?, ?, 'menunggu', ?)";

        $stmt_pinjam = mysqli_prepare($koneksi, $query_pinjam);

        mysqli_stmt_bind_param(
            $stmt_pinjam,
            "isss",
            $id_user,
            $tanggal_pinjam,
            $tanggal_rencana_kembali,
            $catatan
        );

        mysqli_stmt_execute($stmt_pinjam);

        $id_peminjaman = mysqli_insert_id($koneksi);

        mysqli_stmt_close($stmt_pinjam);

        // Simpan semua barang ke tabel detail_peminjaman
        $query_detail = "INSERT INTO detail_peminjaman
                            (id_peminjaman, id_alat, jumlah)
                         VALUES (?, ?, ?)";

        $stmt_detail = mysqli_prepare($koneksi, $query_detail);

        $id_alat_detail = 0;
        $jumlah_detail = 0;

        mysqli_stmt_bind_param(
            $stmt_detail,
            "iii",
            $id_peminjaman,
            $id_alat_detail,
            $jumlah_detail
        );

        foreach ($alat_dipinjam as $item) {
            $id_alat_detail = $item['id_alat'];
            $jumlah_detail = $item['jumlah'];

            mysqli_stmt_execute($stmt_detail);
        }

        mysqli_stmt_close($stmt_detail);

        // Catat aktivitas peminjam
        $aktivitas = "Mengajukan peminjaman #" . $id_peminjaman;

        $query_log = "INSERT INTO log_aktivitas (id_user, aktivitas)
                      VALUES (?, ?)";

        $stmt_log = mysqli_prepare($koneksi, $query_log);

        mysqli_stmt_bind_param(
            $stmt_log,
            "is",
            $id_user,
            $aktivitas
        );

        mysqli_stmt_execute($stmt_log);
        mysqli_stmt_close($stmt_log);

        // Simpan semua perubahan sekaligus
        mysqli_commit($koneksi);
        $transaksi_dimulai = false;

        $_SESSION['pesan'] =
            'Pengajuan berhasil dikirim dan menunggu persetujuan petugas.';

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

        error_log('Pengajuan SummitGear: ' . $e->getMessage());

        $_SESSION['pesan'] =
            'Pengajuan gagal diproses. Periksa data dan struktur database.';

        $_SESSION['jenis_pesan'] = 'danger';
    }

    header("Location: ajukan_peminjaman.php");
    exit;
}

// Mengambil data perlengkapan dari database
$query_alat = "SELECT
                    a.id_alat,
                    a.nama_alat,
                    a.stok,
                    a.kondisi,
                    k.nama_kategori
               FROM alat a
               LEFT JOIN kategori k
                   ON a.id_kategori = k.id_kategori
               ORDER BY k.nama_kategori ASC, a.nama_alat ASC";

$hasil_alat = mysqli_query($koneksi, $query_alat);

// Mengambil pesan hasil proses
$pesan = $_SESSION['pesan'] ?? '';
$jenis_pesan = $_SESSION['jenis_pesan'] ?? 'success';

unset($_SESSION['pesan'], $_SESSION['jenis_pesan']);

$judul_halaman = "Ajukan Peminjaman";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="mb-4">
        <a href="<?= BASE_URL; ?>/peminjam/index.php"
           class="btn btn-outline-secondary btn-sm mb-3">
            Kembali ke Dashboard
        </a>

        <h2 class="fw-bold">Ajukan Peminjaman</h2>

        <p class="text-secondary">
            Pilih perlengkapan yang kamu butuhkan dan tentukan
            tanggal peminjaman serta rencana pengembalian.
        </p>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= sg_escape($jenis_pesan); ?> alert-dismissible fade show"
             role="alert">

            <?= sg_escape($pesan); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <form action="ajukan_peminjaman.php" method="POST">

        <!-- PILIH TANGGAL -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="fw-bold mb-0">Informasi Peminjaman</h5>
            </div>

            <div class="card-body p-4">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="tanggal_pinjam"
                               class="form-label">
                            Tanggal Peminjaman
                        </label>

                        <input type="date"
                               class="form-control"
                               id="tanggal_pinjam"
                               name="tanggal_pinjam"
                               min="<?= date('Y-m-d'); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label for="tanggal_rencana_kembali"
                               class="form-label">
                            Rencana Tanggal Pengembalian
                        </label>

                        <input type="date"
                               class="form-control"
                               id="tanggal_rencana_kembali"
                               name="tanggal_rencana_kembali"
                               min="<?= date('Y-m-d'); ?>"
                               required>
                    </div>

                    <div class="col-12">
                        <label for="catatan" class="form-label">
                            Catatan
                        </label>

                        <textarea class="form-control"
                                  id="catatan"
                                  name="catatan"
                                  rows="3"
                                  placeholder="Contoh: Untuk kegiatan camping sekolah (opsional)"></textarea>
                    </div>

                </div>
            </div>
        </div>

        <!-- PILIH BARANG -->
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-header bg-white py-3">
                <h5 class="fw-bold mb-1">Pilih Perlengkapan</h5>

                <small class="text-secondary">
                    Masukkan jumlah barang yang ingin dipinjam.
                    Isi 0 jika tidak membutuhkan barang tersebut.
                </small>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">

                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">No.</th>
                            <th>Nama Alat</th>
                            <th>Kategori</th>
                            <th>Stok</th>
                            <th>Kondisi</th>
                            <th style="min-width: 130px;">Jumlah Pinjam</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (mysqli_num_rows($hasil_alat) > 0): ?>

                            <?php $no = 1; ?>

                            <?php while ($alat = mysqli_fetch_assoc($hasil_alat)): ?>

                                <?php
                                $dapat_dipinjam =
                                    (int) $alat['stok'] > 0 &&
                                    $alat['kondisi'] === 'baik';
                                ?>

                                <tr>
                                    <td class="ps-3"><?= $no++; ?></td>

                                    <td>
                                        <?= sg_escape($alat['nama_alat']); ?>
                                    </td>

                                    <td>
                                        <?= sg_escape(
                                            $alat['nama_kategori'] ?? '-'
                                        ); ?>
                                    </td>

                                    <td><?= (int) $alat['stok']; ?></td>

                                    <td>
                                        <?php if ($alat['kondisi'] === 'baik'): ?>
                                            <span class="badge text-bg-success">
                                                Baik
                                            </span>
                                        <?php elseif ($alat['kondisi'] === 'rusak ringan'): ?>
                                            <span class="badge text-bg-warning">
                                                Rusak Ringan
                                            </span>
                                        <?php else: ?>
                                            <span class="badge text-bg-danger">
                                                Rusak Berat
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <input
                                            type="number"
                                            class="form-control"
                                            name="jumlah[<?= (int) $alat['id_alat']; ?>]"
                                            min="0"
                                            max="<?= (int) $alat['stok']; ?>"
                                            step="1"
                                            value="0"
                                            aria-label="Jumlah <?= sg_escape($alat['nama_alat']); ?>"
                                            <?= !$dapat_dipinjam ? 'disabled' : ''; ?>
                                        >
                                    </td>
                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6"
                                    class="text-center text-secondary py-4">
                                    Belum ada data perlengkapan.
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>

                </table>
            </div>

            <div class="card-body p-4">
                <?php
                mysqli_data_seek($hasil_alat, 0);
                $ada_alat = mysqli_num_rows($hasil_alat) > 0;
                ?>

                <?php if ($ada_alat): ?>
                    <button type="submit" class="btn btn-success">
                        Kirim Pengajuan
                    </button>
                <?php else: ?>
                    <button type="button"
                            class="btn btn-success"
                            disabled>
                        Kirim Pengajuan
                    </button>
                <?php endif; ?>

                <a href="<?= BASE_URL; ?>/peminjam/index.php"
                   class="btn btn-outline-secondary ms-1">
                    Batal
                </a>
            </div>

        </div>

    </form>

</main>

<script>
const tanggalPinjam = document.getElementById('tanggal_pinjam');
const tanggalKembali = document.getElementById('tanggal_rencana_kembali');
const hariIni = <?= json_encode(date('Y-m-d')); ?>;

function aturTanggalKembali() {
    tanggalKembali.min = tanggalPinjam.value || hariIni;

    if (
        tanggalKembali.value &&
        tanggalKembali.value < tanggalKembali.min
    ) {
        tanggalKembali.value = tanggalKembali.min;
    }
}

tanggalPinjam.addEventListener('change', aturTanggalKembali);
aturTanggalKembali();
</script>

<?php include '../template/footer.php'; ?>
