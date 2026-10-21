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

function escape($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

// Mencatat aktivitas admin
function catatAktivitas($koneksi, $id_user, $aktivitas)
{
    try {
        $query = "INSERT INTO log_aktivitas (id_user, aktivitas)
                  VALUES (?, ?)";

        $stmt = mysqli_prepare($koneksi, $query);
        mysqli_stmt_bind_param($stmt, "is", $id_user, $aktivitas);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (mysqli_sql_exception $e) {
        error_log("Gagal mencatat aktivitas: " . $e->getMessage());
    }
}

$daftar_kondisi = ['baik', 'rusak ringan', 'rusak berat'];

// Proses tambah, edit, dan hapus alat
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    try {
        if ($aksi === 'tambah') {
            $nama_alat = trim($_POST['nama_alat'] ?? '');
            $id_kategori = (int) ($_POST['id_kategori'] ?? 0);
            $stok_input = $_POST['stok'] ?? '';
            $kondisi = $_POST['kondisi'] ?? '';
            $keterangan = trim($_POST['keterangan'] ?? '');

            if (
                $nama_alat === '' ||
                $id_kategori <= 0 ||
                filter_var($stok_input, FILTER_VALIDATE_INT) === false ||
                (int) $stok_input < 0 ||
                !in_array($kondisi, $daftar_kondisi, true)
            ) {
                throw new InvalidArgumentException(
                    'Periksa kembali nama alat, kategori, stok, dan kondisi.'
                );
            }

            $stok = (int) $stok_input;

            $query = "INSERT INTO alat
                      (id_kategori, nama_alat, stok, kondisi, keterangan)
                      VALUES (?, ?, ?, ?, ?)";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param(
                $stmt,
                "isiss",
                $id_kategori,
                $nama_alat,
                $stok,
                $kondisi,
                $keterangan
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            catatAktivitas(
                $koneksi,
                (int) $_SESSION['user_id'],
                "Menambahkan alat: " . $nama_alat
            );

            $_SESSION['pesan'] = 'Data alat berhasil ditambahkan.';
            $_SESSION['jenis_pesan'] = 'success';

        } elseif ($aksi === 'edit') {
            $id_alat = (int) ($_POST['id_alat'] ?? 0);
            $nama_alat = trim($_POST['nama_alat'] ?? '');
            $id_kategori = (int) ($_POST['id_kategori'] ?? 0);
            $stok_input = $_POST['stok'] ?? '';
            $kondisi = $_POST['kondisi'] ?? '';
            $keterangan = trim($_POST['keterangan'] ?? '');

            if (
                $id_alat <= 0 ||
                $nama_alat === '' ||
                $id_kategori <= 0 ||
                filter_var($stok_input, FILTER_VALIDATE_INT) === false ||
                (int) $stok_input < 0 ||
                !in_array($kondisi, $daftar_kondisi, true)
            ) {
                throw new InvalidArgumentException(
                    'Periksa kembali data alat yang akan diubah.'
                );
            }

            $stok = (int) $stok_input;

            $query = "UPDATE alat
                      SET id_kategori = ?,
                          nama_alat = ?,
                          stok = ?,
                          kondisi = ?,
                          keterangan = ?
                      WHERE id_alat = ?";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param(
                $stmt,
                "isissi",
                $id_kategori,
                $nama_alat,
                $stok,
                $kondisi,
                $keterangan,
                $id_alat
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            catatAktivitas(
                $koneksi,
                (int) $_SESSION['user_id'],
                "Mengubah data alat: " . $nama_alat
            );

            $_SESSION['pesan'] = 'Data alat berhasil diperbarui.';
            $_SESSION['jenis_pesan'] = 'success';

        } elseif ($aksi === 'hapus') {
            $id_alat = (int) ($_POST['id_alat'] ?? 0);

            if ($id_alat <= 0) {
                throw new InvalidArgumentException(
                    'ID alat tidak valid.'
                );
            }

            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT nama_alat FROM alat WHERE id_alat = ?"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_alat);
            mysqli_stmt_execute($stmt);

            $hasil_nama = mysqli_stmt_get_result($stmt);
            $data_alat = mysqli_fetch_assoc($hasil_nama);

            mysqli_stmt_close($stmt);

            if (!$data_alat) {
                throw new InvalidArgumentException(
                    'Data alat tidak ditemukan.'
                );
            }

            $nama_alat = $data_alat['nama_alat'];

            $stmt = mysqli_prepare(
                $koneksi,
                "DELETE FROM alat WHERE id_alat = ?"
            );

            mysqli_stmt_bind_param($stmt, "i", $id_alat);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            catatAktivitas(
                $koneksi,
                (int) $_SESSION['user_id'],
                "Menghapus alat: " . $nama_alat
            );

            $_SESSION['pesan'] = 'Data alat berhasil dihapus.';
            $_SESSION['jenis_pesan'] = 'success';

        } else {
            throw new InvalidArgumentException(
                'Aksi yang diminta tidak valid.'
            );
        }

    } catch (InvalidArgumentException $e) {
        $_SESSION['pesan'] = $e->getMessage();
        $_SESSION['jenis_pesan'] = 'warning';

    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1451) {
            $_SESSION['pesan'] =
                'Alat tidak dapat dihapus karena sudah digunakan dalam transaksi peminjaman.';
            $_SESSION['jenis_pesan'] = 'warning';
        } else {
            error_log("Kesalahan pengelolaan alat: " . $e->getMessage());

            $_SESSION['pesan'] =
                'Data gagal diproses. Periksa kembali database.';
            $_SESSION['jenis_pesan'] = 'danger';
        }
    }

    header("Location: alat.php");
    exit;
}

