<?php
require_once 'auth.php';
$u = require_login('transporter');

$types = [
    'national_id'        => 'National ID or passport',
    'driving_licence'    => 'Valid driving licence',
    'insurance'          => 'Valid vehicle insurance',
    'vehicle_inspection' => 'Approved, stamped vehicle inspection',
];
$needsExpiry = ['driving_licence', 'insurance', 'vehicle_inspection'];   // expiry date is compulsory for these
$dir = __DIR__ . '/storage/drivers/';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
    file_put_contents($dir . '.htaccess', "Require all denied\n");   // files can only be opened through view_document.php
}

$me = fetch_one('SELECT driver_status, driver_note FROM users WHERE id = ?', [$u['id']]);
$docs = [];
foreach (fetch_all('SELECT * FROM driver_documents WHERE user_id = ?', [$u['id']]) as $d) $docs[$d['doc_type']] = $d;
$canEdit = in_array($me['driver_status'], ['unverified', 'rejected'], true);
$today = date('Y-m-d');
$err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if (!$canEdit) {
        $err[] = 'Your documents cannot be changed right now.';
    } elseif ($action === 'upload') {
        $type = $_POST['doc_type'] ?? '';
        $f = $_FILES['file'] ?? null;
        $exp = trim($_POST['expiry_date'] ?? '');
        if (!isset($types[$type])) {
            $err[] = 'Unknown document type.';
        } elseif (in_array($type, $needsExpiry, true) && $exp === '') {
            $err[] = 'Enter the expiry date of the document.';
        } elseif ($exp !== '' && (!valid_date($exp) || $exp < $today)) {
            $err[] = 'The expiry date must be a valid date that has not passed.';
        } elseif (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $err[] = 'Choose a file to upload.';
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $err[] = 'The file is too big. Maximum size is 5 MB.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
            if (!$ext) {
                $err[] = 'Upload a PDF, JPG or PNG file.';
            } else {
                $name = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
                    $err[] = 'The file could not be saved. Try again.';
                } else {
                    $old = $docs[$type]['file_name'] ?? null;
                    if ($old && is_file($dir . basename($old))) unlink($dir . basename($old));
                    db()->prepare('INSERT INTO driver_documents (user_id, doc_type, file_name, original_name, expiry_date) VALUES (?,?,?,?,?)
                                   ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), original_name = VALUES(original_name),
                                                           expiry_date = VALUES(expiry_date), uploaded_at = CURRENT_TIMESTAMP')
                        ->execute([$u['id'], $type, $name, mb_substr(basename($f['name']), 0, 150), $exp ?: null]);
                    flash_set($types[$type] . ' uploaded.');
                    redirect('driver_documents.php');
                }
            }
        }
    } elseif ($action === 'submit') {
        $expired = array_filter($docs, fn($d) => $d['expiry_date'] && $d['expiry_date'] < $today);
        if (count($docs) < count($types)) {
            $err[] = 'Upload all four documents before submitting.';
        } elseif ($expired) {
            $err[] = 'One of your documents has expired. Replace it before submitting.';
        } else {
            db()->prepare("UPDATE users SET driver_status = 'pending', driver_note = NULL WHERE id = ?")->execute([$u['id']]);
            flash_set('Submitted. An admin will review your documents.');
            redirect('driver_documents.php');
        }
    }
}

$labels = ['unverified' => 'Not submitted', 'pending' => 'Waiting for admin review', 'approved' => 'Approved driver', 'rejected' => 'Rejected'];
$title = 'Driver approval';
$poll = '';
include 'includes/header.php';
?>
<div class="row justify-content-center pt-lg-3"><div class="col-md-9 col-lg-7 col-xl-6">
  <h1 class="h3 mb-1">Driver approval</h1>
  <p class="text-muted mb-3">Upload the four documents below so an admin can approve you as a driver. Only approved drivers appear in load matching. Documents must be valid, and the vehicle inspection must be approved and stamped.</p>
  <p class="mb-3">Status: <b><?= e($labels[$me['driver_status']] ?? $me['driver_status']) ?></b></p>
  <?php if ($me['driver_status'] === 'rejected' && $me['driver_note']): ?>
    <div class="alert alert-danger py-2">Reason: <?= e($me['driver_note']) ?>. Replace the documents and submit again.</div>
  <?php endif; ?>
  <?= flash_get() ?>
  <?php foreach ($err as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>

  <?php foreach ($types as $key => $label): $d = $docs[$key] ?? null; $req = in_array($key, $needsExpiry, true); ?>
    <div class="load-item">
      <b><?= e($label) ?></b>
      <div class="small text-muted mb-2">
        <?= $d ? 'Uploaded: ' . e($d['original_name']) . ($d['expiry_date'] ? ' &middot; expires ' . e(fmt_date($d['expiry_date'])) : '') : 'Not uploaded yet' ?>
      </div>
      <?php if ($canEdit): ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload"><input type="hidden" name="doc_type" value="<?= e($key) ?>">
          <div><input class="form-control form-control-sm" style="max-width:240px" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
          <div><label class="small text-muted d-block" for="exp_<?= e($key) ?>">Expiry date<?= $req ? '' : ' (if any)' ?></label>
            <input class="form-control form-control-sm" id="exp_<?= e($key) ?>" type="date" name="expiry_date" min="<?= $today ?>" <?= $req ? 'required' : '' ?>></div>
          <button class="btn btn-outline-ink btn-sm"><?= $d ? 'Replace' : 'Upload' ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($canEdit): ?>
    <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="submit">
      <button class="btn btn-crate w-100" <?= count($docs) < count($types) ? 'disabled' : '' ?>>Submit for approval</button>
    </form>
  <?php endif; ?>
</div></div>
<?php include 'includes/footer.php'; ?>