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

// Proses tambah, edit, dan hapus kategori
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    try {
        if ($aksi === 'tambah') {
            $nama_kategori = trim($_POST['nama_kategori'] ?? '');
            $keterangan = trim($_POST['keterangan'] ?? '');

            if ($nama_kategori === '') {
                throw new InvalidArgumentException(
                    'Nama kategori wajib diisi.'
                );
            }

            $query = "INSERT INTO kategori (nama_kategori, keterangan)
                      VALUES (?, ?)";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param(
                $stmt,
                "ss",
                $nama_kategori,
                $keterangan
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['pesan'] = 'Kategori berhasil ditambahkan.';
            $_SESSION['jenis_pesan'] = 'success';

        } elseif ($aksi === 'edit') {
            $id_kategori = (int) ($_POST['id_kategori'] ?? 0);
            $nama_kategori = trim($_POST['nama_kategori'] ?? '');
            $keterangan = trim($_POST['keterangan'] ?? '');

            if ($id_kategori <= 0 || $nama_kategori === '') {
                throw new InvalidArgumentException(
                    'Data kategori tidak valid.'
                );
            }

            $query = "UPDATE kategori
                      SET nama_kategori = ?, keterangan = ?
                      WHERE id_kategori = ?";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param(
                $stmt,
                "ssi",
                $nama_kategori,
                $keterangan,
                $id_kategori
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['pesan'] = 'Kategori berhasil diperbarui.';
            $_SESSION['jenis_pesan'] = 'success';

        } elseif ($aksi === 'hapus') {
            $id_kategori = (int) ($_POST['id_kategori'] ?? 0);

            if ($id_kategori <= 0) {
                throw new InvalidArgumentException(
                    'ID kategori tidak valid.'
                );
            }

            $query = "DELETE FROM kategori WHERE id_kategori = ?";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_kategori);
            mysqli_stmt_execute($stmt);

            $terhapus = mysqli_stmt_affected_rows($stmt);

            mysqli_stmt_close($stmt);

            if ($terhapus > 0) {
                $_SESSION['pesan'] = 'Kategori berhasil dihapus.';
                $_SESSION['jenis_pesan'] = 'success';
            } else {
                $_SESSION['pesan'] = 'Kategori tidak ditemukan.';
                $_SESSION['jenis_pesan'] = 'warning';
            }

        } else {
            throw new InvalidArgumentException(
                'Aksi tidak valid.'
            );
        }

    } catch (InvalidArgumentException $e) {
        $_SESSION['pesan'] = $e->getMessage();
        $_SESSION['jenis_pesan'] = 'warning';

    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            $_SESSION['pesan'] =
                'Nama kategori sudah ada. Gunakan nama lain.';
            $_SESSION['jenis_pesan'] = 'warning';

        } elseif ((int) $e->getCode() === 1451) {
            $_SESSION['pesan'] =
                'Kategori tidak dapat dihapus karena masih digunakan oleh data alat.';
            $_SESSION['jenis_pesan'] = 'warning';

        } else {
            $_SESSION['pesan'] =
                'Terjadi kesalahan saat memproses kategori.';
            $_SESSION['jenis_pesan'] = 'danger';
        }
    }

    header("Location: kategori.php");
    exit;
}

// Ambil data kategori yang akan diedit
$edit_kategori = null;

if (isset($_GET['edit'])) {
    $id_edit = (int) $_GET['edit'];

    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id_kategori, nama_kategori, keterangan
         FROM kategori
         WHERE id_kategori = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id_edit);
    mysqli_stmt_execute($stmt);

    $hasil_edit = mysqli_stmt_get_result($stmt);
    $edit_kategori = mysqli_fetch_assoc($hasil_edit);

    mysqli_stmt_close($stmt);

    if (!$edit_kategori) {
        $_SESSION['pesan'] = 'Kategori tidak ditemukan.';
        $_SESSION['jenis_pesan'] = 'warning';

        header("Location: kategori.php");
        exit;
    }
}

