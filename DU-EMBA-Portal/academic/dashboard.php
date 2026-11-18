<?php
require_once __DIR__ . '/../includes/auth.php';

$requiredRole = 'academic';
$pageTitle = 'Academic Office Dashboard';

require_role($requiredRole);

require_once __DIR__ . '/../includes/academic-header.php';
?>

<div class="dashboard-content">
    <h2>Academic Workspace</h2>
    <p>
        Manage academic results, review submitted marks,
        approve results and publish student results.
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:20px; margin-top:25px;">

        <div style="border:1px solid #e1e5eb; padding:22px; border-radius:10px;">
            <h3>Result Approval</h3>
            <p>Review results submitted by teachers and approve them.</p>
            <a href="<?= BASE_URL ?>/academic/results.php" class="btn-primary">
                Manage Results
            </a>
        </div>

        <div style="border:1px solid #e1e5eb; padding:22px; border-radius:10px;">
            <h3>Result Publication</h3>
            <p>Publish approved semester results for students.</p>
            <a href="<?= BASE_URL ?>/academic/results.php" class="btn-primary">
                View Results
            </a>
        </div>

    </div>
</div>
<a href="<?= BASE_URL ?>/academic/results.php">
    Result Approval & Publishing
</a>
<?php require_once __DIR__ . '/../includes/academic-footer.php'; ?>