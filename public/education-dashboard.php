<?php

/**
 * Maple Grove Education Dashboard
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE
 * GNU General Public License 3
 */

require_once dirname(__FILE__, 5) . "/globals.php";

use OpenEMR\Core\Header;

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <title>
        <?php echo xlt("Maple Grove Education Dashboard"); ?>
    </title>

    <?php Header::setupHeader(); ?>
</head>

<body class="body_top">
<div class="container-fluid mt-3">

    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">
                <?php echo xlt("Maple Grove Education Dashboard"); ?>
            </h1>

            <p class="text-muted mb-0">
                Educational activity, task progress, and participation monitoring.
            </p>
        </div>

        <span class="badge badge-secondary p-2">
            Prototype
        </span>
    </div>

    <div class="row">

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">
                        Active Students
                    </h2>

                    <div class="display-4">
                        —
                    </div>

                    <p class="mb-0 text-muted">
                        Student activity tracking is not connected yet.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">
                        Assigned Tasks
                    </h2>

                    <div class="display-4">
                        —
                    </div>

                    <p class="mb-0 text-muted">
                        Individual and team task data will appear here.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">
                        Completion Rate
                    </h2>

                    <div class="display-4">
                        —
                    </div>

                    <p class="mb-0 text-muted">
                        Completion metrics will be calculated later.
                    </p>
                </div>
            </div>
        </div>

    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <strong>Planned Dashboard Features</strong>
        </div>

        <div class="card-body">
            <ul class="mb-0">
                <li>Student and team participation</li>
                <li>Assigned task progress and completion</li>
                <li>Education-specific OpenEMR activity events</li>
                <li>Instructor and administrator reporting</li>
            </ul>
        </div>
    </div>

</div>
</body>
</html>