<?php
// Location management — add/edit countries, cities, areas with coordinates

$pageTitle = 'Manage Locations';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('admin');

$db = Database::getInstance();
$errors = [];

// ---- Handle all POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF()) {

    // Add country
    if (isset($_POST['add_country'])) {
        $n = trim($_POST['country_name'] ?? '');
        $c = strtoupper(trim($_POST['country_code'] ?? ''));
        if (strlen($n) < 2)               $errors[] = 'Country name required.';
        if (strlen($c) < 2 || strlen($c) > 5) $errors[] = 'Code 2-5 chars.';
        if (empty($errors)) {
            try {
                $db->prepare("INSERT INTO countries (name, code) VALUES (:n, :c)")
                   ->execute([':n' => $n, ':c' => $c]);
                logAction('country_added', "$n ($c)");
                setFlash('success', 'Country added.');
            } catch (PDOException $e) {
                $errors[] = 'Already exists.';
            }
        }
    }

    // Toggle country active/inactive
    if (isset($_POST['toggle_country'])) {
        $db->prepare("UPDATE countries SET is_active = NOT is_active WHERE id = :i")
           ->execute([':i' => (int)$_POST['country_id']]);
        setFlash('success', 'Updated.');
    }

    // Add city
    if (isset($_POST['add_city'])) {
        $cntId = (int)($_POST['city_country_id'] ?? 0);
        $cn    = trim($_POST['city_name'] ?? '');
        if ($cntId < 1)     $errors[] = 'Select country.';
        if (strlen($cn) < 2) $errors[] = 'City name required.';
        if (empty($errors)) {
            try {
                $db->prepare("INSERT INTO cities (country_id, name) VALUES (:c, :n)")
                   ->execute([':c' => $cntId, ':n' => $cn]);
                logAction('city_added', $cn);
                setFlash('success', 'City added.');
            } catch (PDOException $e) {
                $errors[] = 'City exists.';
            }
        }
    }

    // Toggle city active/inactive
    if (isset($_POST['toggle_city'])) {
        $db->prepare("UPDATE cities SET is_active = NOT is_active WHERE id = :i")
           ->execute([':i' => (int)$_POST['city_id']]);
        setFlash('success', 'Updated.');
    }

    // Add area with centroid coordinates
    if (isset($_POST['add_area'])) {
        $ciId = (int)($_POST['area_city_id'] ?? 0);
        $an   = trim($_POST['area_name'] ?? '');
        $lat  = (float)($_POST['centroid_lat'] ?? 0);
        $lon  = (float)($_POST['centroid_lon'] ?? 0);
        if ($ciId < 1)     $errors[] = 'Select city.';
        if (strlen($an) < 2) $errors[] = 'Area name required.';
        if ($lat == 0 && $lon == 0) $errors[] = 'Coords required.';
        if (empty($errors)) {
            try {
                $db->prepare(
                    "INSERT INTO areas (city_id, name, centroid_lat, centroid_lon) VALUES (:c, :n, :lat, :lon)"
                )->execute([':c' => $ciId, ':n' => $an, ':lat' => $lat, ':lon' => $lon]);
                logAction('area_added', $an);
                setFlash('success', 'Area added.');
            } catch (PDOException $e) {
                $errors[] = 'Area exists.';
            }
        }
    }

    // Toggle area active/inactive
    if (isset($_POST['toggle_area'])) {
        $db->prepare("UPDATE areas SET is_active = NOT is_active WHERE id = :i")
           ->execute([':i' => (int)$_POST['area_id']]);
        setFlash('success', 'Updated.');
    }

    // Redirect after toggle actions (not after add with errors)
    if (empty($errors) && !isset($_POST['add_country']) && !isset($_POST['add_city']) && !isset($_POST['add_area'])) {
        redirect('/modules/admin/locations.php');
    }
}

// ---- Load all location data ----
$countries = $db->query("SELECT * FROM countries ORDER BY name")->fetchAll();

$cities = $db->query(
    "SELECT ci.*, co.name AS country_name
     FROM cities ci JOIN countries co ON ci.country_id = co.id
     ORDER BY co.name, ci.name"
)->fetchAll();

