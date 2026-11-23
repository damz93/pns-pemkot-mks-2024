<?php
session_start();
include 'helper/koneksi.php';

setlocale(LC_TIME, 'id_ID.UTF-8');
date_default_timezone_set('Asia/Makassar');

// === ERROR & DEBUG LOG SETUP ===
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
$debugLog = __DIR__ . '/debug.log';
if (!file_exists($debugLog)) @touch($debugLog);
@chmod($debugLog, 0666);
ini_set('error_log', $debugLog);

// === CEK LOGIN ===
if (empty($_SESSION['username']) || ($_SESSION['status'] ?? '') !== 'login') {
    header('Location: index.php?pesan=gagal');
    exit();
}
$username = $_SESSION['username'];

// === CEK AKSES ===
$stmt = $conn->prepare("SELECT akses FROM staf WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$aksesDiperbolehkan = ['FULL', 'LOKET', 'PENGADMINISTRASI'];
if (!$user || !in_array($user['akses'], $aksesDiperbolehkan)) {
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Akses Ditolak</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            body, * { font-family: 'Poppins', sans-serif !important; }
            .swal2-popup { font-family: 'Poppins', sans-serif !important; }
        </style>
    </head>
    <body>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Akses Ditolak!',
            text: 'Hanya pengguna dengan akses FULL, LOKET, atau PENGADMINISTRASI yang diizinkan.',
            confirmButtonText: 'Kembali ke Dashboard',
            allowOutsideClick: false
        }).then(() => window.location.href = 'dashboard.php');
    </script>
    </body>
    </html>
    HTML;
    exit();
}

// === CEK AKSES UNTUK EDIT ===
$aksesEdit = ['FULL', 'PENGADMINISTRASI'];
$canEdit = in_array($user['akses'], $aksesEdit);

$aksesDelete = ['FULL'];
$canDelete = in_array($user['akses'], $aksesDelete);

$hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$namaHari = $hari[date('w')];
$tanggalLengkap = $namaHari . ', ' . date('d M Y');

// === DIREKTORI UPLOAD ===
$baseUploadDir = __DIR__ . '/uploads/';
$uploadDirSrikandi = $baseUploadDir . 'srikandi/';
$uploadDirDisposisi = $baseUploadDir . 'disposisi/';
foreach ([$uploadDirSrikandi, $uploadDirDisposisi] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("GAGAL BUAT FOLDER: $dir");
        }
    }
    @chmod($dir, 0755);
}

// === HELPER ===
function this_or_null($value) { return ($value === '' || $value === null) ? null : trim($value); }
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function getUploadedFile($inputName) {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$inputName];
    if (is_array($file['name'])) {
        return [
            'name' => $file['name'][0] ?? '',
            'type' => $file['type'][0] ?? '',
            'tmp_name' => $file['tmp_name'][0] ?? '',
            'error' => $file['error'][0] ?? UPLOAD_ERR_NO_FILE,
            'size' => $file['size'][0] ?? 0
        ];
    }
    return $file;
}

function formatTanggalIndo($tanggal) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $tgl = date('j', strtotime($tanggal));
    $bln = $bulan[(int)date('n', strtotime($tanggal))];
    $thn = date('Y', strtotime($tanggal));
    return "$tgl $bln $thn";
}

// === UPLOAD DISPOSISI ===
function uploadDisposisiFile($fileInputName, $existingFile = null) {
    global $uploadDirDisposisi;
    $file = getUploadedFile($fileInputName);
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File disposisi wajib diunggah dan valid.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File maksimal 5MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed)) {
        throw new Exception('Hanya PDF, JPG, JPEG, PNG yang diizinkan.');
    }
    if ($existingFile && file_exists($existingFile)) {
        @unlink($existingFile);
    }
    $newName = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $relPath = 'uploads/disposisi/' . $newName;
    $absPath = __DIR__ . '/uploads/disposisi/' . $newName;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        throw new Exception('Gagal simpan file. Cek izin folder (755).');
    }
    @chmod($absPath, 0644);
    return $relPath;
}

// === UPLOAD SRIKANDI ===
function uploadSrikandiFile($fileInputName, $existingFile = null) {
    global $uploadDirSrikandi;
    $file = getUploadedFile($fileInputName);
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File Srikandi tidak valid.');
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('File Srikandi maksimal 10MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed)) {
        throw new Exception('File Srikandi hanya boleh PDF, JPG, JPEG, atau PNG.');
    }
    if ($existingFile && file_exists($existingFile)) {
        @unlink($existingFile);
    }
    $newName = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $relPath = 'uploads/srikandi/' . $newName;
    $absPath = __DIR__ . '/uploads/srikandi/' . $newName;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        throw new Exception('Gagal simpan file Srikandi.');
    }
    @chmod($absPath, 0644);
    return $relPath;
}


