<?php
session_start();
include 'helper/koneksi.php';
date_default_timezone_set('Asia/Makassar');

// ==========================
// CEK LOGIN DAN AKSES FULL
// ==========================
if (!isset($_SESSION['username']) || $_SESSION['status'] != 'login') {
    header('Location: index.php?pesan=gagal');
    exit();
}

$username_login = $_SESSION['username'];

// Ambil data user login
$stmt = $conn->prepare("SELECT * FROM staf WHERE username = ?");
$stmt->bind_param("s", $username_login);
$stmt->execute();
$user_login = $stmt->get_result()->fetch_assoc();

if (!$user_login || $user_login['akses'] !== 'FULL') {
    echo "<!DOCTYPE html><html lang='id'><head>
            <meta charset='UTF-8'>
            <title>Akses Ditolak</title>
            <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap' rel='stylesheet'>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
            <style>body,*{font-family:'Poppins',sans-serif !important;}</style>
          </head><body>
            <script>
              Swal.fire({
                icon: 'error',
                title: 'Akses Ditolak!',
                text: 'Hanya pengguna dengan akses FULL yang diizinkan.',
                confirmButtonText: 'Kembali'
              }).then(() => { window.location.href = 'dashboard.php'; });
            </script>
          </body></html>";
    exit();
}

