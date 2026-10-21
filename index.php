<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Arahkan pengguna yang sudah login ke dashboard sesuai role
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';

    if ($role === 'admin') {
        header("Location: admin/index.php");
        exit;
    }

    if ($role === 'petugas') {
        header("Location: petugas/index.php");
        exit;
    }

    if ($role === 'peminjam') {
        header("Location: peminjam/index.php");
        exit;
    }
}

$judul_halaman = "Beranda";

include 'template/header.php';
?>

<main>
    <section class="bg-white border-bottom">
        <div class="container py-5">
            <div class="row align-items-center py-lg-4">

                <div class="col-lg-7">
                    <span class="badge text-bg-success mb-3">
                        SummitGear Outdoor
                    </span>

                    <h1 class="display-5 fw-bold mb-3">
                        Persiapkan Petualanganmu Bersama SummitGear
                    </h1>

                    <p class="lead text-secondary mb-4">
                        Temukan perlengkapan pendakian dan camping
                        yang kamu butuhkan. Ajukan peminjaman alat
                        dengan mudah melalui SummitGear.
                    </p>

                    <a href="<?= BASE_URL; ?>/alat.php"
                       class="btn btn-success px-4 py-2">
                        Lihat Daftar Alat
                    </a>
                </div>

                <div class="col-lg-5 mt-4 mt-lg-0">
                    <div class="bg-light border rounded p-4">
                        <h5 class="fw-bold mb-3">
                            Perlengkapan Outdoor
                        </h5>

                        <div class="d-flex align-items-center border-bottom py-3">
                            <div class="fs-3 me-3">🏕️</div>
                            <div>
                                <h6 class="mb-1">Tenda</h6>
                                <small class="text-secondary">
                                    Perlengkapan untuk berkemah
                                </small>
                            </div>
                        </div>

                        <div class="d-flex align-items-center border-bottom py-3">
                            <div class="fs-3 me-3">🎒</div>
                            <div>
                                <h6 class="mb-1">Carrier</h6>
                                <small class="text-secondary">
                                    Tas untuk membawa perlengkapan
                                </small>
                            </div>
                        </div>

                        <div class="d-flex align-items-center py-3">
                            <div class="fs-3 me-3">🧥</div>
                            <div>
                                <h6 class="mb-1">Jaket Gunung</h6>
                                <small class="text-secondary">
                                    Perlengkapan untuk aktivitas outdoor
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <section class="container py-5">
        <div class="mb-4">
            <h2 class="fw-bold">Layanan Kami</h2>
            <p class="text-secondary">
                Pengelolaan peminjaman perlengkapan outdoor
                yang praktis dan terdata.
            </p>
        </div>

        <div class="row g-4">

            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-bold">
                            Daftar Peralatan
                        </h5>
                        <p class="card-text text-secondary">
                            Lihat jenis perlengkapan, stok,
                            dan kondisi alat yang tersedia.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-bold">
                            Pengajuan Peminjaman
                        </h5>
                        <p class="card-text text-secondary">
                            Ajukan peminjaman perlengkapan
                            sesuai kebutuhan kegiatanmu.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-bold">
                            Pengembalian Alat
                        </h5>
                        <p class="card-text text-secondary">
                            Data pengembalian dicatat agar
                            penggunaan inventaris lebih tertib.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </section>
</main>

<?php include 'template/footer.php'; ?>