// === AJAX HANDLER ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    error_log("AJAX Request: " . ($_POST['action'] ?? 'unknown') . " | User: " . ($_SESSION['username'] ?? 'none'));
    try {
        $action = $_POST['action'] ?? '';
        $id = (isset($_POST['id']) && is_numeric($_POST['id'])) ? (int)$_POST['id'] : null;

      
/*$previewNoAgenda = '001'; // default
if ($resPreview && isset($resPreview['last_no'])) {
    $previewNoAgenda = sprintf("%03d", $resPreview['last_no'] + 1);
}*/


      
      

      
       if ($action === 'tambah') {
        $perihal = trim($_POST['perihal'] ?? '');
        $tgl_diterima = $_POST['tgl_diterima'] ?? '';
        if (empty($perihal)) throw new Exception('Perihal wajib diisi!');

        $tahunInput = date('Y', strtotime($tgl_diterima));
        $tahunSekarang = date('Y');

        if ($tahunInput != $tahunSekarang) {
            $tgl_diterima = date('Y-m-d');
            $tahunInput = $tahunSekarang;
        }

        // Hitung nomor agenda DISINI saja
        $stmtLast = $conn->prepare("
            SELECT COALESCE(MAX(CAST(no_agenda AS UNSIGNED)), 0) AS last_no
            FROM disposisi_agenda
            WHERE YEAR(tanggal_diterima) = ?
        ");
        $stmtLast->bind_param("i", $tahunInput);
        $stmtLast->execute();
        $resLast = $stmtLast->get_result()->fetch_assoc();
        $no_agenda = sprintf("%04d", $resLast['last_no'] + 1);

          $tgl_surat = this_or_null($_POST['tgl_surat']);
          // Tambah Surat
          $tgl_kegiatan = $_POST['tgl_kegiatan'] ?? null;

          $tgl_kegiatan = ($tgl_kegiatan === '' || $tgl_kegiatan === '0000-00-00') ? null : $tgl_kegiatan;
          $no_surat = this_or_null($_POST['no_surat']);
          $dari = this_or_null($_POST['dari']);
          $hari         = $_POST['hari']         ?? null;
     	 $jam          = $_POST['jam']          ?? null;
          $tempat = this_or_null($_POST['tempat']);
          $keterangan = this_or_null($_POST['keterangan']);
          $pakaian = this_or_null($_POST['pakaian']);
          $pejabat_pendamping = this_or_null($_POST['pejabat_pendamping']);

          // ✅ diperbaiki:
          $sumber_surat = $_POST['sumber_surat'] ?? 'fisik';
          $status_surat = 'draft';

          $fileSrikandiPath = null;
          $fileDisposisiPath = null;

          if ($fileSrik = getUploadedFile('file_srikandi')) {
              $fileSrikandiPath = uploadSrikandiFile('file_srikandi');
          }
          if ($fileDisp = getUploadedFile('file_disposisi')) {
              $fileDisposisiPath = uploadDisposisiFile('file_disposisi');
          }
          if (!empty($_POST['is_surat_edaran'])) {
          $tgl_kegiatan = $hari = $jam = null;
      }

         $tampil = isset($_POST['tampil']) ? 1 : 0;
         
    $stmt = $conn->prepare("
        INSERT INTO disposisi_agenda
        (no_agenda, tanggal_diterima, tanggal_kegiatan, tanggal_surat, no_surat, dari, perihal, hari, jam, tempat,
         petugas_surat, sumber_surat, status_surat, keterangan, pakaian, pejabat_pendamping,
         file_srikandi, file_disposisi, tampil)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "sssssssssssssssssss",
        $no_agenda,
        $tgl_diterima,
        $tgl_kegiatan,
        $tgl_surat,
        $no_surat,
        $dari,
        $perihal,
        $hari,
        $jam,
        $tempat,
        $_SESSION['username'],
        $sumber_surat,
        $status_surat,
        $keterangan,
        $pakaian,
        $pejabat_pendamping,
        $fileSrikandiPath,
        $fileDisposisiPath,
      	$tampil
    );

    if (!$stmt->execute()) {
        error_log("INSERT ERROR: " . $stmt->error);
        throw new Exception('Gagal simpan data: ' . $stmt->error);
    }

    $newId = $conn->insert_id;
    echo json_encode(['success' => true, 'message' => 'Surat berhasil ditambahkan', 'id' => $newId]);
    exit();
}

        if ($action === 'update') {
            if (!$canEdit) throw new Exception('Akses edit ditolak');
            if (!$id) throw new Exception('ID tidak valid');
            $stmt = $conn->prepare("SELECT file_srikandi, file_disposisi FROM disposisi_agenda WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc() ?: [];
            $fileSrikandiPath = $old['file_srikandi'] ?? null;
            if ($fileSrik = getUploadedFile('file_srikandi')) {
                $fileSrikandiPath = uploadSrikandiFile('file_srikandi', $old['file_srikandi']);
            }
            $fileDisposisiPath = $old['file_disposisi'] ?? null;
            if ($fileDisp = getUploadedFile('file_disposisi')) {
                $fileDisposisiPath = uploadDisposisiFile('file_disposisi', $old['file_disposisi']);
            }

            $no_agenda = this_or_null($_POST['no_agenda']);
            $tgl_diterima = $_POST['tgl_diterima'];
            $tgl_surat = this_or_null($_POST['tgl_surat']);
            $tgl_kegiatan = $_POST['tgl_kegiatan'] ?? null;
            $hari         = $_POST['hari']         ?? null;
            $jam          = $_POST['jam']          ?? null;
            $no_surat = this_or_null($_POST['no_surat']);
            $dari = this_or_null($_POST['dari']);
            $perihal = this_or_null($_POST['perihal']);            
            $tempat = this_or_null($_POST['tempat']);
            $keterangan = this_or_null($_POST['keterangan']);
            $pakaian = this_or_null($_POST['pakaian']);
            $pejabat_pendamping = this_or_null($_POST['pejabat_pendamping']);
            $sumber_surat = $_POST['sumber_surat'] ?? 'fisik';
            $status_surat = $_POST['status_surat'] ?? 'draft';
            if (!empty($_POST['is_surat_edaran'])) {
    $tgl_kegiatan = $hari = $jam = null;
}

            $stmt = $conn->prepare("
                UPDATE disposisi_agenda SET
                no_agenda=?, tanggal_diterima=?, tanggal_surat=?, tanggal_kegiatan=?, no_surat=?, dari=?, perihal=?,
                hari=?, jam=?, tempat=?, sumber_surat=?, status_surat=?,
                keterangan=?, pakaian=?, pejabat_pendamping=?, file_srikandi=?, file_disposisi=?
                WHERE id=?
            ");
            $stmt->bind_param(
                "sssssssssssssssssi",
                $no_agenda, $tgl_diterima, $tgl_surat, $tgl_kegiatan, $no_surat, $dari, $perihal,
                $hari, $jam, $tempat, $sumber_surat, $status_surat,
                $keterangan, $pakaian, $pejabat_pendamping, $fileSrikandiPath, $fileDisposisiPath, $id
            );
            if (!$stmt->execute()) throw new Exception('Gagal update: ' . $stmt->error);
            echo json_encode(['success' => true, 'message' => 'Data diperbarui']);
            exit();
        }

        if ($action === 'get') {
            if (!$id) throw new Exception('ID tidak valid');
            $stmt = $conn->prepare("SELECT * FROM disposisi_agenda WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            echo json_encode($row ? ['success' => true, 'data' => $row] : ['success' => false, 'message' => 'Data tidak ditemukan']);
            exit();
        }

        if ($action === 'delete') {
            if (!$id) throw new Exception('ID tidak valid');
            $stmt = $conn->prepare("SELECT file_srikandi, file_disposisi FROM disposisi_agenda WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            foreach (['file_srikandi', 'file_disposisi'] as $key) {
                if ($row[$key] && file_exists($row[$key])) @unlink($row[$key]);
            }
            $stmt = $conn->prepare("DELETE FROM disposisi_agenda WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(['success' => true]);
            exit();
        }

        if ($action === 'upload_disposisi') {
            if (!$id) throw new Exception('ID tidak valid');
            $stmt = $conn->prepare("SELECT perihal, file_disposisi FROM disposisi_agenda WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) throw new Exception('Data tidak ditemukan');
            if (empty(trim($row['perihal'] ?? ''))) {
                throw new Exception('Perihal wajib diisi sebelum upload disposisi!');
            }
            $newPath = uploadDisposisiFile('file_disposisi', $row['file_disposisi']);
            $stmt = $conn->prepare("UPDATE disposisi_agenda SET file_disposisi = ? WHERE id = ?");
            $stmt->bind_param("si", $newPath, $id);
            if (!$stmt->execute()) throw new Exception('Gagal update: ' . $stmt->error);
            echo json_encode([
                'success' => true,
                'message' => 'File disposisi berhasil diupload!',
                'path' => $newPath
            ]);
            exit();
        }

        throw new Exception('Aksi tidak dikenal');
    } catch (Exception $e) {
        error_log("AJAX ERROR: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// === DATA TABEL ===
$result = $conn->query("
    SELECT d.*, s.nama AS nama_petugas
    FROM disposisi_agenda d
    LEFT JOIN staf s ON s.username = d.petugas_surat
    ORDER BY d.id DESC
");
$rows = $result->fetch_all(MYSQLI_ASSOC);
$lastAgendaNum = 0;

$tahunSekarang = date('Y');
$stmtPreview = $conn->prepare("
    SELECT COALESCE(MAX(CAST(no_agenda AS UNSIGNED)), 0) AS last_no
    FROM disposisi_agenda
    WHERE YEAR(tanggal_diterima) = ?
");
$stmtPreview->bind_param("i", $tahunSekarang);
$stmtPreview->execute();
$resPreview = $stmtPreview->get_result()->fetch_assoc();
$previewNoAgenda = sprintf("%04d", $resPreview['last_no'] + 1);
// === KEGIATAN HARI INI ===
$today = date('Y-m-d');

$stmtToday = $conn->prepare("
    SELECT d.perihal, d.hari, d.jam, d.tempat, d.pakaian, d.pejabat_pendamping,
           d.tanggal_surat, d.tanggal_kegiatan, d.keterangan, d.petugas_surat, 
           d.file_srikandi, d.file_disposisi,
           s.nama AS nama_petugas
    FROM disposisi_agenda d
    LEFT JOIN staf s ON s.username = d.petugas_surat
    WHERE d.tanggal_kegiatan = ?
    ORDER BY d.jam ASC
");
$stmtToday->bind_param("s", $today);
$stmtToday->execute();
$resultToday = $stmtToday->get_result();
$kegiatanHariIni = $resultToday->fetch_all(MYSQLI_ASSOC);
$stmtToday->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Surat Masuk dan Undangan – Si-UMPEG-Ta | Sistem Informasi Umum dan Kepegawaian Kecamatan Tallo</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">

<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="icon" href="../../img/logo-tallo.png" type="image/x-icon">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<style>
:root {
  --primary: #0d6efd;
  --success: #198754;
  --danger: #dc3545;
  --warning: #ffc107;
  --info: #0dcaf0;
  --dark: #212529;
}
/* === DETAIL SURAT: UKURAN KECIL & RAPI === */
.modal-detail .modal-body {
    padding: 0.75rem 1rem !important;
    font-size: 0.82rem !important; /* 13px */
    line-height: 1.4;
}
.modal-detail .row.mb-1 {
    margin-bottom: 0.25rem !important;
}
.modal-detail .col-4 {
    padding-right: 0.5rem !important;
}
.modal-detail .col-8 {
    padding-left: 0.5rem !important;
}
.modal-detail .fw-bold {
    font-weight: 600 !important;
    font-size: 0.78rem !important;
}
.modal-detail hr {
    margin: 0.5rem 0 !important;
    border-top: 1px dashed #dee2e6;
}
.modal-detail small {
    font-size: 0.7rem;
}
body {
  font-family: 'Poppins', sans-serif;
  background: #f5f7fa;
  color: #333;
}

/* Z-INDEX & POINTER EVENTS */
table.dataTable tbody td {
    position: relative;
    z-index: 1;
}
.dataTables_scrollHead table,
.dataTables_scrollHeadInner,
.dataTables_scrollHeadInner table {
    width: 100% !important;
    table-layout: fixed;
    margin: 0 !important;
}

/* Pastikan header dan body sinkron */
.dataTables_scrollHeadInner,
.dataTables_scrollHeadInner table {
    width: 100% !important;
    margin: 0 !important;
}

.col-aksi .btn,
.col-file .btn,
.btn-group .btn,
.dataTables_scrollBody .btn {
    position: relative;
    z-index: 1000 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}
/* .dataTables_scrollHead {
    overflow: hidden !important;
} */
.dataTables_scrollBody {
    overflow-x: auto !important;
    overflow-y: auto !important;
}

/* Tooltip */
.tooltip.show { margin-top: -3px !important; }
.tooltip { pointer-events: none !important; }

/* Header */
/* .dataTables_scrollHead {
    display: block !important;
    overflow: visible !important;
} */
.dataTables_scrollHead {
    overflow: hidden !important;
}
.dataTables_scrollBody {
    overflow-x: auto !important;
    overflow-y: auto !important;
    margin-top: 0 !important;
}
.dataTables_scrollHeadInner,
.dataTables_scrollHeadInner table {
    width: 100% !important;
    margin: 0 !important;
    table-layout: fixed !important;
}
/* === UKURAN FONT & TABEL === */
.table {
    font-size: 0.875rem; /* 14px */
    line-height: 1.4;
}

/* Tombol kecil & ikon lebih kecil */
.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 6px;
    transition: all 0.2s ease;
}
.btn-sm i {
    font-size: 0.8em !important;
}

/* Kolom aksi: tombol jadi kotak kecil */
.col-aksi .btn-sm {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
}

/* Truncate teks panjang */
.text-truncate-inline {
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

/* Lebar kolom default */
.col-no { width: 35px; }
.col-agenda { width: 90px; }
.col-surat { width: 120px; }
.col-dari { width: 130px; }
.col-perihal { width: 180px; }
.col-waktu { width: 160px; }
.col-petugas { width: 100px; }
.col-file { width: 80px; }
.col-aksi { width: 160px; }

/* === RESPONSIF: Font & Layout Semakin Kecil === */
@media (max-width: 1400px) {
    .table { font-size: 0.84rem; }
    .table th, .table td { padding: 0.4rem 0.35rem; }
}

@media (max-width: 1200px) {
    .table { font-size: 0.82rem; }
    .table th, .table td { padding: 0.38rem 0.32rem; }
    .btn-sm { font-size: 0.7rem; }
}

@media (max-width: 992px) {
    .table { font-size: 0.78rem; }
    .table th, .table td { padding: 0.35rem 0.3rem; }
    .btn-sm { font-size: 0.68rem; padding: 0.2rem 0.4rem; }
    .col-perihal { width: 150px; }
    .col-dari { width: 110px; }
    .col-aksi { width: 140px; }
}

@media (max-width: 768px) {
    .table { font-size: 0.73rem; }
    .table th, .table td { padding: 0.3rem 0.25rem; }
    .btn-sm { font-size: 0.65rem; padding: 0.18rem 0.35rem; }
    .col-waktu { font-size: 0.7rem; }
}

@media (max-width: 576px) {
    .table { font-size: 0.70rem; }
    .table th, .table td { padding: 0.25rem 0.2rem; }
    .btn-sm { font-size: 0.62rem; padding: 0.15rem 0.3rem; }

    /* Lebar kolom super ketat */
    .col-no { width: 30px; }
    .col-agenda { width: 70px; }
    .col-surat { width: 80px; }
    .col-dari { width: 90px; }
    .col-perihal { width: 110px; }
    .col-waktu { width: 100px; }
    .col-petugas { width: 50px; }
    .col-file { width: 60px; }
    .col-aksi { width: 110px; }

    /* Sembunyikan kolom kurang penting di HP */
    .col-petugas, .col-waktu { font-size: 0.65rem; }
    .d-none-mobile { display: none !important; }
}

/* Waktu & Lokasi: Lebih rapat */
.col-waktu small {
    display: block;
    line-height: 1.3;
    font-size: 0.9em;
}

/* Focus & Active */
.btn:focus, .btn:active {
    outline: none !important;
    box-shadow: none !important;
}

/* Empty state */
.empty-state {
    padding: 3rem 1rem;
    text-align: center;
    color: #6c757d;
}
.empty-state i {
    font-size: 2.5rem;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}
.btn-bagikan-wa {
  background-color: #25D366 !important; /* hijau WhatsApp */
  color: white !important;
  border: none !important;
}
.btn-bagikan-wa:hover {
  background-color: #fff !important;
  color: #25D366 !important; /* hijau WhatsApp */
}
.btn-oke {
  background-color: #007bff !important;
  color: white !important;
}
.btn-oke:hover {
  color: #007bff !important;
  background-color: white !important;
}
</style>
<style>
#kegiatanSnapshot {
  background: #ffffff;
  color: #000;
  font-family: "Segoe UI", Arial, sans-serif;
  padding: 24px;
  width: 600px;
  border-radius: 16px;
  box-shadow: 0 0 10px rgba(0,0,0,0.1);
}
#kegiatanSnapshot h2 {
  text-align: center;
  margin-bottom: 16px;
  color: #333;
}
#kegiatanSnapshot ul {
  list-style: none;
  padding: 0;
  margin: 0;
}
#kegiatanSnapshot li {
  border: 1px solid #ddd;
  border-radius: 10px;
  padding: 10px 14px;
  margin-bottom: 12px;
  background: #fafafa;
}
#kegiatanSnapshot strong {
  color: #222;
  font-size: 15px;
}
#kegiatanSnapshot small {
  color: #555;
  font-size: 13px;
}
  .card-header {
            background: linear-gradient(135deg, #198754, #146c43);
            color: white;
            padding: 1.25rem 1.5rem;
        }
  .table th {
            /*background: #871919;*/
          	background: var(--merah-makassar); 
            font-weight: 500;
          color: white;
        }
</style>
</head>
<body>
<div class="content-wrapper">
<?php if (file_exists('helper/sidebar.php')) include 'helper/sidebar.php'; ?>
<main class="container-fluid my-5">
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
<h4 class="mb-0"><i class="fa fa-envelope-open-text"></i> Surat Masuk dan Undangan</h4>
</div>
<div class="card-body p-3">
<div class="m-3">
<button class="btn btn-success btn-sm shadow-sm" id="btnTambah">
<i class="fas fa-plus me-1"></i> Tambah Surat
</button>
</div>
  
<div class="table-responsive">    
    <table id="tabelDisposisi" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr>
                <th class="col-agenda">Tgl Surat</th>
              	<th class="col-surat">No Agenda</th>
                <th class="col-surat">No Surat</th>
                <th class="col-dari">Dari</th>
                <th class="col-perihal">Perihal</th>
                <th class="col-waktu">Waktu dan Lokasi</th>
                <th class="text-center col-file">Surat | Disposisi</th>
                <th class="text-center col-aksi">Aksi</th>
                <th class="col-petugas">Petugas</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="9" class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <div>Belum ada data disposisi</div>
                    </td>
                </tr>
            <?php else: $no = 1; foreach ($rows as $r): ?>
                <tr>
                    <td class="col-agenda">
                        <?= !empty($r['tanggal_surat']) ? formatTanggalIndo($r['tanggal_surat']) : '-' ?>
                        </td>

                  <td class="col-surat"><?=h($r['no_agenda'] ?? '-')?></td>
                  	<td class="col-surat"><?=h($r['no_surat'] ?? '-')?></td>
                    <td class="col-dari">
                        <span data-bs-toggle="tooltip" title="<?=h($r['dari'])?>">
                            <?=h(mb_strimwidth($r['dari'], 0, 20, '...'))?>
                        </span>
                    </td>
                    <td class="col-perihal">
                        <span data-bs-toggle="tooltip" title="<?=h($r['perihal'])?>">
                            <?=h(mb_strimwidth($r['perihal'] ?? '-', 0, 30, '...'))?>
                        </span>
                    </td>
                    <td class="col-waktu">
                        <small>
                            <?php
                                $hari = h($r['hari'] ?? '-');
                                $tgl = !empty($r['tanggal_kegiatan']) ? date('d M Y', strtotime($r['tanggal_kegiatan'])) : '-';
                                $jam = h($r['jam'] ?? '-');
                                $tempat = h($r['tempat'] ?? '-');

                                // Hilangkan detik kalau formatnya HH:MM:SS
                                if (!empty($jam) && $jam !== '-') {
                                    $jam = preg_replace('/:\d{2}$/', '', $jam); // hapus detik
                                } else {
                                    $jam = ''; // kosongkan kalau tidak ada jam
                                }

                                echo "$hari, $tgl<br>" .
                                    ($jam ? "$jam WITA<br>" : '') .
                                    "<em>$tempat</em>";
                            ?>
                        </small>
                    </td>

                    <td class="text-center col-file">
                        <?php if ($r['file_srikandi'] && file_exists($r['file_srikandi'])):
                            $ext = strtolower(pathinfo($r['file_srikandi'], PATHINFO_EXTENSION));
                            $icon = in_array($ext, ['jpg','jpeg','png','gif']) ? 'fa-file-image' : 'fa-file-pdf';
                        ?>
                            <a href="<?=h($r['file_srikandi'])?>" target="_blank" class="btn btn-sm btn-outline-info" data-bs-toggle="tooltip" title="Lihat Surat">
                                <i class="fas <?=$icon?>"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                        <span class="text-muted mx-1">|</span>
                        <?php if ($r['file_disposisi'] && file_exists($r['file_disposisi'])): ?>
                            <a href="<?=h($r['file_disposisi'])?>" target="_blank" class="btn btn-sm btn-outline-warning" data-bs-toggle="tooltip" title="Lihat Disposisi">
                                <i class="fas fa-file-alt"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center col-aksi">
                        <div class="btn-group" role="group">
                            <button class="btn btn-sm btn-outline-primary lihat-btn" data-id="<?=$r['id']?>" data-bs-toggle="tooltip" title="Lihat Detail">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($canEdit): ?>
                                <button class="btn btn-sm btn-outline-primary edit-btn" data-id="<?=$r['id']?>" data-bs-toggle="tooltip" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled  data-bs-toggle="tooltip" title="Edit Tidak Diizinkan">
                                    <i class="fas fa-edit"></i>
                                </button>
                            <?php endif; ?>                          
                          <?php if ($canEdit): ?>
                                <a href="disposisi-cetak.php?id=<?=$r['id']?>" target="_blank" class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="Cetak">
                                <i class="fas fa-file-pdf"></i>
                            	</a>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled data-bs-toggle="tooltip" title="Cetak Tidak Diizinkan">
                                    <i class="fas fa-file-pdf"></i>
                                </button>
                            <?php endif; ?>                           
                            
                                  <?php if ($canEdit): ?>
                                <button class="btn btn-sm btn-outline-success wa-btn" data-id="<?=$r['id']?>" data-bs-toggle="tooltip" title="Bagikan WA">
                                <i class="fab fa-whatsapp"></i>
                            </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled data-bs-toggle="tooltip" title="Bagikan WA Tidak Diizinkan!!!">
                                <i class="fab fa-whatsapp"></i>
                                  </button>
                            <?php endif; ?>
                            
                            <?php if ($canEdit): ?>
                                <button class="btn btn-sm btn-outline-warning upload-btn" data-id="<?=$r['id']?>" data-bs-toggle="tooltip" title="Upload Disposisi">
                                	<i class="fas fa-upload"></i>
                            	</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled data-bs-toggle="tooltip" title="Upload Disposisi Tidak Diizinkan!!!">
                                <i class="fas fa-upload"></i>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <button class="btn btn-sm btn-outline-danger hapus-btn" data-id="<?=$r['id']?>" data-bs-toggle="tooltip" title="Hapus Surat">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled data-bs-toggle="tooltip" title="Hapus Tidak Diizinkan!!!">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="col-petugas">
                        <span data-bs-toggle="tooltip" title="<?=h($r['nama_petugas'] ?? $r['petugas_surat'] ?? '-')?>">
                            <?=h(mb_strimwidth($r['nama_petugas'] ?? $r['petugas_surat'] ?? '-', 0, 6, '...'))?>
                        </span>

                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</div>
</div>
</main>
<?php if (file_exists('helper/footer.php')) include 'helper/footer.php'; ?>
</div>

<!-- MODAL FORM -->
<div class="modal fade" id="modalForm" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form id="formData" class="modal-content" enctype="multipart/form-data">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="formTitle">Tambah Surat</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <!-- Hidden fields -->
        <input type="hidden" name="action" id="formAction" value="tambah">
        <input type="hidden" name="id" id="formId">
        <input type="hidden" name="is_surat_edaran" id="hidden_surat_edaran" value="">

        <div class="row g-3">

          <!-- =======================
               A. INFORMASI SURAT
          ======================== -->
          <div class="col-12">
            <h6 class="fw-bold text-danger border-bottom pb-1">A. Informasi Surat</h6>
          </div>

          <div class="col-md-6">
            <label>Tanggal Diterima <span class="text-danger">*</span></label>
            <input type="date" name="tgl_diterima" id="tgl_diterima" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label>Tanggal Surat</label>
            <input type="date" name="tgl_surat" id="tgl_surat" class="form-control">
          </div>

          <div class="col-md-6">
            <label>No. Agenda</label>
            <input type="text" readonly name="no_agenda" id="no_agenda" class="form-control" value="<?php echo $previewNoAgenda; ?>">
          </div>
          <div class="col-md-6">
            <label>No. Surat</label>
            <input type="text" name="no_surat" id="no_surat" class="form-control" value="-">
          </div>

          <div class="col-md-6">
            <label>Dari</label>
            <textarea name="dari" id="dari" rows="4" class="form-control">-</textarea>
          </div>
          <div class="col-md-6">
            <label>Keterangan</label>
            <textarea name="keterangan" id="keterangan" rows="4" class="form-control">-</textarea>
          </div>

          <div class="col-md-12">
            <label>Perihal <span class="text-danger">*</span></label>
            <textarea name="perihal" id="perihal" rows="5" class="form-control" required>-</textarea>
          </div>


          <!-- =======================
               B. INFORMASI KEGIATAN
          ======================== -->
          <div class="col-12 mt-4">
            <h6 class="fw-bold text-danger border-bottom pb-1">B. Informasi Kegiatan</h6>
          </div>
          
          <div class="col-md-12">
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" id="is_surat_edaran">
               <label class="form-check-label text-danger" for="is_surat_edaran">
                (centang jika surat edaran, pemberitahuan dll)
              </label>
            </div>
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" id="is_hilangkan" name="tampil" value="1">
                <label class="form-check-label text-danger" for="is_hilangkan">
                    (centang jika ingin menampilkan di surat edaran)
                </label>
            </div>
          </div>

          <div class="col-md-4">
            <label>Tgl Kegiatan</label>
            <input type="date" name="tgl_kegiatan" id="tgl_kegiatan" class="form-control">
          </div>
          <div class="col-md-4">
            <label>Hari</label>
            <select name="hari" id="hari" class="form-select">
              <option value="">Pilih</option>
              <option>Senin</option>
              <option>Selasa</option>
              <option>Rabu</option>
              <option>Kamis</option>
              <option>Jumat</option>
              <option>Sabtu</option>
              <option>Minggu</option>
            </select>
          </div>
          <div class="col-md-4">
            <label>Jam</label>
            <input type="time" name="jam" id="jam" class="form-control">
          </div>
          <div class="col-md-12">
            <label>Lokasi Kegiatan</label>
                <textarea name="tempat" id="tempat" rows="3" class="form-control" placeholder="Contoh: Aula Kecamatan, Kantor Camat, Lapangan Upacara, hotel ....">-</textarea>
        </div>

          <div class="col-md-6">
            <label>Pakaian</label>
            <textarea name="pakaian" id="pakaian" rows="3" placeholder="Batik, PDH" class="form-control">Sesuai yang berlaku hari ini</textarea>
          </div>
          <div class="col-md-6">
            <label>Pejabat Pendamping</label>
            <textarea name="pejabat_pendamping" id="pejabat_pendamping" rows="3" class="form-control">-</textarea>
          </div>

          <!-- =======================
               C. LAMPIRAN & SUMBER
          ======================== -->
          <div class="col-12 mt-4">
            <h6 class="fw-bold text-danger border-bottom pb-1">C. Lampiran & Sumber Surat</h6>
          </div>

          <div class="col-md-12">
            <label>Sumber Surat <span class="text-danger">*</span></label>
            <select name="sumber_surat" id="sumber_surat" class="form-select" required>
              <option value="fisik">Fisik</option>
              <option value="srikandi">Srikandi</option>
            </select>
          </div>

          <div class="col-md-6">
            <label>Surat [Fisik/Srikandi] (PDF/Gambar)</label>
            <input type="file" name="file_srikandi" id="file_srikandi" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            <div id="filePreview" class="mt-2"></div>
          </div>

          <div class="col-md-6">
            <label>Disposisi (PDF/Gambar) <small class="text-muted">(Opsional)</small></label>
            <?php if ($canEdit): ?>
              <input type="file" name="file_disposisi" id="file_disposisi" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            <?php else: ?>
              <input type="file" name="file_disposisi" id="file_disposisi" class="form-control" accept=".pdf,.jpg,.jpeg,.png" disabled>
            <?php endif; ?>
            <div id="fileDisposisiPreview" class="mt-2"></div>
          </div>

        </div><!-- /.row -->
      </div><!-- /.modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary" id="btnSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL UPLOAD -->
<div class="modal fade" id="modalUploadDisposisi" tabindex="-1">
<div class="modal-dialog">
<form id="formUploadDisposisi" class="modal-content" enctype="multipart/form-data">
<div class="modal-header bg-warning text-dark">
<h5 class="modal-title">Upload File Disposisi</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="action" value="upload_disposisi">
<input type="hidden" name="id" id="uploadId">
<div class="mb-2"><strong>Perihal:</strong> <span id="uploadPerihal">-</span></div>
<div class="mb-2"><strong>Pakaian:</strong> <span id="uploadPakaian">-</span></div>
<div class="mb-2"><strong>Pendamping:</strong> <span id="uploadPendamping">-</span></div>
<label>File <span class="text-danger">*</span></label>
<input type="file" name="file_disposisi" id="fileDisposisiInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
<div id="uploadPreview" class="mt-2"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
    <button type="submit" class="btn btn-primary">Upload</button>
</div>
</form>
</div>
</div>


<div id="kegiatanSnapshot" style="display:none;"></div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>



<script>
let table = null;
const hariIndo = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  

// === HELPER ===
function h(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

async function ajaxPost(fd) {
    const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
    if (!res.ok) throw new Error(`Server Error ${res.status}`);
    const text = await res.text();
    if (!text.trim()) throw new Error('Server kosong');
    try { return JSON.parse(text); } catch (e) { throw new Error('JSON error: ' + text.substring(0, 200)); }
}
  
  function initToolasdastips() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        if (el._bootstrapTooltip) el._bootstrapTooltip.dispose();
    });

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        const tooltip = new bootstrap.Tooltip(el, {
            placement: 'top',
            trigger: 'hover',
            container: 'body',
            delay: { show: 80, hide: 40 },
            boundary: 'viewport',
            fallbackPlacements: [],
            popperConfig: (defaultBsPopperConfig) => ({
                ...defaultBsPopperConfig,
                strategy: 'fixed',
                modifiers: [
                    {
                        name: 'offset',
                        options: { offset: [0, -8] } // ↓ turunkan tooltip (negatif = turun)
                    },
                    { name: 'flip', enabled: false },
                    { name: 'preventOverflow', options: { boundary: 'viewport' } },
                    { name: 'arrow', options: { padding: 1 } }
                ]
            })
        });
        el._bootstrapTooltip = tooltip;
    });
}

  function initToolasdasdtips() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        if (el._bootstrapTooltip) el._bootstrapTooltip.dispose();
    });

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        const tooltip = new bootstrap.Tooltip(el, {
            placement: 'top',
            trigger: 'hover',
            container: 'body',
            delay: { show: 80, hide: 40 },
            boundary: 'viewport',
            fallbackPlacements: [],
            popperConfig: (defaultBsPopperConfig) => ({
                ...defaultBsPopperConfig,
                strategy: 'fixed',
                modifiers: [
                    {
                        name: 'offset',
                        // negatif sedikit supaya tooltip turun dikit
                        options: { offset: [0, -2] } // dari -1 sampai -4 ideal
                    },
                    { name: 'flip', enabled: false },
                    { name: 'preventOverflow', options: { boundary: 'viewport' } },
                    { name: 'arrow', options: { padding: 2 } }
                ]
            })
        });
        el._bootstrapTooltip = tooltip;
    });
}

  function initTooltips() {
    // Hancurkan semua tooltip lama
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        if (el._bootstrapTooltip) el._bootstrapTooltip.dispose();
    });

    // Inisialisasi ulang tooltip
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        const tooltip = new bootstrap.Tooltip(el, {
            placement: 'bottom',
            trigger: 'hover',
            container: 'body', // penting! supaya tidak terpengaruh parent overflow
            delay: { show: 80, hide: 40 },
            boundary: 'viewport',
            fallbackPlacements: [], // jangan fallback ke bawah
            popperConfig: (defaultBsPopperConfig) => ({
                ...defaultBsPopperConfig,
                strategy: 'fixed', // biar posisi tidak berubah saat scroll
                modifiers: [
                    {
                        name: 'offset',
                        options: { offset: [0, -5] }
                    },
                    {
                        name: 'preventOverflow',
                        options: { boundary: 'viewport' }
                    },
                    {
                        name: 'flip',
                        enabled: false // tidak pindah ke bawah
                    }
                ]
            })
        });

        // simpan referensi biar bisa dispose nanti
        el._bootstrapTooltip = tooltip;
    });
}

