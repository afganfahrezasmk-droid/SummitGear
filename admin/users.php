
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

$escape = function ($nilai) {
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
};

// Proses tambah, edit, dan hapus pengguna
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    try {
        // TAMBAH PENGGUNA
        if ($aksi === 'tambah') {
            $nama = trim($_POST['nama'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';

            if (
                $nama === '' ||
                $username === '' ||
                $password === '' ||
                !in_array($role, ['admin', 'petugas', 'peminjam'], true)
            ) {
                throw new InvalidArgumentException(
                    'Semua data wajib diisi dengan benar.'
                );
            }

            if (strlen($password) < 8) {
                throw new InvalidArgumentException(
                    'Password minimal 8 karakter.'
                );
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $query = "INSERT INTO users (nama, username, password, role)
                      VALUES (?, ?, ?, ?)";

            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param(
                $stmt,
                "ssss",
                $nama,
                $username,
                $hash,
                $role
            );

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['pesan'] = 'Pengguna berhasil ditambahkan.';
            $_SESSION['jenis_pesan'] = 'success';

        // EDIT PENGGUNA
        } elseif ($aksi === 'edit') {
            $id_user = (int) ($_POST['id_user'] ?? 0);
            $nama = trim($_POST['nama'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';

            if (
                $id_user <= 0 ||
                $nama === '' ||
                $username === '' ||
                !in_array($role, ['admin', 'petugas', 'peminjam'], true)
            ) {
                throw new InvalidArgumentException(
                    'Data pengguna tidak valid.'
                );
            }

            // Mencegah admin mengubah role akunnya sendiri
            if (
                $id_user === (int) $_SESSION['user_id'] &&
                $role !== 'admin'
            ) {
                throw new InvalidArgumentException(
                    'Role akun admin yang sedang digunakan tidak dapat diubah.'
                );
            }

            if ($password !== '' && strlen($password) < 8) {
                throw new InvalidArgumentException(
                    'Password baru minimal 8 karakter.'
                );
            }

            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $query = "UPDATE users
                          SET nama = ?, username = ?, password = ?, role = ?
                          WHERE id_user = ?";

                $stmt = mysqli_prepare($koneksi, $query);
                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssi",
                    $nama,
                    $username,
                    $hash,
                    $role,
                    $id_user
                );
            } else {
                $query = "UPDATE users
                          SET nama = ?, username = ?, role = ?
                          WHERE id_user = ?";

                $stmt = mysqli_prepare($koneksi, $query);
                mysqli_stmt_bind_param(
                    $stmt,
                    "sssi",
                    $nama,
                    $username,
                    $role,
                    $id_user
                );
            }

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['pesan'] = 'Data pengguna berhasil diperbarui.';
            $_SESSION['jenis_pesan'] = 'success';

        // HAPUS PENGGUNA
        } elseif ($aksi === 'hapus') {
            $id_user = (int) ($_POST['id_user'] ?? 0);

            if ($id_user <= 0) {
                throw new InvalidArgumentException(
                    'ID pengguna tidak valid.'
                );
            }

            if ($id_user === (int) $_SESSION['user_id']) {
                throw new InvalidArgumentException(
                    'Kamu tidak dapat menghapus akun yang sedang digunakan.'
                );
            }

            $query = "DELETE FROM users WHERE id_user = ?";
            $stmt = mysqli_prepare($koneksi, $query);
            mysqli_stmt_bind_param($stmt, "i", $id_user);

            mysqli_stmt_execute($stmt);
            $terhapus = mysqli_stmt_affected_rows($stmt);

            mysqli_stmt_close($stmt);

            if ($terhapus > 0) {
                $_SESSION['pesan'] = 'Pengguna berhasil dihapus.';
                $_SESSION['jenis_pesan'] = 'success';
            } else {
                $_SESSION['pesan'] = 'Data pengguna tidak ditemukan.';
                $_SESSION['jenis_pesan'] = 'warning';
            }

        } else {
            throw new InvalidArgumentException(
                'Aksi yang diminta tidak valid.'
            );
        }

    } catch (InvalidArgumentException $e) {
        $_SESSION['pesan'] = $e->getMessage();
        $_SESSION['jenis_pesan'] = 'warning';

    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            $_SESSION['pesan'] =
                'Username sudah digunakan. Silakan gunakan username lain.';
        } else {
            $_SESSION['pesan'] =
                'Data gagal diproses. Akun mungkin masih digunakan oleh transaksi.';
        }

        $_SESSION['jenis_pesan'] = 'danger';
    }

    header("Location: users.php");
    exit;
}

// Ambil data pengguna untuk form edit
$edit_user = null;

if (isset($_GET['edit'])) {
    $id_edit = (int) $_GET['edit'];

    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id_user, nama, username, role
         FROM users
         WHERE id_user = ?"
    );

    mysqli_stmt_bind_param($stmt, "i", $id_edit);
    mysqli_stmt_execute($stmt);

    $hasil_edit = mysqli_stmt_get_result($stmt);
    $edit_user = mysqli_fetch_assoc($hasil_edit);

    mysqli_stmt_close($stmt);

    if (!$edit_user) {
        $_SESSION['pesan'] = 'Pengguna tidak ditemukan.';
        $_SESSION['jenis_pesan'] = 'warning';

        header("Location: users.php");
        exit;
    }
}

