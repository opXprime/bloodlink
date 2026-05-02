<?php
// Blood drive campaigns — create and manage donation events

$pageTitle = 'Campaigns';
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('hospital');

$db  = Database::getInstance();
$uid = currentUserId();
$cid = currentCountryId();

// Load hospital profile
$s = $db->prepare("SELECT * FROM hospital_profiles WHERE user_id = :u");
$s->execute([':u' => $uid]);
$hospital = $s->fetch();
$hid = $hospital['id'] ?? 0;

$errors = [];

// ---- Create a new campaign ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_campaign']) && verifyCSRF()) {
    if ($hospital['verification_status'] !== 'verified') {
        $errors[] = 'Not verified.';
    } else {
        $t  = trim($_POST['title'] ?? '');
        $d  = trim($_POST['description'] ?? '');
        $sd = $_POST['start_date'] ?? '';
        $ln = trim($_POST['location_name'] ?? '');

        if (strlen($t) < 2) $errors[] = 'Title required.';
        if (!$sd)            $errors[] = 'Date required.';

        if (empty($errors)) {
            $db->prepare(
                "INSERT INTO campaigns (hospital_id, country_id, title, description, campaign_date, location)
                 VALUES (:h, :c, :t, :d, :s, :l)"
            )->execute([':h' => $hid, ':c' => $cid, ':t' => $t, ':d' => $d, ':s' => $sd, ':l' => $ln]);
            logAction('campaign_created', $t);
            setFlash('success', 'Created!');
            redirect('/modules/hospital/campaigns.php');
        }
    }
}

// ---- Delete a campaign ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_campaign']) && verifyCSRF()) {
    $cmpId = (int)$_POST['campaign_id'];
    $db->prepare("DELETE FROM campaigns WHERE id = :i AND hospital_id = :h AND country_id = :c")
       ->execute([':i' => $cmpId, ':h' => $hid, ':c' => $cid]);
    logAction('campaign_deleted', "#$cmpId");
    setFlash('success', 'Campaign deleted.');
    redirect('/modules/hospital/campaigns.php');
}

// ---- Load existing campaigns (newest first) ----
$s = $db->prepare("SELECT * FROM campaigns WHERE hospital_id = :h AND country_id = :c ORDER BY created_at DESC");
$s->execute([':h' => $hid, ':c' => $cid]);
$campaigns = $s->fetchAll();

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-bullhorn me-2 text-danger"></i>Campaigns</h2>

<?php // Validation errors ?>
<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php // Create campaign form (verified hospitals only) ?>
<?php if ($hospital && $hospital['verification_status'] === 'verified'): ?>
<div class="card mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Create Campaign</h5></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Title *</label>
                    <input type="text" class="form-control" name="title" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date *</label>
                    <input type="date" class="form-control" name="start_date" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2"></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Location Name</label>
                    <input type="text" class="form-control" name="location_name">
                </div>
            </div>
            <button type="submit" name="create_campaign" class="btn btn-blood mt-3">
                <i class="fas fa-plus me-2"></i>Create
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Campaign listing -->
<div class="card">
<div class="card-body">
    <?php if (empty($campaigns)): ?>
        <p class="text-muted">No campaigns.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Title</th><th>Dates</th><th>Location</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $c): ?>
                <tr>
                    <td>
                        <strong><?= e($c['title']) ?></strong>
                        <?php if ($c['description']): ?>
                            <br><small class="text-muted"><?= e(substr($c['description'], 0, 60)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($c['campaign_date'] ?? '-') ?></td>
                    <td><?= e($c['location'] ?? '-') ?></td>
                    <td>
                        <!-- Delete with confirmation -->
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this campaign?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                            <button type="submit" name="delete_campaign" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>