function adjustHeader2(tableInstance) {
    if (!tableInstance) return;

    const $container = $(tableInstance.table().container());
    const $scrollBody = $container.find('.dataTables_scrollBody');
    const $scrollHead = $container.find('.dataTables_scrollHead');

    // Langkah 1: Adjust kolom (wajib!)
    tableInstance.columns.adjust().responsive.recalc();

    // Langkah 2: Sesuaikan lebar header dengan body
    setTimeout(() => {
        const bodyWidth = $scrollBody[0].clientWidth;
        const headTable = $scrollHead.find('table')[0];
        if (headTable && bodyWidth > 0) {
    headTable.style.width = bodyWidth + 'px';  // ← ERROR DI SINI!
}
    }, 50);
}
  
  function adjustHeaderbkp2(tableInstance) {
    if (!tableInstance) return;

    const $container = $(tableInstance.table().container());
    const $scrollBody = $container.find('.dataTables_scrollBody');
    const $scrollHead = $container.find('.dataTables_scrollHead');

    // Adjust kolom aman (tanpa error recalc)
    tableInstance.columns.adjust();
    if (tableInstance.responsive) {
        tableInstance.responsive.recalc();
    }

    setTimeout(() => {
        const bodyWidth = $scrollBody[0]?.clientWidth || 0;
        const headTable = $scrollHead.find('table')[0];
        if (headTable && bodyWidth > 0) {
            headTable.style.width = bodyWidth + 'px';
        }
    }, 50);
}

