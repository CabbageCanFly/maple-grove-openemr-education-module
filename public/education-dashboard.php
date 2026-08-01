<?php

/**
 * Maple Grove Education Dashboard
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE
 * GNU General Public License 3
 */

require_once dirname(__FILE__, 5) . "/globals.php";
require_once dirname(__FILE__) . "/../src/EducationAnalytics.php";

use OpenEMR\Core\Header;
use OpenEMR\Modules\CustomModuleSkeleton\EducationAnalytics;

$currentUserId = EducationAnalytics::currentUserId();
$currentUsername = EducationAnalytics::currentUsername();
$educationUser = EducationAnalytics::getEducationUser($currentUserId);

$isTrackedStudent = !empty($educationUser['track_activity']);
$canManageEducation = EducationAnalytics::canManageEducation($currentUserId);

EducationAnalytics::recordTrackedEvent(
    $currentUserId,
    $currentUsername,
    'dashboard_opened',
    [],
    5
);

$rangeOptions = [
    'today' => [
        'label' => 'Today',
        'condition' => 'events.created_at >= CURDATE()'
    ],
    '7' => [
        'label' => 'Last 7 Days',
        'condition' => 'events.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    ],
    '30' => [
        'label' => 'Last 30 Days',
        'condition' => 'events.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
    ],
    'all' => [
        'label' => 'All Time',
        'condition' => '1 = 1'
    ]
];

$rangeKey = (string) ($_GET['range'] ?? '7');

if (!isset($rangeOptions[$rangeKey])) {
    $rangeKey = '7';
}

$rangeLabel = $rangeOptions[$rangeKey]['label'];
$rangeCondition = $rangeOptions[$rangeKey]['condition'];

$trackedStudentCount = 0;
$activeStudentCount = 0;
$eventsInRangeCount = 0;
$dashboardOpensInRangeCount = 0;
$lastCohortActivity = false;
$recentCohortActivity = [];
$eventBreakdown = [];

if ($canManageEducation) {
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
           AND {$rangeCondition}"
    );

    $activeStudentCount = (int) ($activeStudentRow['total'] ?? 0);

    $eventsInRangeRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
           AND {$rangeCondition}"
    );

    $eventsInRangeCount = (int) ($eventsInRangeRow['total'] ?? 0);

    $dashboardOpensRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
           AND events.event_type = ?
           AND {$rangeCondition}",
        ['dashboard_opened']
    );

    $dashboardOpensInRangeCount = (int) (
        $dashboardOpensRow['total']
        ?? 0
    );

    $lastCohortActivity = sqlQuery(
        "SELECT
            events.username,
            events.event_type,
            events.created_at
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
         ORDER BY events.created_at DESC
         LIMIT 1"
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
           AND {$rangeCondition}
         ORDER BY events.created_at DESC
         LIMIT 25"
    );

    while ($row = sqlFetchArray($recentActivityStatement)) {
        $recentCohortActivity[] = $row;
    }

    $breakdownStatement = sqlStatement(
        "SELECT
            events.event_type,
            COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
           AND {$rangeCondition}
         GROUP BY events.event_type
         ORDER BY total DESC, events.event_type"
    );

    while ($row = sqlFetchArray($breakdownStatement)) {
        $eventBreakdown[] = $row;
    }
}

$myEventsInRange = 0;
$myDashboardOpensInRange = 0;
$myActiveDaysInRange = 0;
$myLastActivity = false;
$myRecentActivity = [];

