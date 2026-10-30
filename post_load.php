<?php
require_once 'auth.php';
$u = require_login('farmer');
$err = [];
$crops = [
    'Vegetables' => ['Tomatoes', 'Kale (Sukuma wiki)', 'Cabbage', 'Onions', 'Spinach', 'Carrots', 'Capsicum', 'French beans', 'Snow peas', 'Garlic', 'Cauliflower', 'Broccoli', 'Cucumber', 'Pumpkin'],
    'Fruits' => ['Bananas', 'Mangoes', 'Avocados', 'Pineapples', 'Oranges', 'Passion fruit', 'Watermelon', 'Pawpaw', 'Lemons', 'Apples'],
    'Roots and tubers' => ['Irish potatoes', 'Sweet potatoes', 'Cassava', 'Arrow roots'],
    'Cereals and pulses' => ['Maize', 'Beans', 'Wheat', 'Rice', 'Sorghum', 'Millet', 'Green grams', 'Cowpeas', 'Pigeon peas'],
    'Cash crops' => ['Tea', 'Coffee', 'Sugarcane', 'Macadamia', 'Cashew nuts', 'Cotton'],
];
$allCrops = array_merge(...array_values($crops));
$picked = [];
$other = '';
$v = ['produce' => '', 'weight_kg' => '', 'perishability' => 'medium', 'pickup' => (int)($u['location_id'] ?? 0), 'drop' => 0, 'pickup_date' => '', 'deliver_by' => '', 'notes' => '', 'truck' => 'normal'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['produce' => '', 'weight_kg' => trim($_POST['weight_kg'] ?? ''), 'perishability' => $_POST['perishability'] ?? '',
          'pickup' => (int)($_POST['pickup'] ?? 0), 'drop' => (int)($_POST['drop'] ?? 0),
          'pickup_date' => trim($_POST['pickup_date'] ?? ''), 'deliver_by' => trim($_POST['deliver_by'] ?? ''), 'notes' => trim($_POST['notes'] ?? ''),
          'truck' => ($_POST['truck'] ?? '') === 'refrigerated' ? 'refrigerated' : 'normal'];
    // Several crops can be ticked; only crops from the list are accepted, plus one optional typed crop.
    $picked = array_values(array_intersect($allCrops, (array)($_POST['crops'] ?? [])));
    $other = trim($_POST['produce_other'] ?? '');
    $list = $picked;
    if ($other !== '') $list[] = $other;
    $v['produce'] = implode(', ', $list);
    $w = (int)$v['weight_kg'];
    if (!$list) $err[] = 'Tick at least one crop you are sending.';
    elseif (mb_strlen($v['produce']) > 80) $err[] = 'Too many crops to fit. Remove a few or post them as two loads.';
    if ($w < 1 || $w > 50000) $err[] = 'Enter the total weight in kilograms (1 to 50,000).';
    if (!in_array($v['perishability'], ['high', 'medium', 'low'], true)) $err[] = 'Choose how quickly the produce spoils.';
    if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$v['pickup']])) $err[] = 'Choose the pickup place.';
    if (!fetch_one('SELECT id FROM locations WHERE id = ?', [$v['drop']])) $err[] = 'Choose where it is going.';
    if ($v['pickup'] && $v['pickup'] === $v['drop']) $err[] = 'Pickup and drop-off cannot be the same place.';
    if (!valid_date($v['pickup_date']) || $v['pickup_date'] < date('Y-m-d')) $err[] = 'Choose a pickup date from today onwards.';
    if ($v['deliver_by'] !== '' && (!valid_date($v['deliver_by']) || $v['deliver_by'] < $v['pickup_date'])) $err[] = 'The deliver-by date cannot be before the pickup date.';
    if (mb_strlen($v['notes']) > 500) $err[] = 'Keep the notes under 500 characters.';
    if (!$err) {
        db()->prepare('INSERT INTO loads (farmer_id, produce, weight_kg, perishability, needs_refrigeration, origin_id, destination_id, pickup_date, deliver_by, notes) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$u['id'], $v['produce'], $w, $v['perishability'], $v['truck'] === 'refrigerated' ? 1 : 0, $v['pickup'], $v['drop'], $v['pickup_date'], $v['deliver_by'] ?: null, $v['notes'] ?: null]);
        flash_set('Load posted. Here are the trucks that fit it.');
        redirect('load.php?id=' . (int)db()->lastInsertId());
    }
}
$title = 'Post a load';
$poll = '';
include 'includes/header.php';
?>
<div class="row justify-content-center pt-lg-3"><div class="col-md-9 col-lg-7 col-xl-6">
  <h1 class="h3 mb-1">Post a load</h1>
  <p class="text-muted mb-4">Tell us what needs to move. We will rank the trucks that can take it.</p>
  <?php foreach ($err as $m): ?><div class="alert alert-danger py-2" role="alert"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="panel">
    <?= csrf_field() ?>
    <label class="form-label">Produce <span class="text-muted">(tick all that apply)</span></label>
    <div class="mb-2" style="max-height:230px;overflow-y:auto">
      <?php foreach ($crops as $group => $items): ?>
        <div class="small text-muted mt-2"><?= e($group) ?></div>
        <?php foreach ($items as $c): $cid = 'crop_' . md5($c); ?>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="crops[]" id="<?= $cid ?>" value="<?= e($c) ?>" <?= in_array($c, $picked, true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= $cid ?>"><?= e($c) ?></label>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <input class="form-control mb-3" id="produce_other" name="produce_other" maxlength="40" placeholder="Another crop not listed (optional)" value="<?= e($other) ?>">
    <label class="form-label" for="weight_kg">Total weight (kg)</label>
    <input class="form-control mb-3" id="weight_kg" name="weight_kg" type="number" min="1" max="50000" inputmode="numeric" value="<?= e($v['weight_kg']) ?>" required>
    <label class="form-label" for="perishability">How quickly does it spoil? <span class="text-muted">(mixed load: choose the fastest)</span></label>
    <select class="form-select mb-3" id="perishability" name="perishability">
      <option value="high"   <?= $v['perishability'] === 'high' ? 'selected' : '' ?>>Very fast (tomatoes, leafy greens, berries)</option>
      <option value="medium" <?= $v['perishability'] === 'medium' ? 'selected' : '' ?>>Moderate (potatoes, carrots, bananas)</option>
      <option value="low"    <?= $v['perishability'] === 'low' ? 'selected' : '' ?>>Slowly (maize, beans, onions)</option>
    </select>
    <label class="form-label d-block">Type of truck needed</label>
    <div class="mb-1">
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="truck" id="truck_normal" value="normal" <?= $v['truck'] !== 'refrigerated' ? 'checked' : '' ?>><label class="form-check-label" for="truck_normal">Normal truck</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="truck" id="truck_fridge" value="refrigerated" <?= $v['truck'] === 'refrigerated' ? 'checked' : '' ?>><label class="form-check-label" for="truck_fridge">Refrigerated truck</label></div>
    </div>
    <div class="small text-muted mb-3">Choose refrigerated for produce that must stay cool. Only refrigerated trucks will be offered.</div>
    <div class="row">
      <div class="col-sm-6"><label class="form-label" for="pickup">Pickup place</label>
        <select class="form-select mb-3" id="pickup" name="pickup" required><?= location_options($v['pickup'], 'Choose a place') ?></select></div>
      <div class="col-sm-6"><label class="form-label" for="drop">Delivering to</label>
        <select class="form-select mb-3" id="drop" name="drop" required><?= location_options($v['drop'], 'Choose a place') ?></select></div>
    </div>
    <div class="row">
      <div class="col-sm-6"><label class="form-label" for="pickup_date">Pickup date</label>
        <input class="form-control mb-3" id="pickup_date" name="pickup_date" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($v['pickup_date']) ?>" required></div>
      <div class="col-sm-6"><label class="form-label" for="deliver_by">Deliver by <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-3" id="deliver_by" name="deliver_by" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($v['deliver_by']) ?>"></div>
    </div>
    <label class="form-label" for="notes">Notes <span class="text-muted">(optional)</span></label>
    <textarea class="form-control mb-3" id="notes" name="notes" rows="3" maxlength="500" placeholder="Crates, access to the farm, anything the driver should know"><?= e($v['notes']) ?></textarea>
    <button class="btn btn-crate w-100">Find trucks</button>
  </form>
</div></div>
<?php include 'includes/footer.php'; ?>