function adjustHeader(api) {
    if (!api) return;
    const $container = $(api.table().container());
    const $scrollHead = $container.find('.dataTables_scrollHead table');
    const $scrollBody = $container.find('.dataTables_scrollBody');

    if ($scrollHead.length === 0 || $scrollBody.length === 0) return;

    api.columns.adjust();

    // Gunakan requestAnimationFrame agar DOM stabil
    requestAnimationFrame(() => {
        const bodyWidth = $scrollBody[0].scrollWidth;
        $scrollHead.css('width', bodyWidth + 'px');
    });
}
        function adjustHeadererer(tableInstance) {
    if (!tableInstance) return;

    // Pastikan tabel sudah di-render
    const $container = $(tableInstance.table().container());
    const $scrollHead = $container.find('.dataTables_scrollHead table');
    const $scrollBody = $container.find('.dataTables_scrollBody');

    if ($scrollHead.length === 0 || $scrollBody.length === 0) {
        //console.warn('DataTables header/body belum siap, skip adjust');
        return;
    }

    // Adjust kolom dulu
    tableInstance.columns.adjust();
    if (tableInstance.responsive) {
        tableInstance.responsive.recalc();
    }

    // Gunakan requestAnimationFrame agar DOM sudah stabil
    requestAnimationFrame(() => {
        const bodyWidth = $scrollBody[0].scrollWidth || $scrollBody[0].clientWidth;
        const headTable = $scrollHead[0];

        if (headTable && bodyWidth > 0) {
            headTable.style.width = bodyWidth + 'px';
            headTable.style.tableLayout = 'fixed'; // tambahkan ini
        }
    });
}
        
    