function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// ========================
// UPDATE STATUS PLT SELESAI OTOMATIS
// ========================
$conn->query("UPDATE plt 
              SET status = 'Selesai'
              WHERE tanggal_selesai IS NOT NULL 
              AND tanggal_selesai < CURDATE() 
              AND status = 'Aktif'");

// ========================
// FILTER & PENCARIAN
// ========================
$search        = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? 'semua';

$conditions = [];
$params     = [];
$types      = "";

if ($search !== '') {
    $like = "%$search%";
    $conditions[] = "(nama LIKE ? OR nip LIKE ? OR jabatan_plt LIKE ? OR unit_kerja LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= "ssss";
}
if ($status_filter !== 'semua') {
    $conditions[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_clause = empty($conditions) ? "1=1" : "1=1 AND " . implode(" AND ", $conditions);

$sql = "
    SELECT *
    FROM plt
    WHERE $where_clause
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$plt_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ========================
// PROSES TAMBAH PLT ADMIN
// ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tambah_plt') {
    
    // Pecah data nip dan nama dari select
    $pegawai_data = $_POST['pegawai_data'] ?? '';
    if (strpos($pegawai_data, '|') === false) {
        echo json_encode(['success'=>false,'message'=>'Pegawai tidak valid']);
        exit();
    }
    list($nip, $nama) = explode('|', $pegawai_data);
    
    $jabatan_definitif = $_POST['jabatan_definitif'] ?? '';
    $unit_kerja = $_POST['unit_kerja'] ?? '';
    $jabatan_plt = $_POST['jabatan_plt'] ?? '';
    $tanggal_mulai = $_POST['tanggal_mulai'] ?? '';
    $tanggal_selesai = $_POST['tanggal_selesai'] ?? null;
  
    if (empty($tanggal_selesai) || $tanggal_selesai === '') {
        $tanggal_selesai = null;
    }

    if (!$nip || !$nama || !$jabatan_plt || !$tanggal_mulai) {
        echo json_encode(['success'=>false,'message'=>'Data wajib tidak lengkap']);
        exit();
    }

    $upload_dir = 'uploads/plt/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $dokumen_pengajuan = null;
    $dokumen_sk = null;
    $allowed_ext = ['pdf','jpg','jpeg','png'];

    // Upload Dokumen Pengajuan
    if (!empty($_FILES['dokumen_pengajuan']['name'])) {
        $ext = strtolower(pathinfo($_FILES['dokumen_pengajuan']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext)) {
            $newname_pengajuan = time().'_pengajuan_'.$nip.'.'.$ext;
            if (move_uploaded_file($_FILES['dokumen_pengajuan']['tmp_name'], $upload_dir.$newname_pengajuan)) {
                $dokumen_pengajuan = $newname_pengajuan;
            }
        } else {
            echo json_encode(['success'=>false,'message'=>'Dokumen Pengajuan harus PDF/JPG/PNG']);
            exit();
        }
    }

    // Upload Dokumen SK
    if (!empty($_FILES['dokumen_sk']['name'])) {
        $ext = strtolower(pathinfo($_FILES['dokumen_sk']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext)) {
            $newname_sk = time().'_sk_'.$nip.'.'.$ext;
            if (move_uploaded_file($_FILES['dokumen_sk']['tmp_name'], $upload_dir.$newname_sk)) {
                $dokumen_sk = $newname_sk;
            }
        } else {
            echo json_encode(['success'=>false,'message'=>'Dokumen SK harus PDF/JPG/PNG']);
            exit();
        }
    }

    $stmt = $conn->prepare("INSERT INTO plt (nip, nama, jabatan_definitif, unit_kerja, jabatan_plt, tanggal_mulai, tanggal_selesai, dokumen_pengajuan, dokumen_sk, status) VALUES (?,?,?,?,?,?,?,?,?, 'Aktif')");
    $stmt->bind_param("sssssssss", $nip, $nama, $jabatan_definitif, $unit_kerja, $jabatan_plt, $tanggal_mulai, $tanggal_selesai, $dokumen_pengajuan, $dokumen_sk);
    
    if ($stmt->execute()) echo json_encode(['success'=>true,'message'=>'Data berhasil ditambah']);
    else echo json_encode(['success'=>false,'message'=>'Gagal']);
    exit();
}

// ========================
// PROSES EDIT PLT
// ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_plt') {
    $id = $_POST['id'];
    $jabatan_plt = $_POST['jabatan_plt'];
    $tanggal_mulai = $_POST['tanggal_mulai'];
    $tanggal_selesai = !empty($_POST['tanggal_selesai']) ? $_POST['tanggal_selesai'] : date('Y-m-d', strtotime($tanggal_mulai . ' + 3 months'));
    $status = $_POST['status'];

    // Update dokumen jika ada upload baru
    $update_doc = "";
    if (!empty($_FILES['dokumen_sk']['name'])) {
        $newname_sk = time().'_sk_edit.pdf'; // Tambahkan logika move_uploaded_file di sini
        move_uploaded_file($_FILES['dokumen_sk']['tmp_name'], 'uploads/plt/'.$newname_sk);
        $update_doc = ", dokumen_sk = '$newname_sk'";
    }

    $stmt = $conn->prepare("UPDATE plt SET jabatan_plt = ?, tanggal_mulai = ?, tanggal_selesai = ?, status = ? $update_doc WHERE id = ?");
    $stmt->bind_param("ssssi", $jabatan_plt, $tanggal_mulai, $tanggal_selesai, $status, $id);
    
    if ($stmt->execute()) echo json_encode(['success'=>true,'message'=>'Data berhasil diupdate']);
    else echo json_encode(['success'=>false,'message'=>'Gagal update']);
    exit();
}


$pegawai_list = [];
// Asumsi field jabatan dan unit_kerja ada di tabel staf
$qpegawai = $conn->query("SELECT username, nama, nip, jabatan, unit_kerja FROM staf WHERE tampil='ya' AND status_pegawai='PNS' ORDER BY nama ASC");
while($r = $qpegawai->fetch_assoc()){
    $pegawai_list[] = $r;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Data Pelaksana Tugas (PLT) – Si-UMPEG-Ta</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap-5-theme@1.5.2/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
  <link rel="icon" href="../../img/logo-tallo.png" type="image/x-icon">

  <style>
    .bg-dark-blue{
    background: #0d47a1 !important;
}
    body {
      font-family: 'Poppins', sans-serif;
      background-color: #f5f7fa;
    }
    .card { border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .card-header {
      background: linear-gradient(135deg, #198754, #146c43);
      color: white;
      padding: 1.25rem 1.5rem; 
    }
    .table { margin-bottom: 0; }
    .table th {
      background-color: var(--merah-makassar, #871919);
      color: white;
      font-weight: 500;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .table td { vertical-align: middle; }
    .badge { padding: 0.5em 0.9em; font-weight: 500; }
    .btn-action {
      width: 32px; height: 32px; padding: 0;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 6px;
    }
    .text-ellipsis {
      max-width: 200px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;
    }
    .employee-info small { font-size: 0.82rem; opacity: 0.85; }
    .dataTables_filter input { border-radius: 10px; padding: 5px 10px; }
    .dataTables_length select { border-radius: 10px; }
    
    /* Select2 Bootstrap 5 Theme Fixes */
    .select2-container--bootstrap-5 .select2-selection--single {
      height: calc(2.25rem + 2px); padding: 0.375rem 0.75rem;
      font-size: 1rem; line-height: 1.5; border: 1px solid #ced4da;
      border-radius: 0.375rem; background-color: #fff; color: #212529;
    }
    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__placeholder {
      color: #6c757d;
    }
    .select2-container--bootstrap-5 .select2-dropdown {
      border: 1px solid #ced4da; border-radius: 0.375rem;
      background-color: #fff !important; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
    }
    .select2-container--bootstrap-5 .select2-results__option {
      padding: 0.375rem 0.75rem; color: #212529 !important;
    }
    .select2-container--bootstrap-5 .select2-results__option--highlighted {
      background-color: #0d6efd !important; color: white !important;
    }
    .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field {
      border: 1px solid #ced4da; border-radius: 0.25rem; padding: 0.375rem 0.75rem;
    }
  </style>
</head>
<body>

  <div class="content-wrapper">
    <?php include 'helper/sidebar.php'; ?>

    <main class="container my-5">
      <div class="card mb-4">
        <div class="card-header">
          <h4 class="mb-0"><i class="fas fa-user-tie"></i> Data Pelaksana Tugas (PLT)</h4>
        </div>

        <div class="card-body">
          <!-- FILTER -->
          <form method="get" class="row g-3 mb-4">
            <div class="col-md-5">
              <input type="text" name="q" class="form-control" 
                     placeholder="Cari nama, NIP, atau jabatan..." 
                     value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
              <select name="status" class="form-select">
                <option value="semua">Semua Status</option>
                <option value="Aktif"   <?= $status_filter === 'Aktif'   ? 'selected' : '' ?>>Aktif</option>
                <option value="Selesai" <?= $status_filter === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
              </select>
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-filter me-1"></i> Terapkan
              </button>
            </div>
            <div class="col-md-2">
              <button type="button" class="btn btn-success w-100" id="btnUpload">
                <i class="fas fa-plus me-1"></i> Tambah PLT
              </button>
            </div>
          </form>

          <!-- TABEL -->
         <div class="table-responsive">
          <table id="tabelPLT" class="table table-bordered table-striped table-hover align-middle">
            <thead>
              <tr>
                <th class="text-center" style="width:50px">No</th>
                <th>Pegawai</th>
                <th>Jabatan Definitif & Satker</th>
                <th>Jabatan PLT</th>
                <th>Masa Jabatan</th>
                <th>Dokumen</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php $no = 1; foreach ($plt_data as $row): ?>
                <tr>
                  <td class="text-center fw-bold"><?= $no++ ?></td>
                  <td>
                    <div class="employee-info">
                      <strong><?= h($row['nama']) ?></strong><br>
                      <small>NIP: <?= h($row['nip']) ?></small>
                    </div>
                  </td>
                  <td>
                    <div class="employee-info">
                      <strong><?= h($row['jabatan_definitif']) ?></strong><br>
                      <small><?= h($row['unit_kerja']) ?></small>
                    </div>
                  </td>
                  <td class="fw-semibold text-primary"><?= h($row['jabatan_plt']) ?></td>
                  <td>
                    <small class="d-block"><i class="fas fa-calendar-alt text-muted"></i> Mulai: <?= date('d/m/Y', strtotime($row['tanggal_mulai'])) ?></small>
                    <small class="d-block"><i class="fas fa-calendar-check text-muted"></i> Selesai: <?= $row['tanggal_selesai'] ? date('d/m/Y', strtotime($row['tanggal_selesai'])) : '<span class="text-muted fst-italic">Belum ditentukan</span>' ?></small>
                  </td>
                  <td>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-warning btn-action" 
                              onclick="editPLT('<?=$row['id']?>', '<?=h($row['jabatan_plt'])?>', '<?=$row['tanggal_mulai']?>', '<?=$row['tanggal_selesai']?>')">
                          <i class="fas fa-edit"></i>
                      </button>
                      <?php if($row['dokumen_pengajuan']): ?>
                        <a href="uploads/plt/<?= h($row['dokumen_pengajuan']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Lihat Dokumen Pengajuan">
                          <i class="fas fa-file-alt"></i> Pengajuan
                        </a>
                      <?php endif; ?>
                      <?php if($row['dokumen_sk']): ?>
                        <a href="uploads/plt/<?= h($row['dokumen_sk']) ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Lihat SK">
                          <i class="fas fa-file-pdf"></i> SK
                        </a>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($row['status'] === 'Selesai'): ?>
                      <span class="badge bg-secondary">Selesai</span>
                    <?php else: ?>
                      <span class="badge bg-success">Aktif</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        </div>
      </div>
      
      <!-- MODAL TAMBAH PLT -->
      <div class="modal fade" id="uploadModal" tabindex="-1">
          <div class="modal-dialog modal-lg">
              <div class="modal-content">
                  <div class="modal-header bg-dark-blue text-white">
                      <h5 class="modal-title fw-semibold"><i class="fas fa-plus-circle"></i> Tambah Data PLT</h5>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
            
                  <form id="formUpload" enctype="multipart/form-data">
                      <div class="modal-body">
                          <input type="hidden" name="action" value="tambah_plt">
                          
                          <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-medium">Pegawai</label>
                                <select name="pegawai_data" id="pegawai_select" class="form-select select2" required>
                                    <option value="">-- Cari Nama atau NIP Pegawai --</option>
                                    <?php foreach($pegawai_list as $p): ?>
                                        <option value="<?=h($p['nip'])?>|<?=h($p['nama'])?>"
                                                data-jabatan="<?=h($p['jabatan'] ?? '')?>"
                                                data-unit_kerja="<?=h($p['unit_kerja'] ?? '')?>">
                                            <?=h($p['nama'])?> (<?=h($p['nip'])?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium">Jabatan Definitif</label>
                                <input type="text" name="jabatan_definitif" id="jabatan_definitif" class="form-control" placeholder="Contoh: Kasi Pemerintahan" readonly required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium">Unit Kerja</label>
                                <input type="text" name="unit_kerja" id="unit_kerja" class="form-control" placeholder="Contoh: Sub Bagian Umum Dan Kepegawaian, Sekretariat Kecamatan" required>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-medium">Jabatan PLT (Tugas Tambahan)</label>
                                <input type="text" name="jabatan_plt" class="form-control" placeholder="Contoh: Kepala Seksi Ketentraman Dan Ketertiban Umum" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium">Tanggal Mulai</label>
                                <input type="date" name="tanggal_mulai" class="form-control" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium">Tanggal Selesai (Opsional)</label>
                                <input type="date" name="tanggal_selesai" class="form-control">
                                <small class="text-muted">Kosongkan jika belum diketahui</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium">Dokumen Pengajuan</label>
                                <input type="file" name="dokumen_pengajuan" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-medium">Dokumen SK</label>
                                <input type="file" name="dokumen_sk" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                          </div>
                      </div>
                      <div class="modal-footer bg-light">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                          <button type="submit" class="btn btn-success">Simpan Data</button>
                      </div>
                  </form>
              </div>
          </div>
      </div>

      <div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Edit Data & Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEdit" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_plt">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-3">
                        <label>Jabatan PLT</label>
                        <input type="text" name="jabatan_plt" id="edit_jabatan" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label>Mulai</label>
                            <input type="date" name="tanggal_mulai" id="edit_mulai" class="form-control" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label>Selesai (Otomatis +3 bulan jika kosong)</label>
                            <input type="date" name="tanggal_selesai" id="edit_selesai" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="Aktif">Aktif</option>
                            <option value="Selesai">Selesai</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Ganti Dokumen SK (Opsional)</label>
                        <input type="file" name="dokumen_sk" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-warning">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>
      
    </main>
  </div>

  <?php include "helper/footer.php"; ?>
  
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  
  <script>
    const uploadModal = new bootstrap.Modal('#uploadModal');

    document.getElementById('btnUpload').addEventListener('click', () => {
        uploadModal.show();
    });

    document.getElementById('formUpload').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);

        try {
            const res = await fetch('', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();

            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: data.message
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Gagal', data.message, 'error');
            }
        } catch(err) {
            Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
        }
    });

    $(document).ready(function() {
        $('.select2').select2({
            placeholder: "Cari nama atau NIP pegawai...",
            allowClear: true,
            width: '100%',
            dropdownParent: $('#uploadModal'),
            theme: 'bootstrap-5'
        });

        // ====== AUTOFILL JABATAN & SATUAN KERJA ======
        $('#pegawai_select').on('change', function() {
            // Ambil option yang sedang dipilih
            var selectedOption = $(this).find('option:selected');
            
            // Ambil data atribut
            var jabatan = selectedOption.data('jabatan') || '';
            var unit = selectedOption.data('unit_kerja') || '';
            
            // Masukkan ke dalam input text jika bukan option kosong
            if($(this).val()) {
                $('#jabatan_definitif').val(jabatan);
                $('#unit_kerja').val(unit);
            } else {
                // Kosongkan jika Select di-clear
                $('#jabatan_definitif').val('');
                $('#unit_kerja').val('');
            }
        });

        $('#tabelPLT').DataTable({
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            order: [[1, 'asc']],
            language: {
                search: "Cari:",
                lengthMenu: "Tampilkan _MENU_ data",
                info: "Menampilkan _START_ - _END_ dari _TOTAL_ data",
                paginate: {
                    previous: "←",
                    next: "→"
                },
                // Pesan jika database kosong (Tabel kosong)
                emptyTable: `
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-50"></i>
                        <p>Tidak ada data PLT ditemukan</p>
                    </div>
                `,
                // Pesan jika pencarian tidak menemukan hasil
                zeroRecords: `
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-search fa-2x mb-2 d-block opacity-50"></i>
                        <p>Data tidak ditemukan</p>
                    </div>
                `
            }
        });
    });

    $(document).on('select2:opening', '.select2', function (e) {
        $('.modal-body').css('overflow', 'visible');
    });

    $(document).on('select2:open', '.select2', function (e) {
        setTimeout(function() {
            $('.modal-body').css('overflow', 'auto');
        }, 1); 
    });

    $(document).on('select2:closing', '.select2', function (e) {
        $('.modal-body').css('overflow', 'auto');
    });
    function editPLT(id, jabatan, mulai, selesai, status) {
    $('#edit_id').val(id);
    $('#edit_jabatan').val(jabatan);
    $('#edit_mulai').val(mulai);
    $('#edit_selesai').val(selesai);
    $('#edit_status').val(status);
    new bootstrap.Modal('#editModal').show();
}

$('#formEdit').on('submit', async (e) => {
    e.preventDefault();
    const res = await fetch('', { method: 'POST', body: new FormData(e.target) });
    const data = await res.json();
    if (data.success) {
        Swal.fire('Berhasil', data.message, 'success').then(() => location.reload());
    } else {
        Swal.fire('Gagal', data.message, 'error');
    }
});
  </script>
</body>
</html>