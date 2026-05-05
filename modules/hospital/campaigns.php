<?php
// Blood drive campaigns — hospital-side planning and management tool
// Hospitals use this to plan, schedule, and track blood drive events

$pageTitle = 'Campaign Planning';
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
                "INSERT INTO campaigns (hospital_id, country_id, title, description, campaign_date, location, status)
                 VALUES (:h, :c, :t, :d, :s, :l, 'active')"
            )->execute([':h' => $hid, ':c' => $cid, ':t' => $t, ':d' => $d, ':s' => $sd, ':l' => $ln]);
            logAction('campaign_created', $t);
            setFlash('success', 'Campaign created!');
            redirect('/modules/hospital/campaigns.php');
        }
    }
}

// ---- Update campaign status ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && verifyCSRF()) {
    $cmpId  = (int)$_POST['campaign_id'];
    $newSt  = $_POST['new_status'] ?? '';
    if (in_array($newSt, ['active', 'completed', 'cancelled'])) {
        $db->prepare("UPDATE campaigns SET status = :s WHERE id = :i AND hospital_id = :h")
           ->execute([':s' => $newSt, ':i' => $cmpId, ':h' => $hid]);
        logAction('campaign_status_changed', "#$cmpId to $newSt");
        setFlash('success', 'Status updated.');
    }
    redirect('/modules/hospital/campaigns.php');
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
$s = $db->prepare("SELECT * FROM campaigns WHERE hospital_id = :h AND country_id = :c ORDER BY campaign_date ASC");
$s->execute([':h' => $hid, ':c' => $cid]);
$campaigns = $s->fetchAll();

// Count by status
$counts = ['active' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($campaigns as $c) {
    $st = $c['status'] ?? 'planning';
    if (isset($counts[$st])) $counts[$st]++;
}

require_once APP_ROOT . '/includes/header.php';
?>

<h2 class="mb-4"><i class="fas fa-bullhorn me-2 text-danger"></i>Campaign Planning</h2>

<!-- Purpose banner -->
<div class="alert alert-info py-2 mb-3">
    <i class="fas fa-info-circle me-2"></i>
    <strong>Campaign Planner:</strong> Schedule and track blood drive events for your hospital.
    Plan upcoming drives, mark them as active when running, and record completion for your records.
</div>

<!-- Status summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card text-center p-3">
            <div class="text-success fw-bold" style="font-size:1.5rem"><?= $counts['active'] ?></div>
            <small class="text-muted">Active</small>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center p-3">
            <div class="text-primary fw-bold" style="font-size:1.5rem"><?= $counts['completed'] ?></div>
            <small class="text-muted">Completed</small>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center p-3">
            <div class="text-secondary fw-bold" style="font-size:1.5rem"><?= $counts['cancelled'] ?></div>
            <small class="text-muted">Cancelled</small>
        </div>
    </div>
</div>

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
    <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-plus-circle me-2 text-danger"></i>Schedule New Campaign</h5></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Campaign Title *</label>
                    <input type="text" class="form-control" name="title" placeholder="e.g. World Blood Donor Day Drive" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Event Date *</label>
                    <input type="date" class="form-control" name="start_date" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2" placeholder="Details about the blood drive, target blood types, expected donors..."></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Venue / Location</label>
                    <input type="text" class="form-control" name="location_name" placeholder="e.g. Hospital Main Hall">
                </div>
            </div>
            <button type="submit" name="create_campaign" class="btn btn-blood mt-3">
                <i class="fas fa-plus me-2"></i>Create Campaign
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Campaign listing -->
<div class="card">
    <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-list me-2 text-danger"></i>Your Campaigns</h5></div>
    <div class="card-body">
        <?php if (empty($campaigns)): ?>
            <p class="text-muted">No campaigns scheduled. Create your first blood drive event above.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Campaign</th><th>Date</th><th>Venue</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $c):
                        $st = $c['status'] ?? 'active';
                        $stClass = $st === 'active' ? 'bg-success' : ($st === 'completed' ? 'bg-primary' : 'bg-secondary');
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($c['title']) ?></strong>
                            <?php if ($c['description']): ?>
                                <br><small class="text-muted"><?= e(substr($c['description'], 0, 80)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($c['campaign_date'] ?? '-') ?></td>
                        <td><?= e($c['location'] ?? '-') ?></td>
                        <td><span class="badge <?= $stClass ?>"><?= e(ucfirst($st)) ?></span></td>
                        <td>
                            <!-- Status change -->
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                <?php if ($st === 'active'): ?>
                                    <button name="update_status" class="btn btn-sm btn-outline-primary" title="Mark completed">
                                        <input type="hidden" name="new_status" value="completed">
                                        <i class="fas fa-check me-1"></i>Complete
                                    </button>
                                <?php endif; ?>
                            </form>

                            <?php // Cancel button (active only) ?>
                            <?php if ($st === 'active'): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                <input type="hidden" name="new_status" value="cancelled">
                                <button name="update_status" class="btn btn-sm btn-outline-secondary" title="Cancel campaign">
                                    <i class="fas fa-ban me-1"></i>Cancel
                                </button>
                            </form>
                            <?php endif; ?>

                            <!-- Delete -->
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this campaign permanently?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                <button type="submit" name="delete_campaign" class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="fas fa-trash"></i>
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