// === FUNGSI UTAMA ===
window.lihat = async function(id) {
    try {
        const fd = new FormData(); fd.append('action', 'get'); fd.append('id', id);
        const json = await ajaxPost(fd);
        if (!json.success) throw new Error(json.message);
        const d = json.data;
        // const base = window.location.origin + window.location.pathname.replace('disposisi.php', '');
        const currentPath = window.location.pathname;
const base = window.location.origin + currentPath.substring(0, currentPath.lastIndexOf('/') + 1);
        const tglSurat = d.tanggal_surat ? new Date(d.tanggal_surat).toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) : '-';
        const tglTerima = d.tanggal_diterima ? new Date(d.tanggal_diterima).toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) : '-';
        const tglKegiatan = d.tanggal_kegiatan ? new Date(d.tanggal_kegiatan).toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) : '-';
        const fileSrikandi = d.file_srikandi ? `<a href="${base}${d.file_srikandi}" target="_blank" class="btn btn-sm btn-info">Lihat</a>` : '-';
        const fileDisposisi = d.file_disposisi ? `<a href="${base}${d.file_disposisi}" target="_blank" class="btn btn-sm btn-warning">Lihat</a>` : '-';
        Swal.fire({
          title: 'Detail Surat',
          icon: 'info',
          html: `
            <div class="text-start container-fluid py-1">
                <div class="row mb-0">
                <div class="col-5 fw-bold">No Agenda</div>
                <div class="col-7">: ${h(d.no_agenda || '-')}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Tgl Diterima</div>
                <div class="col-7">: ${tglTerima}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Tgl Surat</div>
                <div class="col-7">: ${tglSurat}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">No Surat</div>
                <div class="col-7">: ${h(d.no_surat || '-')}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Dari</div>
                <div class="col-7">: ${h(d.dari || '-')}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Perihal</div>
                <div class="col-7">: ${h(d.perihal || '-')}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Tgl Kegiatan</div>
                <div class="col-7">: ${tglKegiatan}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Jam</div>
                <div class="col-7">
                : ${d.jam && d.jam !== '-' 
                    ? h(d.jam.replace(/:\d{2}$/, '')) + ' WITA' 
                    : '-'}
                </div>

                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Tempat</div>
                <div class="col-7">: ${h(d.tempat || '-')}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Pakaian</div>
                <div class="col-7">: ${h(d.pakaian || '-')}</div>
                </div>
                <div class="row mb-0">
                <div class="col-5 fw-bold">Pendamping</div>
                <div class="col-7">: ${h(d.pejabat_pendamping || '-')}</div>
                </div>
                <div class="row mb-1">
                <div class="col-5 fw-bold">Keterangan</div>
                <div class="col-7">: ${h(d.keterangan || '-')}</div>
                </div>
                <hr class="my-1">
                <div class="row">
                <div class="col-6">
                    <small class="fw-bold">File Surat:</small><br>${fileSrikandi}
                </div>
                <div class="col-6">
                    <small class="fw-bold">File Disposisi:</small><br>${fileDisposisi}
                </div>
                </div>
            </div>
            `,
          showCloseButton: true,
          width: '700px'
        });

    } catch (err) { Swal.fire('Error', err.message, 'error'); }
};