// Ambil semua data kategori
$query = "SELECT id_kategori, nama_kategori, keterangan
          FROM kategori
          ORDER BY nama_kategori ASC";

$hasil_kategori = mysqli_query($koneksi, $query);

$pesan = $_SESSION['pesan'] ?? '';
$jenis_pesan = $_SESSION['jenis_pesan'] ?? 'success';

unset($_SESSION['pesan'], $_SESSION['jenis_pesan']);

$judul_halaman = "Kelola Kategori";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-2 mb-4">
        <div>
            <h2 class="fw-bold">Kelola Kategori</h2>
            <p class="text-secondary mb-0">
                Atur kategori perlengkapan outdoor SummitGear.
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

    <!-- FORM TAMBAH DAN EDIT -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">
                <?= $edit_kategori
                    ? 'Edit Kategori'
                    : 'Tambah Kategori'; ?>
            </h5>
        </div>

        <div class="card-body p-4">
            <form action="kategori.php" method="POST">

                <input type="hidden"
                       name="aksi"
                       value="<?= $edit_kategori ? 'edit' : 'tambah'; ?>">

                <?php if ($edit_kategori): ?>
                    <input type="hidden"
                           name="id_kategori"
                           value="<?= (int) $edit_kategori['id_kategori']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="nama_kategori" class="form-label">
                        Nama Kategori
                    </label>

                    <input type="text"
                           class="form-control"
                           id="nama_kategori"
                           name="nama_kategori"
                           maxlength="100"
                           placeholder="Contoh: Perlengkapan Tidur"
                           value="<?= escape($edit_kategori['nama_kategori'] ?? ''); ?>"
                           required>
                </div>

                <div class="mb-3">
                    <label for="keterangan" class="form-label">
                        Keterangan
                    </label>

                    <textarea class="form-control"
                              id="keterangan"
                              name="keterangan"
                              rows="3"
                              placeholder="Keterangan kategori (opsional)"><?= escape($edit_kategori['keterangan'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn btn-success">
                    <?= $edit_kategori
                        ? 'Simpan Perubahan'
                        : 'Tambah Kategori'; ?>
                </button>

                <?php if ($edit_kategori): ?>
                    <a href="kategori.php"
                       class="btn btn-outline-secondary ms-1">
                        Batal
                    </a>
                <?php endif; ?>

            </form>
        </div>
    </div>

    <!-- TABEL KATEGORI -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Daftar Kategori</h5>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">

                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">No.</th>
                        <th>Nama Kategori</th>
                        <th>Keterangan</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (
                        $hasil_kategori &&
                        mysqli_num_rows($hasil_kategori) > 0
                    ): ?>

                        <?php $no = 1; ?>

                        <?php while (
                            $kategori = mysqli_fetch_assoc($hasil_kategori)
                        ): ?>
                            <tr>
                                <td class="ps-3"><?= $no++; ?></td>

                                <td>
                                    <?= escape($kategori['nama_kategori']); ?>
                                </td>

                                <td>
                                    <?= escape($kategori['keterangan'] ?? ''); ?>
                                </td>

                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">

                                        <a href="kategori.php?edit=<?= (int) $kategori['id_kategori']; ?>"
                                           class="btn btn-sm btn-warning">
                                            Edit
                                        </a>

                                        <form action="kategori.php"
                                              method="POST"
                                              onsubmit="return confirm('Yakin ingin menghapus kategori ini?');">

                                            <input type="hidden"
                                                   name="aksi"
                                                   value="hapus">

                                            <input type="hidden"
                                                   name="id_kategori"
                                                   value="<?= (int) $kategori['id_kategori']; ?>">

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
                            <td colspan="4"
                                class="text-center text-secondary py-4">
                                Belum ada data kategori.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

</main>

<?php include '../template/footer.php'; ?>