// Mengambil data untuk form edit
$edit_alat = null;

if (isset($_GET['edit'])) {
    $id_edit = (int) $_GET['edit'];

    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id_alat, id_kategori, nama_alat, stok, kondisi, keterangan
         FROM alat
         WHERE id_alat = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id_edit);
    mysqli_stmt_execute($stmt);

    $hasil_edit = mysqli_stmt_get_result($stmt);
    $edit_alat = mysqli_fetch_assoc($hasil_edit);

    mysqli_stmt_close($stmt);

    if (!$edit_alat) {
        $_SESSION['pesan'] = 'Data alat tidak ditemukan.';
        $_SESSION['jenis_pesan'] = 'warning';

        header("Location: alat.php");
        exit;
    }
}

// Mengambil daftar kategori untuk dropdown
$query_kategori = "SELECT id_kategori, nama_kategori
                   FROM kategori
                   ORDER BY nama_kategori ASC";

$hasil_kategori = mysqli_query($koneksi, $query_kategori);
$kategori_tersedia = mysqli_num_rows($hasil_kategori) > 0;

// Mengambil daftar alat beserta nama kategori
$query_alat = "SELECT
                    a.id_alat,
                    a.nama_alat,
                    a.stok,
                    a.kondisi,
                    a.keterangan,
                    a.id_kategori,
                    k.nama_kategori
               FROM alat a
               LEFT JOIN kategori k
                 ON a.id_kategori = k.id_kategori
               ORDER BY a.id_alat DESC";

$hasil_alat = mysqli_query($koneksi, $query_alat);

$pesan = $_SESSION['pesan'] ?? '';
$jenis_pesan = $_SESSION['jenis_pesan'] ?? 'success';

unset($_SESSION['pesan'], $_SESSION['jenis_pesan']);

