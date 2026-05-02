<?php
// Reusable country > city > area dropdown component
//
// Include in forms: require APP_ROOT . '/includes/location_picker.php';
//
// Expected variables (set before including):
//   $selectedCountryId  (int|null)
//   $selectedCityId     (int|null)
//   $selectedCityId     (int|null)
//   $selectedAreaId     (int|null)
//   $selectedAreaName   (string|null)
//   $locationRequired   (bool) default true

if (!defined('APP_ROOT')) die('Direct access not permitted');

$db = Database::getInstance();
$countries = $db->query("SELECT id, name FROM countries WHERE is_active = 1 ORDER BY name")->fetchAll();

// If country already selected, preload its cities
$cities = [];
if (!empty($selectedCountryId)) {
    $s = $db->prepare("SELECT id, name FROM cities WHERE country_id = :c AND is_active = 1 ORDER BY name");
    $s->execute([':c' => $selectedCountryId]);
    $cities = $s->fetchAll();
}

$req = ($locationRequired ?? true) ? 'required' : '';
?>

<meta name="app-url" content="<?= APP_URL ?>">

<div class="row g-3">

    <!-- Country dropdown -->
    <div class="col-md-4">
        <label class="form-label">Country <?= $req ? '*' : '' ?></label>
        <select class="form-select" id="loc_country" name="country_id" <?= $req ?>>
            <option value="">-- Select Country --</option>
            <?php foreach ($countries as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($selectedCountryId ?? 0) == $c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- City dropdown (populated via AJAX when country changes) -->
    <div class="col-md-4">
        <label class="form-label">City <?= $req ? '*' : '' ?></label>
        <select class="form-select" id="loc_city" name="city_id" <?= $req ?>>
            <option value="">-- Select City --</option>
            <?php foreach ($cities as $ct): ?>
            <option value="<?= $ct['id'] ?>" <?= ($selectedCityId ?? 0) == $ct['id'] ? 'selected' : '' ?>>
                <?= e($ct['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Area autocomplete (populated via AJAX when city changes) -->
    <div class="col-md-4">
        <label class="form-label">Area <?= $req ? '*' : '' ?></label>
        <div class="autocomplete-wrap">
            <input type="text" class="form-control" id="loc_area_text" name="area_name"
                   placeholder="Type to search..." autocomplete="off"
                   value="<?= e($selectedAreaName ?? '') ?>" <?= $req ?>>
            <input type="hidden" id="loc_area_id" name="area_id" value="<?= e($selectedAreaId ?? '') ?>">
            <div class="autocomplete-list" id="loc_ac_list"></div>
        </div>
    </div>

</div>