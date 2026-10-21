
<?php
$judul_halaman = "Login";

include 'template/header.php';

$pesan_error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>

<main class="container py-5 flex-grow-1">

    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4 p-md-5">

                    <div class="text-center mb-4">
                        <h2 class="fw-bold">Login SummitGear</h2>
                        <p class="text-secondary mb-0">
                            Silakan masuk ke akun kamu.
                        </p>
                    </div>

                    <?php if ($pesan_error !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= htmlspecialchars($pesan_error); ?>
                        </div>
                    <?php endif; ?>

                    <form action="<?= BASE_URL; ?>/proses/login_proses.php"
                          method="POST">

                        <div class="mb-3">
                            <label for="username" class="form-label">
                                Username
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="username"
                                name="username"
                                placeholder="Masukkan username"
                                autocomplete="username"
                                required
                            >
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Masukkan password"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <button type="submit"
                                class="btn btn-success w-100">
                            Login
                        </button>

                    </form>

                    <div class="text-center mt-4">
                        <a href="<?= BASE_URL; ?>/index.php"
                           class="text-decoration-none">
                            Kembali ke beranda
                        </a>
                    </div>

                </div>
            </div>

        </div>
    </div>

</main>

<?php include 'template/footer.php'; ?>