window.edit = async function(id) {
    try {
        const fd = new FormData(); fd.append('action', 'get'); fd.append('id', id);
        const json = await ajaxPost(fd);
        if (!json.success) throw new Error(json.message);
        const d = json.data;
        $('#formTitle').text('Edit Surat');
        $('#formAction').val('update');
        $('#formId').val(d.id);
        $('#tgl_diterima').val(d.tanggal_diterima || '');
        $('#tgl_surat').val(d.tanggal_surat || '');
        $('#tgl_kegiatan').val(d.tanggal_kegiatan || '');
        $('#no_agenda').val(d.no_agenda || '');
        $('#no_surat').val(d.no_surat || '');
        $('#dari').val(d.dari || '');
        $('#perihal').val(d.perihal || '');
        $('#hari').val(d.hari || '');
        $('#jam').val(d.jam || '');
        $('#tempat').val(d.tempat || '');
        $('#pakaian').val(d.pakaian || '');
        $('#pejabat_pendamping').val(d.pejabat_pendamping || '');
        $('#keterangan').val(d.keterangan || '');
        $('#filePreview').html(d.file_srikandi ? `<small class="text-success">File: ${d.file_srikandi.split('/').pop()}</small>` : '');
        $('#fileDisposisiPreview').html(d.file_disposisi ? `<small class="text-warning">File: ${d.file_disposisi.split('/').pop()}</small>` : '');
        new bootstrap.Modal('#modalForm').show();
    } catch (err) { Swal.fire('Error', err.message, 'error'); }
};

window.uploadDisposisi = async function(id) {
    try {
        const fd = new FormData(); fd.append('action', 'get'); fd.append('id', id);
        const json = await ajaxPost(fd);
        if (!json.success) throw new Error(json.message);
        const d = json.data;
        $('#uploadId').val(d.id);
        $('#uploadPerihal').text(d.perihal || '-');
        $('#uploadPakaian').text(d.pakaian || '-');
        $('#uploadPendamping').text(d.pejabat_pendamping || '-');
        $('#uploadPreview').html('');
        $('#fileDisposisiInput').val('');
        new bootstrap.Modal('#modalUploadDisposisi').show();
    } catch (err) { Swal.fire('Error', err.message, 'error'); }
};

