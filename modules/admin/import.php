<?php
// admin bulk import — upload a CSV of locations (country, city, area, lat, lon)
// creates countries/cities if they dont exist, skips duplicate areas
$pageTitle='Import Locations';
require_once __DIR__.'/../../includes/bootstrap.php';
requireRole('admin');
$db=Database::getInstance();

$results = null;

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['import_csv']) && verifyCSRF()){
    // check a file was uploaded
    if(!isset($_FILES['csv_file']) || $_FILES['csv_file']['error']!==UPLOAD_ERR_OK){
        setFlash('error','Please select a valid CSV file.');
        redirect('/modules/admin/import.php');
    }

    $file = $_FILES['csv_file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

    // basic validation — must be .csv
    if($ext !== 'csv'){
        setFlash('error','Only .csv files are accepted.');
        redirect('/modules/admin/import.php');
    }

    // read the file
    $handle = fopen($file, 'r');
    if(!$handle){
        setFlash('error','Could not read the file.');
        redirect('/modules/admin/import.php');
    }

    // first row should be headers
    $headers = fgetcsv($handle);
    if(!$headers || count($headers) < 5){
        fclose($handle);
        setFlash('error','CSV must have at least 5 columns: country, city, area, latitude, longitude');
        redirect('/modules/admin/import.php');
    }

    // normalize headers to lowercase and trim
    $headers = array_map(function($h){ return strtolower(trim($h)); }, $headers);

    // find column indexes — flexible so column order doesnt matter
    $colCountry = array_search('country', $headers);
    $colCity = array_search('city', $headers);
    $colArea = array_search('area', $headers);
    $colLat = array_search('latitude', $headers);
    $colLon = array_search('longitude', $headers);

    // also accept short names
    if($colLat === false) $colLat = array_search('lat', $headers);
    if($colLon === false) $colLon = array_search('lon', $headers);
    if($colLon === false) $colLon = array_search('lng', $headers);

    if($colCountry === false || $colCity === false || $colArea === false || $colLat === false || $colLon === false){
        fclose($handle);
        setFlash('error','CSV headers must include: country, city, area, latitude (or lat), longitude (or lon/lng)');
        redirect('/modules/admin/import.php');
    }

    // process rows
    $stats = ['countries_created'=>0, 'cities_created'=>0, 'areas_created'=>0, 'skipped'=>0, 'errors'=>0, 'total'=>0];
    $errorRows = [];

    // cache lookups so we dont hit db for every row
    $countryCache = []; // name => id
    $cityCache = [];    // "country_id:city_name" => id

    // preload existing countries and cities
    foreach($db->query("SELECT id, name FROM countries")->fetchAll() as $r) $countryCache[strtolower($r['name'])] = $r['id'];
    foreach($db->query("SELECT id, country_id, name FROM cities")->fetchAll() as $r) $cityCache[$r['country_id'].':'.strtolower($r['name'])] = $r['id'];

    $rowNum = 1; // header was row 0
    while(($row = fgetcsv($handle)) !== false){
        $rowNum++;
        $stats['total']++;

        // skip empty rows
        if(!$row || count($row) < 5) { $stats['skipped']++; continue; }

        $countryName = trim($row[$colCountry] ?? '');
        $cityName = trim($row[$colCity] ?? '');
        $areaName = trim($row[$colArea] ?? '');
        $lat = trim($row[$colLat] ?? '');
        $lon = trim($row[$colLon] ?? '');

        // validate
        if(strlen($countryName)<2 || strlen($cityName)<2 || strlen($areaName)<2){
            $stats['errors']++;
            $errorRows[] = "Row $rowNum: country/city/area too short";
            continue;
        }
        if(!is_numeric($lat) || !is_numeric($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180){
            $stats['errors']++;
            $errorRows[] = "Row $rowNum: invalid coordinates ($lat, $lon)";
            continue;
        }

        // get or create country
        $ck = strtolower($countryName);
        if(!isset($countryCache[$ck])){
            // create it — generate a 2-letter code from the name
            $code = strtoupper(substr(preg_replace('/[^a-z]/', '', strtolower($countryName)), 0, 2));
            // make code unique if needed
            $existing = $db->prepare("SELECT id FROM countries WHERE code=:c"); $existing->execute([':c'=>$code]);
            if($existing->fetch()) $code = strtoupper(substr($countryName, 0, 1)) . rand(1,9);

            $db->prepare("INSERT INTO countries (name, code) VALUES (:n, :c)")->execute([':n'=>$countryName, ':c'=>$code]);
            $countryCache[$ck] = $db->lastInsertId();
            $stats['countries_created']++;
        }
        $countryId = $countryCache[$ck];

        // get or create city
        $cityKey = $countryId.':'.strtolower($cityName);
        if(!isset($cityCache[$cityKey])){
            $db->prepare("INSERT INTO cities (country_id, name) VALUES (:co, :n)")->execute([':co'=>$countryId, ':n'=>$cityName]);
            $cityCache[$cityKey] = $db->lastInsertId();
            $stats['cities_created']++;
        }
        $cityId = $cityCache[$cityKey];

        // check if this area already exists in this city (skip duplicates)
        $areaCheck = $db->prepare("SELECT id FROM areas WHERE city_id=:c AND LOWER(name)=LOWER(:n)");
        $areaCheck->execute([':c'=>$cityId, ':n'=>$areaName]);
        if($areaCheck->fetch()){
            $stats['skipped']++;
            continue;
        }

        // insert area
        $db->prepare("INSERT INTO areas (city_id, name, centroid_lat, centroid_lon) VALUES (:c, :n, :lat, :lon)")
            ->execute([':c'=>$cityId, ':n'=>$areaName, ':lat'=>$lat, ':lon'=>$lon]);
        $stats['areas_created']++;
    }

    fclose($handle);
    logAction('bulk_import', "areas:{$stats['areas_created']} cities:{$stats['cities_created']} countries:{$stats['countries_created']} skipped:{$stats['skipped']} errors:{$stats['errors']}");
    $results = $stats;
    $results['error_details'] = $errorRows;
}

require_once APP_ROOT.'/includes/header.php';
?>
<h2 class="mb-4"><i class="fas fa-file-upload me-2 text-danger"></i>Import Locations</h2>

<!-- instructions -->
<div class="card mb-4"><div class="card-body">
<h5><i class="fas fa-info-circle text-info me-2"></i>CSV Format</h5>
<p class="text-muted mb-2">Upload a CSV file with location data. The system will create countries and cities automatically if they don't exist, and skip areas that are already in the database.</p>
<p class="mb-1"><strong>Required columns:</strong> country, city, area, latitude, longitude</p>
<p class="mb-1"><strong>Column order doesn't matter</strong> — the system reads the header row to find each column.</p>
<p class="mb-0"><strong>Example:</strong></p>
<div class="bg-light rounded p-3 mt-2" style="font-family:monospace; font-size:0.85rem">
country,city,area,latitude,longitude<br>
Nepal,Kathmandu,Thamel,27.7153,85.3123<br>
Nepal,Kathmandu,Balaju,27.7270,85.3005<br>
Nepal,Pokhara,Lakeside,28.2096,83.9559<br>
Denmark,Copenhagen,Nørrebro,55.6970,12.5470
</div>
</div></div>

<!-- upload form -->
<div class="card mb-4"><div class="card-body">
<form method="POST" enctype="multipart/form-data">
<?=csrfField()?>
<div class="row align-items-end">
    <div class="col-md-8">
        <label class="form-label fw-bold">Select CSV File</label>
        <input type="file" class="form-control" name="csv_file" accept=".csv" required>
    </div>
    <div class="col-md-4">
        <button type="submit" name="import_csv" class="btn btn-blood w-100"><i class="fas fa-upload me-2"></i>Import</button>
    </div>
</div>
</form>
</div></div>

<?php if($results !== null):?>
<!-- results -->
<div class="card mb-4 border-success"><div class="card-header bg-success bg-opacity-10"><h5 class="mb-0 text-success"><i class="fas fa-check-circle me-2"></i>Import Complete</h5></div>
<div class="card-body">
    <div class="row g-3">
        <div class="col-md-2"><div class="text-center"><div class="fs-3 fw-bold text-primary"><?=$results['total']?></div><small class="text-muted">Total Rows</small></div></div>
        <div class="col-md-2"><div class="text-center"><div class="fs-3 fw-bold text-success"><?=$results['areas_created']?></div><small class="text-muted">Areas Added</small></div></div>
        <div class="col-md-2"><div class="text-center"><div class="fs-3 fw-bold text-info"><?=$results['cities_created']?></div><small class="text-muted">Cities Created</small></div></div>
        <div class="col-md-2"><div class="text-center"><div class="fs-3 fw-bold text-info"><?=$results['countries_created']?></div><small class="text-muted">Countries Created</small></div></div>
        <div class="col-md-2"><div class="text-center"><div class="fs-3 fw-bold text-warning"><?=$results['skipped']?></div><small class="text-muted">Skipped (duplicates)</small></div></div>
        <div class="col-md-2"><div class="text-center"><div class="fs-3 fw-bold text-danger"><?=$results['errors']?></div><small class="text-muted">Errors</small></div></div>
    </div>
    <?php if(!empty($results['error_details'])):?>
    <hr>
    <h6 class="text-danger">Error Details:</h6>
    <?php foreach($results['error_details'] as $err):?>
    <p class="mb-1 small text-muted"><?=e($err)?></p>
    <?php endforeach;?>
    <?php endif;?>
</div></div>
<?php endif;?>

<!-- current stats -->
<div class="card"><div class="card-header bg-white"><h5 class="mb-0">Current Location Data</h5></div>
<div class="card-body">
<?php
$countryCount = (int)$db->query("SELECT COUNT(*) FROM countries")->fetchColumn();
$cityCount = (int)$db->query("SELECT COUNT(*) FROM cities")->fetchColumn();
$areaCount = (int)$db->query("SELECT COUNT(*) FROM areas")->fetchColumn();
?>
<p class="mb-1"><strong><?=$countryCount?></strong> countries, <strong><?=$cityCount?></strong> cities, <strong><?=$areaCount?></strong> areas currently in the database.</p>
<a href="<?=APP_URL?>/modules/admin/locations.php" class="btn btn-outline-secondary btn-sm mt-2"><i class="fas fa-map-marker-alt me-1"></i>Manage Locations</a>
</div></div>

<?php require_once APP_ROOT.'/includes/footer.php';?>
