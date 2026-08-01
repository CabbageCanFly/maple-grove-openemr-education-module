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

$currentUserId = (int) (
    $_SESSION['authUserID']
    ?? $GLOBALS['authUserID']
    ?? 0
);

$currentUsername = (string) (
    $_SESSION['authUser']
    ?? $GLOBALS['authUser']
    ?? ''
);

$educationUser = false;

if ($currentUserId > 0) {
    $educationUser = sqlQuery(
        "SELECT
            education_role,
            track_activity,
            can_view_analytics
         FROM mod_maple_grove_education_users
         WHERE openemr_user_id = ?",
        [$currentUserId]
    );
}

$isTrackedStudent = !empty($educationUser['track_activity']);
$canViewAnalytics = !empty($educationUser['can_view_analytics']);

/*
 * Record at most one dashboard-opened event per tracked user every five
 * minutes. This avoids creating a new analytics event for every refresh.
 */
if ($isTrackedStudent && $currentUserId > 0 && $currentUsername !== '') {
    $recentDashboardOpen = sqlQuery(
        "SELECT id
         FROM mod_maple_grove_education_events
         WHERE openemr_user_id = ?
           AND event_type = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY created_at DESC
         LIMIT 1",
        [$currentUserId, 'dashboard_opened']
    );

    if (!$recentDashboardOpen) {
        sqlStatement(
            "INSERT INTO mod_maple_grove_education_events
                (
                    openemr_user_id,
                    username,
                    event_type,
                    created_at
                )
             VALUES (?, ?, ?, NOW())",
            [
                $currentUserId,
                $currentUsername,
                'dashboard_opened'
            ]
        );
    }
}

$trackedStudentCount = 0;
$activeStudentCount = 0;
$eventsTodayCount = 0;
$dashboardOpensTodayCount = 0;
$recentActivity = [];

if ($canViewAnalytics) {
    $trackedStudentRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_users
         WHERE track_activity = 1"
    );

    $trackedStudentCount = (int) ($trackedStudentRow['total'] ?? 0);

    $activeStudentRow = sqlQuery(
        "SELECT COUNT(DISTINCT events.openemr_user_id) AS total
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
           AND events.created_at >= CURDATE()"
    );

    $activeStudentCount = (int) ($activeStudentRow['total'] ?? 0);

    $eventsTodayRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
           AND events.created_at >= CURDATE()"
    );

    $eventsTodayCount = (int) ($eventsTodayRow['total'] ?? 0);

    $dashboardOpensTodayRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
           AND events.event_type = ?
           AND events.created_at >= CURDATE()",
        ['dashboard_opened']
    );

    $dashboardOpensTodayCount = (int) (
        $dashboardOpensTodayRow['total']
        ?? 0
    );

    $recentActivityStatement = sqlStatement(
        "SELECT
            events.username,
            events.event_type,
            events.created_at
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
         ORDER BY events.created_at DESC
         LIMIT 20"
    );

    while ($row = sqlFetchArray($recentActivityStatement)) {
        $recentActivity[] = $row;
    }
}

$myDashboardOpensToday = 0;
$myLastActivity = null;

if ($isTrackedStudent && $currentUserId > 0) {
    $myDashboardOpensRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events
         WHERE openemr_user_id = ?
           AND event_type = ?
           AND created_at >= CURDATE()",
        [$currentUserId, 'dashboard_opened']
    );

    $myDashboardOpensToday = (int) (
        $myDashboardOpensRow['total']
        ?? 0
    );

    $myLastActivity = sqlQuery(
        "SELECT event_type, created_at
         FROM mod_maple_grove_education_events
         WHERE openemr_user_id = ?
         ORDER BY created_at DESC
         LIMIT 1",
        [$currentUserId]
    );
}

function formatEducationEventType(string $eventType): string
{
    return ucwords(str_replace('_', ' ', $eventType));
}

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
<div class="container-fluid mt-3 mb-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">
                <?php echo xlt("Maple Grove Education Dashboard"); ?>
            </h1>

            <p class="text-muted mb-0">
                Educational activity, task progress, and participation monitoring.
            </p>
        </div>

        <div class="mt-2 mt-md-0">
            <?php if ($canViewAnalytics) : ?>
                <a
                    class="btn btn-outline-primary mr-2"
                    href="manage-education-users.php"
                >
                    <?php echo xlt("Manage Education Users"); ?>
                </a>
            <?php endif; ?>

            <span class="badge badge-secondary p-2">
                Analytics MVP
            </span>
        </div>
    </div>

    <?php if ($canViewAnalytics) : ?>

        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-muted">
                            <?php echo xlt("Tracked Students"); ?>
                        </h2>

                        <div class="display-4">
                            <?php echo text((string) $trackedStudentCount); ?>
                        </div>

                        <p class="mb-0 text-muted">
                            Accounts selected for education activity tracking.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-muted">
                            <?php echo xlt("Active Students Today"); ?>
                        </h2>

                        <div class="display-4">
                            <?php echo text((string) $activeStudentCount); ?>
                        </div>

                        <p class="mb-0 text-muted">
                            Tracked students with at least one event today.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-muted">
                            <?php echo xlt("Events Today"); ?>
                        </h2>

                        <div class="display-4">
                            <?php echo text((string) $eventsTodayCount); ?>
                        </div>

                        <p class="mb-0 text-muted">
                            Events generated by tracked students today.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-muted">
                            <?php echo xlt("Dashboard Opens Today"); ?>
                        </h2>

                        <div class="display-4">
                            <?php echo text((string) $dashboardOpensTodayCount); ?>
                        </div>

                        <p class="mb-0 text-muted">
                            Throttled to one event per student every five minutes.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <strong><?php echo xlt("Recent Student Activity"); ?></strong>
            </div>

            <?php if (empty($recentActivity)) : ?>
                <div class="card-body text-muted">
                    No tracked student activity has been recorded yet.
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                        <tr>
                            <th><?php echo xlt("Username"); ?></th>
                            <th><?php echo xlt("Event"); ?></th>
                            <th><?php echo xlt("Time"); ?></th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($recentActivity as $event) : ?>
                            <tr>
                                <td>
                                    <code><?php echo text($event['username']); ?></code>
                                </td>
                                <td>
                                    <?php
                                    echo text(
                                        formatEducationEventType(
                                            $event['event_type']
                                        )
                                    );
                                    ?>
                                </td>
                                <td>
                                    <?php echo text($event['created_at']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($isTrackedStudent) : ?>

        <div class="alert alert-success">
            Your education activity is being tracked for this prototype.
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-muted">
                            <?php echo xlt("My Dashboard Opens Today"); ?>
                        </h2>

                        <div class="display-4">
                            <?php echo text((string) $myDashboardOpensToday); ?>
                        </div>

                        <p class="mb-0 text-muted">
                            Repeated refreshes within five minutes count once.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-muted">
                            <?php echo xlt("My Latest Activity"); ?>
                        </h2>

                        <?php if ($myLastActivity) : ?>
                            <p class="h5 mb-1">
                                <?php
                                echo text(
                                    formatEducationEventType(
                                        $myLastActivity['event_type']
                                    )
                                );
                                ?>
                            </p>

                            <p class="mb-0 text-muted">
                                <?php echo text($myLastActivity['created_at']); ?>
                            </p>
                        <?php else : ?>
                            <p class="mb-0 text-muted">
                                No education activity has been recorded yet.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else : ?>

        <div class="alert alert-info">
            Your account is not currently configured as a tracked student or
            analytics viewer.
        </div>

    <?php endif; ?>

</div>
</body>
</html>