window.hapus = async function(id) {
    const res = await Swal.fire({ title: 'Hapus data?', icon: 'warning', showCancelButton: true });
    if (res.isConfirmed) {
        try {
            const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
            const json = await ajaxPost(fd);
            if (json.success) location.reload();
        } catch (err) { Swal.fire('Error', err.message, 'error'); }
    }
};

window.bagikanWA = async function(id) {
    try {
        const fd = new FormData(); fd.append('action', 'get'); fd.append('id', id);
        const json = await ajaxPost(fd);
        if (!json.success) throw new Error('Gagal ambil data');
        const d = json.data;
        //const base = window.location.origin + window.location.pathname.replace('disposisi.php', '');
        const currentPath = window.location.pathname;
const base = window.location.origin + currentPath.substring(0, currentPath.lastIndexOf('/') + 1);
        const tgl = new Date(d.tanggal_kegiatan || Date.now());
        const hari = d.hari || hariIndo[tgl.getDay()];
        const tglFormat = tgl.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
        //const jam = d.jam ? d.jam.slice(0,5) + ':00' : '-';
        const jam = d.jam && d.jam !== '-' 
        ? d.jam.replace(/:\d{2}$/, '') + ' WITA' 
        : '-';
        const linkS = d.file_srikandi ? `${base}${d.file_srikandi}` : '-';
        const linkD = d.file_disposisi ? `${base}${d.file_disposisi}` : '-';
        const text = `*JADWAL KEGIATAN PEJABAT KECAMATAN TALLO*\n\n` +
            `Hari, Tanggal : *${tglFormat}*\n` +
            `Pukul : *${jam}*\n` +
            `Kegiatan : *${d.perihal || '-'}*\n` +
            `Tempat/ Lokasi : *${d.tempat || '-'}*\n` +
            `Pakaian : *${d.pakaian || '-'}*\n` +
            `Pejabat Pendamping/ Pejabat yang Mewakili : \n${d.pejabat_pendamping || '-'}\n` +
            `Pejabat: Camat Tallo\n` +
            `Keterangan : *${d.keterangan || '-'}*\n` +            
            `Diagendakan : -\n\n` +
            `*Lampiran:*\n• Surat: ${linkS}\n• Disposisi: ${linkD}`;
        window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
    } catch (err) { Swal.fire('Error', err.message, 'error'); }
};

// === EVENT DELEGATION PAKAI CLASS ANDA (lihat-btn, edit-btn, dll) ===
$(document).on('click', '.lihat-btn', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const id = $(this).data('id');
    lihat(id);
});

$(document).on('click', '.edit-btn', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const id = $(this).data('id');
    edit(id);
});

$(document).on('click', '.upload-btn', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const id = $(this).data('id');
    uploadDisposisi(id);
});

$(document).on('click', '.hapus-btn', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const id = $(this).data('id');
    hapus(id);
});

$(document).on('click', '.wa-btn', function(e) {
    e.preventDefault();
    e.stopPropagation();
    const id = $(this).data('id');
    bagikanWA(id);
});

// === DATATABLES INIT ===
$(document).ready(function() {
    table = $('#tabelDisposisi').DataTable({
        dom: 'frtip', // B dihilangkan juga
        paging: true,
        pageLength: 10,
        scrollX: true,
        responsive: true,
        ordering: false,

        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
        },

        initComplete: function() {
            adjustHeader(this.api());
            initTooltips();
        },
        drawCallback: function() {
            adjustHeader(this.api());
            initTooltips();
        }

});

    // ✅ Pastikan header disesuaikan setelah tabel siap
function generateNextAgendaNumber(lastNumber) {
    return lastNumber + 1;
}

    // === TOMBOL TAMBAH ===
   $('#btnTambah').on('click', function() {
    const today = new Date().toISOString().split('T')[0];
    const yesterday = new Date(Date.now()-86400000).toISOString().split('T')[0];

    $('#formTitle').text('Tambah Surat');
    $('#formAction').val('tambah');
    $('#formId').val('');
    $('#formData')[0].reset();

    // Reset semua field
    $('#tgl_diterima').val(today);
    $('#tgl_surat').val(yesterday);
    $('#tgl_kegiatan').val('');
    $('#hari').val('');
    $('#jam').val('');
    $('#filePreview, #fileDisposisiPreview').empty();
    $('#is_surat_edaran').prop('checked', false);
//    const lastNumber = <?= $lastAgendaNum ?>; // dari PHP
//    $('#no_agenda').val(generateNextAgendaNumber(lastNumber));

    // AMBIL AKSES DARI PHP (SUDAH DI ATAS!)
        const akses = '<?= htmlspecialchars($user['akses'] ?? "", ENT_QUOTES) ?>';
        $('#sumber_surat').val(
            akses === 'PENGADMINISTRASI' ? 'fisik' :
            akses === 'LOKET' ? 'fisik' : 'fisik'
        );
        

    // Pastikan field aktif dulu
    $('#tgl_kegiatan, #hari, #jam').prop('disabled', false);

    new bootstrap.Modal('#modalForm').show();
});

    $('#tgl_kegiatan').on('change', function() {
        const date = new Date(this.value);
        $('#hari').val(hariIndo[date.getDay()]);
    });

    $(document).on('change', '#is_surat_edaran', function() {
    $('#hidden_surat_edaran').val(this.checked ? '1' : '');
});

    // === KONTROL SURAT EDARAN ===
$(document).on('change', '#is_surat_edaran', function() {
    const isChecked = $(this).is(':checked');
    const $fields = $('#tgl_kegiatan, #hari, #jam');

    if (isChecked) {
        $fields.val('').prop('disabled', true);
    } else {
        $fields.prop('disabled', false);
        // Otomatis isi hari jika tanggal diisi
        const tgl = $('#tgl_kegiatan').val();
        if (tgl) {
            const date = new Date(tgl);
            $('#hari').val(hariIndo[date.getDay()]);
        }
    }
});

// Saat edit, cek apakah tanggal kosong → centang otomatis
$(document).on('shown.bs.modal', '#modalForm', function() {
    const tgl = $('#tgl_kegiatan').val();
    const hari = $('#hari').val();
    const jam = $('#jam').val();

    if (!tgl && !hari && !jam) {
        $('#is_surat_edaran').prop('checked', true);
        $('#tgl_kegiatan, #hari, #jam').prop('disabled', true);
    }
});

    ['file_srikandi', 'file_disposisi', 'fileDisposisiInput'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', function() {
            const file = this.files[0];
            const previewId = this.id === 'file_srikandi' ? 'filePreview' :
                            this.id === 'fileDisposisiInput' ? 'uploadPreview' : 'fileDisposisiPreview';
            const preview = document.getElementById(previewId);
            preview.innerHTML = '';
            if (file) {
                if (file.type === 'application/pdf') {
                    preview.innerHTML = `<small class="text-success">PDF: ${file.name}</small>`;
                } else if (file.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.classList.add('img-fluid', 'rounded', 'border');
                    img.style.maxHeight = '100px';
                    preview.appendChild(img);
                }
            }
        });
    });

    $('#formData').on('submit', async function(e) {
    e.preventDefault();
    const sumber = $('#sumber_surat').val();
    const fileSrikandi = $('#file_srikandi')[0].files[0];

    // if (sumber === 'srikandi' && !fileSrikandi) {
    //     Swal.fire('Error', 'File Srikandi wajib diunggah!', 'error');
    //     return;
    // }

    // Lanjutkan submit
    const btn = $('#btnSubmit');
    btn.prop('disabled', true).html('Menyimpan...');
    try {
        const data = await ajaxPost(new FormData(this));
        if (data.success) {
            Swal.fire('Sukses', data.message, 'success').then(() => location.reload());
        } else {
            Swal.fire('Gagal', data.message, 'error');
        }
    } catch (err) {
        Swal.fire('Error', err.message, 'error');
    } finally {
        btn.prop('disabled', false).html('Simpan');
    }
});

    // $('#formData').on('submit', async function(e) {
    //     e.preventDefault();
    //     const btn = $('#btnSubmit');
    //     btn.prop('disabled', true).html('Menyimpan...');
    //     try {
    //         const data = await ajaxPost(new FormData(this));
    //         if (data.success) {
    //             Swal.fire('Sukses', data.message, 'success').then(() => location.reload());
    //         } else {
    //             Swal.fire('Gagal', data.message, 'error');
    //         }
    //     } catch (err) {
    //         Swal.fire('Error', err.message, 'error');
    //     } finally {
    //         btn.prop('disabled', false).html('Simpan');
    //     }
    // });

    $('#formUploadDisposisi').on('submit', async function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('Upload...');
        try {
            const data = await ajaxPost(new FormData(this));
            if (data.success) {
                Swal.fire('Sukses', data.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Gagal', data.message, 'error');
            }
        } catch (err) {
            Swal.fire('Error', err.message, 'error');
        } finally {
            btn.prop('disabled', false).html('Upload');
        }
    });
