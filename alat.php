
<?php
include 'config/koneksi.php';

$judul_halaman = "Daftar Alat";

$query = "SELECT
            a.id_alat,
            a.nama_alat,
            a.stok,
            a.kondisi,
            a.keterangan,
            k.nama_kategori
          FROM alat a
          LEFT JOIN kategori k
            ON a.id_kategori = k.id_kategori
          ORDER BY a.nama_alat ASC";

$hasil = mysqli_query($koneksi, $query);

include 'template/header.php';
?>

<main class="container py-5">

    <div class="mb-4">
        <h2 class="fw-bold">Daftar Perlengkapan Outdoor</h2>
        <p class="text-secondary">
            Lihat perlengkapan pendakian dan camping
            yang tersedia di SummitGear.
        </p>
    </div>

    <?php if (!$hasil): ?>

        <div class="alert alert-danger">
            Data alat gagal ditampilkan.
            Periksa kembali struktur tabel database.
        </div>

    <?php elseif (mysqli_num_rows($hasil) > 0): ?>

        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>No.</th>
                        <th>Nama Alat</th>
                        <th>Kategori</th>
                        <th>Stok</th>
                        <th>Kondisi</th>
                        <th>Keterangan</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php $no = 1; ?>

                    <?php while ($alat = mysqli_fetch_assoc($hasil)): ?>
                        <tr>
                            <td><?= $no++; ?></td>

                            <td>
                                <?= htmlspecialchars($alat['nama_alat']); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $alat['nama_kategori'] ?? '-'
                                ); ?>
                            </td>

                            <td><?= (int) $alat['stok']; ?></td>

                            <td>
                                <?= htmlspecialchars($alat['kondisi']); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $alat['keterangan'] ?? '-'
                                ); ?>
                            </td>

                            <td>
                                <?php if ((int) $alat['stok'] > 0
                                    && $alat['kondisi'] === 'baik'): ?>

                                    <span class="badge text-bg-success">
                                        Tersedia
                                    </span>

                                <?php elseif ((int) $alat['stok'] <= 0): ?>

                                    <span class="badge text-bg-danger">
                                        Stok Habis
                                    </span>

                                <?php else: ?>

                                    <span class="badge text-bg-warning">
                                        Perlu Pemeriksaan
                                    </span>

                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>

        <div class="alert alert-info">
            Belum ada data perlengkapan.
            Data alat akan muncul setelah ditambahkan ke database.
        </div>

    <?php endif; ?>

    <a href="<?= BASE_URL; ?>/index.php"
       class="btn btn-secondary mt-3">
        Kembali ke Beranda
    </a>

</main>

<?php include 'template/footer.php'; ?>