$areas = $db->query(
    "SELECT ar.*, ci.name AS city_name, co.name AS country_name
     FROM areas ar
     JOIN cities ci ON ar.city_id = ci.id
     JOIN countries co ON ci.country_id = co.id
     ORDER BY co.name, ci.name, ar.name"
)->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-map-marker-alt me-2 text-danger"></i>Manage Locations</h2>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ========== ADD FORMS ========== -->
<div class="row g-4 mb-4">

    <!-- Add Country -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><h6 class="mb-0">Add Country</h6></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" name="country_name" placeholder="Country Name" required>
                    </div>
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" name="country_code" placeholder="Code (NP)" maxlength="5" required>
                    </div>
                    <button type="submit" name="add_country" class="btn btn-blood btn-sm w-100">Add</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add City -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><h6 class="mb-0">Add City</h6></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-2">
                        <select class="form-select form-select-sm" name="city_country_id" required>
                            <option value="">-- Country --</option>
                            <?php foreach ($countries as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" name="city_name" placeholder="City Name" required>
                    </div>
                    <button type="submit" name="add_city" class="btn btn-blood btn-sm w-100">Add</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Area -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><h6 class="mb-0">Add Area</h6></div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-2">
                        <select class="form-select form-select-sm" name="area_city_id" required>
                            <option value="">-- City --</option>
                            <?php foreach ($cities as $ci): ?>
                            <option value="<?= $ci['id'] ?>"><?= e($ci['country_name']) ?> → <?= e($ci['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" name="area_name" placeholder="Area Name" required>
                    </div>
                    <div class="row g-1 mb-2">
                        <div class="col-6">
                            <input type="number" step="any" class="form-control form-control-sm" name="centroid_lat" placeholder="Latitude" required>
                        </div>
                        <div class="col-6">
                            <input type="number" step="any" class="form-control form-control-sm" name="centroid_lon" placeholder="Longitude" required>
                        </div>
                    </div>
                    <button type="submit" name="add_area" class="btn btn-blood btn-sm w-100">Add</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ========== COUNTRIES TABLE ========== -->
<div class="card mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Countries (<?= count($countries) ?>)</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Name</th><th>Code</th><th>Active</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($countries as $c): ?>
                    <tr>
                        <td><?= e($c['name']) ?></td>
                        <td><code><?= e($c['code']) ?></code></td>
                        <td><span class="badge <?= $c['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $c['is_active'] ? 'Yes' : 'No' ?></span></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="country_id" value="<?= $c['id'] ?>">
                                <button type="submit" name="toggle_country" class="btn btn-sm btn-outline-secondary">
                                    <?= $c['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========== CITIES TABLE ========== -->
<div class="card mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Cities (<?= count($cities) ?>)</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Country</th><th>City</th><th>Active</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($cities as $ci): ?>
                    <tr>
                        <td><?= e($ci['country_name']) ?></td>
                        <td><?= e($ci['name']) ?></td>
                        <td><span class="badge <?= $ci['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $ci['is_active'] ? 'Yes' : 'No' ?></span></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="city_id" value="<?= $ci['id'] ?>">
                                <button type="submit" name="toggle_city" class="btn btn-sm btn-outline-secondary">
                                    <?= $ci['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========== AREAS TABLE ========== -->
<div class="card">
    <div class="card-header bg-white"><h5 class="mb-0">Areas (<?= count($areas) ?>)</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Country</th><th>City</th><th>Area</th><th>Lat</th><th>Lon</th><th>Active</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($areas as $a): ?>
                    <tr>
                        <td><?= e($a['country_name']) ?></td>
                        <td><?= e($a['city_name']) ?></td>
                        <td><?= e($a['name']) ?></td>
                        <td><small><?= e($a['centroid_lat']) ?></small></td>
                        <td><small><?= e($a['centroid_lon']) ?></small></td>
                        <td><span class="badge <?= $a['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $a['is_active'] ? 'Yes' : 'No' ?></span></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="area_id" value="<?= $a['id'] ?>">
                                <button type="submit" name="toggle_area" class="btn btn-sm btn-outline-secondary">
                                    <?= $a['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>