if ($isTrackedStudent && $currentUserId > 0) {
    $myEventsRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         WHERE events.openemr_user_id = ?
           AND {$rangeCondition}",
        [$currentUserId]
    );

    $myEventsInRange = (int) ($myEventsRow['total'] ?? 0);

    $myDashboardOpensRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         WHERE events.openemr_user_id = ?
           AND events.event_type = ?
           AND {$rangeCondition}",
        [$currentUserId, 'dashboard_opened']
    );

    $myDashboardOpensInRange = (int) (
        $myDashboardOpensRow['total']
        ?? 0
    );

    $myActiveDaysRow = sqlQuery(
        "SELECT COUNT(DISTINCT DATE(events.created_at)) AS total
         FROM mod_maple_grove_education_events AS events
         WHERE events.openemr_user_id = ?
           AND {$rangeCondition}",
        [$currentUserId]
    );

    $myActiveDaysInRange = (int) ($myActiveDaysRow['total'] ?? 0);

    $myLastActivity = sqlQuery(
        "SELECT event_type, created_at
         FROM mod_maple_grove_education_events
         WHERE openemr_user_id = ?
         ORDER BY created_at DESC
         LIMIT 1",
        [$currentUserId]
    );

    $myRecentStatement = sqlStatement(
        "SELECT event_type, created_at
         FROM mod_maple_grove_education_events AS events
         WHERE events.openemr_user_id = ?
           AND {$rangeCondition}
         ORDER BY events.created_at DESC
         LIMIT 20",
        [$currentUserId]
    );

    while ($row = sqlFetchArray($myRecentStatement)) {
        $myRecentActivity[] = $row;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <title>
        <?php echo xlt('Maple Grove Education Dashboard'); ?>
    </title>

    <?php Header::setupHeader(); ?>
</head>

<body class="body_top">
<div class="container-fluid mt-3 mb-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1">
                <?php echo xlt('Maple Grove Education Dashboard'); ?>
            </h1>

            <p class="text-muted mb-0">
                Educational activity, progress, and participation monitoring.
            </p>
        </div>

        <div class="mt-2 mt-md-0">
            <?php if ($canManageEducation) : ?>
                <a
                    class="btn btn-outline-primary mr-2"
                    href="manage-education-users.php"
                >
                    <?php echo xlt('Manage Education Users'); ?>
                </a>
            <?php endif; ?>

            <span class="badge badge-secondary p-2">
                Analytics MVP
            </span>
        </div>
    </div>

    <form method="get" class="form-inline mb-4">
        <label for="range" class="mr-2">
            <strong><?php echo xlt('Activity Range'); ?></strong>
        </label>

        <select
            class="form-control mr-2"
            id="range"
            name="range"
            onchange="this.form.submit()"
        >
            <?php foreach ($rangeOptions as $key => $option) : ?>
                <option
                    value="<?php echo attr($key); ?>"
                    <?php echo $key === $rangeKey ? 'selected' : ''; ?>
                >
                    <?php echo text($option['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <noscript>
            <button class="btn btn-primary" type="submit">
                <?php echo xlt('Apply'); ?>
            </button>
        </noscript>
    </form>

    <?php if ($canManageEducation) : ?>

        <h2 class="h5 mb-3">
            <?php echo text('Cohort Activity — ' . $rangeLabel); ?>
        </h2>

        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Tracked Students'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $trackedStudentCount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Active Students'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $activeStudentCount); ?>
                        </div>
                        <p class="mb-0 text-muted">
                            Students with at least one event in this range.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Total Events'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $eventsInRangeCount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Dashboard Opens'); ?>
                        </h3>
                        <div class="display-4">
                            <?php
                            echo text(
                                (string) $dashboardOpensInRangeCount
                            );
                            ?>
                        </div>
                        <p class="mb-0 text-muted">
                            Repeated opens are throttled for five minutes.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-muted">
                    <?php echo xlt('Last Recorded Student Activity'); ?>
                </h3>

                <?php if ($lastCohortActivity) : ?>
                    <p class="h5 mb-1">
                        <code>
                            <?php
                            echo text($lastCohortActivity['username']);
                            ?>
                        </code>
                        —
                        <?php
                        echo text(
                            EducationAnalytics::formatEventType(
                                $lastCohortActivity['event_type']
                            )
                        );
                        ?>
                    </p>

                    <p class="mb-0 text-muted">
                        <?php echo text($lastCohortActivity['created_at']); ?>
                    </p>
                <?php else : ?>
                    <p class="mb-0 text-muted">
                        No tracked student activity has been recorded yet.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <strong>
                            <?php
                            echo text(
                                'Recent Student Activity — ' . $rangeLabel
                            );
                            ?>
                        </strong>
                    </div>

                    <?php if (empty($recentCohortActivity)) : ?>
                        <div class="card-body text-muted">
                            No events occurred in the selected range.
                        </div>
                    <?php else : ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead>
                                <tr>
                                    <th><?php echo xlt('Username'); ?></th>
                                    <th><?php echo xlt('Event'); ?></th>
                                    <th><?php echo xlt('Time'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                foreach ($recentCohortActivity as $event) :
                                ?>
                                    <tr>
                                        <td>
                                            <code>
                                                <?php
                                                echo text($event['username']);
                                                ?>
                                            </code>
                                        </td>
                                        <td>
                                            <?php
                                            echo text(
                                                EducationAnalytics::formatEventType(
                                                    $event['event_type']
                                                )
                                            );
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            echo text($event['created_at']);
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <strong><?php echo xlt('Event Breakdown'); ?></strong>
                    </div>

                    <?php if (empty($eventBreakdown)) : ?>
                        <div class="card-body text-muted">
                            No event categories occurred in this range.
                        </div>
                    <?php else : ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($eventBreakdown as $event) : ?>
                                <li
                                    class="list-group-item d-flex justify-content-between"
                                >
                                    <span>
                                        <?php
                                        echo text(
                                            EducationAnalytics::formatEventType(
                                                $event['event_type']
                                            )
                                        );
                                        ?>
                                    </span>
                                    <span class="badge badge-primary badge-pill">
                                        <?php echo text($event['total']); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif ($isTrackedStudent) : ?>

        <div class="alert alert-success">
            Your education activity is being tracked for this prototype.
        </div>

        <h2 class="h5 mb-3">
            <?php echo text('My Activity — ' . $rangeLabel); ?>
        </h2>

        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('My Total Events'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myEventsInRange); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('My Dashboard Opens'); ?>
                        </h3>
                        <div class="display-4">
                            <?php
                            echo text(
                                (string) $myDashboardOpensInRange
                            );
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('My Active Days'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myActiveDaysInRange); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-muted">
                    <?php echo xlt('My Last Recorded Activity'); ?>
                </h3>

                <?php if ($myLastActivity) : ?>
                    <p class="h5 mb-1">
                        <?php
                        echo text(
                            EducationAnalytics::formatEventType(
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

        <div class="card shadow-sm">
            <div class="card-header">
                <strong>
                    <?php echo text('My Recent Activity — ' . $rangeLabel); ?>
                </strong>
            </div>

            <?php if (empty($myRecentActivity)) : ?>
                <div class="card-body text-muted">
                    No events occurred in the selected range.
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                        <tr>
                            <th><?php echo xlt('Event'); ?></th>
                            <th><?php echo xlt('Time'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($myRecentActivity as $event) : ?>
                            <tr>
                                <td>
                                    <?php
                                    echo text(
                                        EducationAnalytics::formatEventType(
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

    <?php else : ?>

        <div class="alert alert-info">
            Your account is not currently configured as a tracked student or
            analytics viewer.
        </div>

    <?php endif; ?>

</div>
</body>
</html>