/*
    // === KEGIATAN HARI INI ===
   const kegiatan = <?= json_encode($kegiatanHariIni) ?>;
const todayText = '<?= $tanggalLengkap ?>';
const base = window.location.origin + window.location.pathname.replace('disposisi.php', '');

if (kegiatan.length > 0) {

    // --- bagian atas: tulis jumlah kegiatan lebih dulu ---
    let htmlSwal = `
      <div style="margin-bottom:10px; text-align:right; font-weight:600; color:#00695c;">
        Jumlah kegiatan hari ini: ${kegiatan.length}
      </div>
      <ul class="text-start mb-0" style="padding-left:1.2rem;">`;

    let htmlSnapshot = '<ul>';

    kegiatan.forEach(k => {
        const tgl = new Date(k.tanggal_kegiatan || '<?=date('Y-m-d')?>');
        const hari = k.hari || hariIndo[tgl.getDay()];
        const tglFormatlagi = tgl.toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' });
        const jam = k.jam && k.jam !== '-' ? k.jam.replace(/:\d{2}$/, '') + ' WITA' : '-';
        const linkS = k.file_srikandi ? `${base}${k.file_srikandi}` : '-';
        const linkD = k.file_disposisi ? `${base}${k.file_disposisi}` : '-';

        const waText = `*JADWAL KEGIATAN PEJABAT KECAMATAN TALLO*\n\n` +
          `Hari, Tanggal : *${hari}, ${tglFormatlagi}*\n` +
          `Pukul : *${jam}*\n` +
          `Kegiatan : *${k.perihal || '-'}*\n` +
          `Tempat/ Lokasi : *${k.tempat || '-'}*\n` +
          `Pakaian : *${k.pakaian || '-'}*\n` +
          `Pejabat Pendamping/ Pejabat yang Mewakili : \n${k.pejabat_pendamping || '-'}\n` +
          `Pejabat : Camat Tallo\n` +
          `Keterangan : *${k.keterangan || '-'}*\n` +
          `Diagendakan : -\n\n` +
          `*Lampiran:*\n• Surat: ${linkS}\n• Disposisi: ${linkD}`;
        const waUrl = `https://wa.me/?text=${encodeURIComponent(waText)}`;

        htmlSwal += `
        <li class="border-bottom pb-3 mb-3">
            <strong>${k.perihal || '-'}</strong><br>
            <small>
                <i class="fas fa-calendar-day text-primary"></i> ${hari}, ${tglFormatlagi}<br>
                <i class="fas fa-clock text-success"></i> ${jam && jam !== '-' ? jam.replace(/:\d{2}$/, '') : '-'}<br>
                <i class="fas fa-map-marker-alt text-danger"></i> ${k.tempat || '-'}<br>
                <i class="fas fa-tshirt text-warning"></i> ${k.pakaian || '-'}<br>
                <i class="fas fa-user-tie text-info"></i> ${k.pejabat_pendamping || '-'}
            </small>
            <div class="mt-2">
                <button class="btn btn-success btn-sm px-3 me-2" onclick="window.open('${waUrl}', '_blank')">
                    <i class="fab fa-whatsapp"></i> Bagikan ke WA!
                </button>
            </div>
        </li>`;

        htmlSnapshot += `
        <li>
            <strong>${k.perihal || '-'}</strong><br>
            <small>
                🗓️ ${hari}, ${tglFormatlagi}<br>
                ⏰ ${jam}<br>
                📍 ${k.tempat || '-'}<br>
                👔 ${k.pakaian || '-'}<br>
                👥 ${k.pejabat_pendamping || '-'}
            </small>
        </li>`;
    });

    htmlSwal += '</ul>';
    htmlSnapshot += '</ul>';

    document.getElementById('kegiatanSnapshot').innerHTML = `
      <div style="
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 12px;
        border-bottom: 2px solid #d32525ff;
      ">
        <img src="img/logo-mks.png" width="50" alt="Logo Makassar" style="height:auto;">
        <div style="text-align: center; flex: 1;">
          <h2 style="margin: 0; font-size: 18px;">Info Kegiatan Kecamatan Tallo</h2>
          <p style="margin: 0; font-size: 14px; font-weight: 500;">${todayText}</p>
        </div>
        <img src="img/logo-tallo.png" width="60" alt="Logo Tallo" style="height:auto;">
      </div>

      <div style="padding: 10px 16px;">
        ${htmlSnapshot}
      </div>
    `;

    Swal.fire({
      icon: 'info',
      title: `<strong>Kegiatan Hari Ini — ${todayText}</strong>`,
      html: htmlSwal,
      showConfirmButton: true,
      confirmButtonText: 'Oke',
      showDenyButton: true,
      denyButtonText: '<i class="fab fa-whatsapp"></i> Bagikan Seluruh Kegiatan',
      width: '720px',
      customClass: {
          confirmButton: 'btn-oke',
          denyButton: 'btn-bagikan-wa'
      }
    }).then(result => {
      if (result.isDenied) {
          const el = document.getElementById('kegiatanSnapshot');
          el.style.display = 'block';

          html2canvas(el, { scale: 2, backgroundColor: '#ffffff' }).then(canvas => {
              el.style.display = 'none';
              canvas.toBlob(async blob => {
                  const safeName = `Kegiatan-${todayText.replace(/\s+/g, '_')}`;
                  const file = new File([blob], safeName + '.png', { type: 'image/png' });

                  if (navigator.canShare && navigator.canShare({ files: [file] })) {
                      try {
                          await navigator.share({
                              files: [file],
                              title: 'Kegiatan Hari Ini',
                              text: `Berikut kegiatan Kecamatan Tallo (${todayText})`,
                          });
                      } catch (err) {
                          alert('Gagal membagikan: ' + err.message);
                      }
                  } else {
                      const link = document.createElement('a');
                      link.href = URL.createObjectURL(blob);
                      link.download = safeName + '.png';
                      link.click();
                      Swal.fire('Tidak bisa share langsung', 'Gambar sudah diunduh. Silakan kirim manual via WhatsApp.', 'info');
                  }
              });
          });
      }
    });

} else {
    Swal.fire({
        icon: 'success',
        title: `<strong>Hari Ini — ${todayText}</strong>`,
        html: `<div class="text-center py-3">
            <i class="fas fa-calendar-check fa-3x text-success mb-3"></i>
            <p class="mb-0"><strong>Tidak ada kegiatan terjadwal hari ini.</strong></p>
            <small class="text-muted">Nikmati hari dengan tenang!</small>
        </div>`,
        confirmButtonText: 'Oke...',
        width: '500px'
    });
}*/

$(window).on('resize', function() {
    if (table) {
        table.columns.adjust().draw(false);
    }
});

    });
</script>
</body>
</html>