// Ambil seluruh data pengguna
$query = "SELECT id_user, nama, username, role, created_at
          FROM users
          ORDER BY id_user DESC";

$hasil_users = mysqli_query($koneksi, $query);

$pesan = $_SESSION['pesan'] ?? '';
$jenis_pesan = $_SESSION['jenis_pesan'] ?? 'success';

unset($_SESSION['pesan'], $_SESSION['jenis_pesan']);

$judul_halaman = "Kelola Pengguna";
include '../template/header.php';
?>

<main class="container py-5 flex-grow-1">

    <div class="d-flex justify-content-between align-items-center
                flex-wrap gap-2 mb-4">
        <div>
            <h2 class="fw-bold">Kelola Pengguna</h2>
            <p class="text-secondary mb-0">
                Kelola akun admin, petugas, dan peminjam SummitGear.
            </p>
        </div>

        <a href="<?= BASE_URL; ?>/admin/index.php"
           class="btn btn-outline-secondary">
            Kembali ke Dashboard
        </a>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= $escape($jenis_pesan); ?> alert-dismissible fade show"
             role="alert">
            <?= $escape($pesan); ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- FORM TAMBAH / EDIT -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">
                <?= $edit_user ? 'Edit Pengguna' : 'Tambah Pengguna'; ?>
            </h5>
        </div>

        <div class="card-body p-4">
            <form action="users.php" method="POST">

                <input type="hidden"
                       name="aksi"
                       value="<?= $edit_user ? 'edit' : 'tambah'; ?>">

                <?php if ($edit_user): ?>
                    <input type="hidden"
                           name="id_user"
                           value="<?= (int) $edit_user['id_user']; ?>">
                <?php endif; ?>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label for="nama" class="form-label">
                            Nama Lengkap
                        </label>

                        <input type="text"
                               class="form-control"
                               id="nama"
                               name="nama"
                               maxlength="100"
                               value="<?= $escape($edit_user['nama'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label for="username" class="form-label">
                            Username
                        </label>

                        <input type="text"
                               class="form-control"
                               id="username"
                               name="username"
                               maxlength="50"
                               value="<?= $escape($edit_user['username'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label for="password" class="form-label">
                            Password
                        </label>

                        <input type="password"
                               class="form-control"
                               id="password"
                               name="password"
                               minlength="8"
                               autocomplete="new-password"
                               <?= $edit_user ? '' : 'required'; ?>>

                        <?php if ($edit_user): ?>
                            <div class="form-text">
                                Kosongkan jika password tidak diganti.
                            </div>
                        <?php else: ?>
                            <div class="form-text">
                                Minimal 8 karakter.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="role" class="form-label">
                            Role Pengguna
                        </label>

                        <select class="form-select"
                                id="role"
                                name="role"
                                required>

                            <option value="">Pilih role</option>

                            <?php
                            $daftar_role = [
                                'admin' => 'Admin',
                                'petugas' => 'Petugas',
                                'peminjam' => 'Peminjam'
                            ];
                            ?>

                            <?php foreach ($daftar_role as $nilai => $label): ?>
                                <option value="<?= $nilai; ?>"
                                    <?= (($edit_user['role'] ?? '') === $nilai)
                                        ? 'selected'
                                        : ''; ?>>
                                    <?= $label; ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-success">
                            <?= $edit_user ? 'Simpan Perubahan' : 'Tambah Pengguna'; ?>
                        </button>

                        <?php if ($edit_user): ?>
                            <a href="users.php"
                               class="btn btn-outline-secondary ms-1">
                                Batal
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <!-- TABEL DATA PENGGUNA -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Daftar Pengguna</h5>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">

                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">No.</th>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Tanggal Dibuat</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($hasil_users && mysqli_num_rows($hasil_users) > 0): ?>

                            <?php $no = 1; ?>

                            <?php while ($user = mysqli_fetch_assoc($hasil_users)): ?>
                                <tr>
                                    <td class="ps-3"><?= $no++; ?></td>
                                    <td><?= $escape($user['nama']); ?></td>
                                    <td><?= $escape($user['username']); ?></td>

                                    <td>
                                        <?php
                                        $warna_role = [
                                            'admin' => 'danger',
                                            'petugas' => 'primary',
                                            'peminjam' => 'success'
                                        ];

                                        $warna = $warna_role[$user['role']] ?? 'secondary';
                                        ?>

                                        <span class="badge text-bg-<?= $warna; ?>">
                                            <?= ucfirst($escape($user['role'])); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= $escape($user['created_at']); ?>
                                    </td>

                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">

                                            <a href="users.php?edit=<?= (int) $user['id_user']; ?>"
                                               class="btn btn-sm btn-warning">
                                                Edit
                                            </a>

                                            <form action="users.php"
                                                  method="POST"
                                                  onsubmit="return confirm('Yakin ingin menghapus pengguna ini?');">

                                                <input type="hidden"
                                                       name="aksi"
                                                       value="hapus">

                                                <input type="hidden"
                                                       name="id_user"
                                                       value="<?= (int) $user['id_user']; ?>">

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
                                <td colspan="6"
                                    class="text-center text-secondary py-4">
                                    Belum ada data pengguna.
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>

                </table>
            </div>
        </div>
    </div>

</main>

<?php include '../template/footer.php'; ?>