$judul_halaman = "Kelola Alat";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-2 mb-4">
        <div>
            <h2 class="fw-bold">Kelola Alat</h2>
            <p class="text-secondary mb-0">
                Kelola inventaris perlengkapan outdoor SummitGear.
            </p>
        </div>

        <a href="<?= BASE_URL; ?>/admin/index.php"
           class="btn btn-outline-secondary">
            Kembali ke Dashboard
        </a>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= escape($jenis_pesan); ?> alert-dismissible fade show"
             role="alert">
            <?= escape($pesan); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <?php if (!$kategori_tersedia): ?>
        <div class="alert alert-warning">
            Belum ada kategori. Tambahkan kategori terlebih dahulu
            sebelum memasukkan data alat.

            <a href="<?= BASE_URL; ?>/admin/kategori.php"
               class="alert-link">
                Kelola kategori
            </a>
        </div>
    <?php endif; ?>

    <!-- FORM TAMBAH DAN EDIT ALAT -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">
                <?= $edit_alat ? 'Edit Alat' : 'Tambah Alat'; ?>
            </h5>
        </div>

        <div class="card-body p-4">

            <form action="alat.php" method="POST">

                <input type="hidden"
                       name="aksi"
                       value="<?= $edit_alat ? 'edit' : 'tambah'; ?>">

                <?php if ($edit_alat): ?>
                    <input type="hidden"
                           name="id_alat"
                           value="<?= (int) $edit_alat['id_alat']; ?>">
                <?php endif; ?>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="nama_alat" class="form-label">
                            Nama Alat
                        </label>

                        <input type="text"
                               class="form-control"
                               id="nama_alat"
                               name="nama_alat"
                               maxlength="100"
                               placeholder="Contoh: Tenda 2 Orang"
                               value="<?= escape($edit_alat['nama_alat'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label for="id_kategori" class="form-label">
                            Kategori
                        </label>

                        <select class="form-select"
                                id="id_kategori"
                                name="id_kategori"
                                required
                                <?= !$kategori_tersedia ? 'disabled' : ''; ?>>

                            <option value="">Pilih kategori</option>

                            <?php while ($kategori = mysqli_fetch_assoc($hasil_kategori)): ?>
                                <option
                                    value="<?= (int) $kategori['id_kategori']; ?>"
                                    <?= (int) ($edit_alat['id_kategori'] ?? 0)
                                        === (int) $kategori['id_kategori']
                                        ? 'selected'
                                        : ''; ?>>

                                    <?= escape($kategori['nama_kategori']); ?>

                                </option>
                            <?php endwhile; ?>

                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="stok" class="form-label">
                            Stok
                        </label>

                        <input type="number"
                               class="form-control"
                               id="stok"
                               name="stok"
                               min="0"
                               step="1"
                               value="<?= escape($edit_alat['stok'] ?? '0'); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label for="kondisi" class="form-label">
                            Kondisi Alat
                        </label>

                        <select class="form-select"
                                id="kondisi"
                                name="kondisi"
                                required>

                            <option value="">Pilih kondisi</option>

                            <?php foreach ($daftar_kondisi as $kondisi): ?>
                                <option
                                    value="<?= escape($kondisi); ?>"
                                    <?= ($edit_alat['kondisi'] ?? '') === $kondisi
                                        ? 'selected'
                                        : ''; ?>>

                                    <?= ucfirst(escape($kondisi)); ?>

                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12">
                        <label for="keterangan" class="form-label">
                            Keterangan
                        </label>

                        <textarea class="form-control"
                                  id="keterangan"
                                  name="keterangan"
                                  rows="3"
                                  placeholder="Keterangan tambahan (opsional)"><?= escape($edit_alat['keterangan'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit"
                                class="btn btn-success"
                                <?= !$kategori_tersedia ? 'disabled' : ''; ?>>

                            <?= $edit_alat
                                ? 'Simpan Perubahan'
                                : 'Tambah Alat'; ?>

                        </button>

                        <?php if ($edit_alat): ?>
                            <a href="alat.php"
                               class="btn btn-outline-secondary ms-1">
                                Batal
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </form>
        </div>
    </div>

    <!-- DAFTAR ALAT -->
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="fw-bold mb-0">Daftar Perlengkapan</h5>
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
                        <th>Keterangan</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($hasil_alat && mysqli_num_rows($hasil_alat) > 0): ?>

                        <?php $no = 1; ?>

                        <?php while ($alat = mysqli_fetch_assoc($hasil_alat)): ?>
                            <tr>
                                <td class="ps-3"><?= $no++; ?></td>

                                <td>
                                    <?= escape($alat['nama_alat']); ?>
                                </td>

                                <td>
                                    <?= escape($alat['nama_kategori'] ?? '-'); ?>
                                </td>

                                <td><?= (int) $alat['stok']; ?></td>

                                <td>
                                    <?php
                                    $warna_kondisi = [
                                        'baik' => 'success',
                                        'rusak ringan' => 'warning',
                                        'rusak berat' => 'danger'
                                    ];

                                    $warna = $warna_kondisi[$alat['kondisi']]
                                        ?? 'secondary';
                                    ?>

                                    <span class="badge text-bg-<?= $warna; ?>">
                                        <?= ucfirst(escape($alat['kondisi'])); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= escape($alat['keterangan'] ?? '-'); ?>
                                </td>

                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">

                                        <a href="alat.php?edit=<?= (int) $alat['id_alat']; ?>"
                                           class="btn btn-sm btn-warning">
                                            Edit
                                        </a>

                                        <form action="alat.php"
                                              method="POST"
                                              onsubmit="return confirm('Yakin ingin menghapus alat ini?');">

                                            <input type="hidden"
                                                   name="aksi"
                                                   value="hapus">

                                            <input type="hidden"
                                                   name="id_alat"
                                                   value="<?= (int) $alat['id_alat']; ?>">

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
                            <td colspan="7"
                                class="text-center text-secondary py-4">
                                Belum ada data alat.
                            </td>
                        </tr>

                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

</main>

<?php include '../template/footer.php'; ?>
