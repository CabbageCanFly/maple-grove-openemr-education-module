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
        'audit_condition' => 'audit.date >= CURDATE()',
        'module_condition' => 'events.created_at >= CURDATE()'
    ],
    '7' => [
        'label' => 'Last 7 Days',
        'audit_condition' => 'audit.date >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        'module_condition' => 'events.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    ],
    '30' => [
        'label' => 'Last 30 Days',
        'audit_condition' => 'audit.date >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
        'module_condition' => 'events.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
    ],
    'all' => [
        'label' => 'All Available History',
        'audit_condition' => '1 = 1',
        'module_condition' => '1 = 1'
    ]
];

$scopeOptions = [
    'meaningful' => 'Meaningful Student Activity',
    'all' => 'All Successful Audit Events'
];

$rangeKey = (string) ($_GET['range'] ?? '7');
$scopeKey = (string) ($_GET['scope'] ?? 'meaningful');

if (!isset($rangeOptions[$rangeKey])) {
    $rangeKey = '7';
}

if (!isset($scopeOptions[$scopeKey])) {
    $scopeKey = 'meaningful';
}

$rangeLabel = $rangeOptions[$rangeKey]['label'];
$auditRangeCondition = $rangeOptions[$rangeKey]['audit_condition'];
$moduleRangeCondition = $rangeOptions[$rangeKey]['module_condition'];

$auditScopeCondition = $scopeKey === 'all'
    ? 'audit.success = 1'
    : EducationAnalytics::meaningfulAuditCondition('audit');

$meaningfulDiscreteCondition =
    EducationAnalytics::meaningfulDiscreteAuditCondition('audit');

$patientChartCondition =
    EducationAnalytics::patientChartAuditCondition('audit');

$patientCreationBundleCondition = "
    audit.success = 1
    AND audit.event = 'patient-record-insert'
    AND audit.category IN (
        'Patient Demographics',
        'Patient Insurance',
        'Social and Family History'
    )
";

$meaningfulNonCreationCondition = "
    ({$meaningfulDiscreteCondition})
    AND NOT ({$patientCreationBundleCondition})
    AND NOT (
        audit.event IN (
            'patient-record-insert',
            'patient-record-update',
            'patient-record-delete',
            'patient-record-replace'
        )
        AND audit.patient_id <= 0
    )
";

$meaningfulPatientChartCondition = "
    ({$patientChartCondition})
    AND NOT EXISTS (
        SELECT 1
        FROM log AS created_patient
        WHERE created_patient.user = audit.user
          AND created_patient.patient_id = audit.patient_id
          AND created_patient.success = 1
          AND created_patient.event = 'patient-record-insert'
          AND created_patient.category = 'Patient Demographics'
          AND ABS(
              TIMESTAMPDIFF(
                  SECOND,
                  created_patient.date,
                  audit.date
              )
          ) <= 60
    )
";

$effectiveAuditCondition = $scopeKey === 'all'
    ? $auditScopeCondition
    : "(
        ({$meaningfulPatientChartCondition})
        OR ({$meaningfulNonCreationCondition})
        OR ({$patientCreationBundleCondition})
    )";

$trackedStudentCount = 0;
$activeStudentCount = 0;
$auditEventsCount = 0;
$loginCount = 0;
$patientChartSessions = 0;
$clinicalChangeCount = 0;
$schedulingChangeCount = 0;
$moduleEventsCount = 0;
$lastCohortAudit = false;
$recentCohortAudit = [];
$auditBreakdown = [];

if ($canManageEducation) {
    $trackedStudentRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_users
         WHERE track_activity = 1"
    );

    $trackedStudentCount = (int) ($trackedStudentRow['total'] ?? 0);

    $activeStudentRow = sqlQuery(
        "SELECT COUNT(DISTINCT audit.user) AS total
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND {$effectiveAuditCondition}
           AND {$auditRangeCondition}"
    );

    $activeStudentCount = (int) ($activeStudentRow['total'] ?? 0);

    $auditEventsRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND {$auditScopeCondition}
           AND {$auditRangeCondition}"
    );

    $auditEventsCount = (int) ($auditEventsRow['total'] ?? 0);

    $loginRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND audit.success = 1
           AND audit.event = 'login'
           AND {$auditRangeCondition}"
    );

    $loginCount = (int) ($loginRow['total'] ?? 0);

    $patientSessionsRow = sqlQuery(
        "SELECT COUNT(
            DISTINCT CONCAT_WS(
                '|',
                audit.user,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 1800)
            )
         ) AS total
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND {$meaningfulPatientChartCondition}
           AND {$auditRangeCondition}"
    );

    $patientChartSessions = (int) ($patientSessionsRow['total'] ?? 0);

    $patientCreationRow = sqlQuery(
        "SELECT COUNT(
            DISTINCT CONCAT_WS(
                '|',
                audit.user,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 10)
            )
         ) AS total
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND {$patientCreationBundleCondition}
           AND {$auditRangeCondition}"
    );

    $patientCreationCount = (int) ($patientCreationRow['total'] ?? 0);

    if ($scopeKey === 'meaningful') {
        $discreteActivityRow = sqlQuery(
            "SELECT COUNT(
                DISTINCT CONCAT_WS(
                    '|',
                    audit.user,
                    audit.event,
                    audit.category,
                    audit.patient_id,
                    FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
                )
             ) AS total
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND {$meaningfulNonCreationCondition}
               AND {$auditRangeCondition}"
        );

        $auditEventsCount =
            (int) ($discreteActivityRow['total'] ?? 0)
            + $patientCreationCount
            + $patientChartSessions;
    }

    $clinicalChangesRow = sqlQuery(
        "SELECT COUNT(
            DISTINCT CONCAT_WS(
                '|',
                audit.user,
                audit.event,
                audit.category,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
            )
         ) AS total
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND audit.success = 1
           AND audit.event IN (
               'patient-record-insert',
               'patient-record-update',
               'patient-record-delete',
               'patient-record-replace'
           )
           AND NOT ({$patientCreationBundleCondition})
           AND audit.patient_id > 0
           AND {$auditRangeCondition}"
    );

    $clinicalChangeCount =
        (int) ($clinicalChangesRow['total'] ?? 0)
        + $patientCreationCount;

    $schedulingChangesRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND audit.success = 1
           AND audit.event IN (
               'scheduling-insert',
               'scheduling-update',
               'scheduling-delete'
           )
           AND {$auditRangeCondition}"
    );

    $schedulingChangeCount = (int) (
        $schedulingChangesRow['total']
        ?? 0
    );

    $moduleEventsRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.openemr_user_id = events.openemr_user_id
         WHERE education_users.track_activity = 1
           AND {$moduleRangeCondition}"
    );

    $moduleEventsCount = (int) ($moduleEventsRow['total'] ?? 0);

    $lastCohortAudit = sqlQuery(
        "SELECT
            audit.user,
            audit.event,
            audit.category,
            audit.patient_id,
            audit.date
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         WHERE education_users.track_activity = 1
           AND {$effectiveAuditCondition}
         ORDER BY audit.date DESC, audit.id DESC
         LIMIT 1"
    );

    if ($scopeKey === 'meaningful') {
        $discreteActivityStatement = sqlStatement(
            "SELECT
                audit.user,
                audit.event,
                audit.category,
                audit.patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND {$meaningfulNonCreationCondition}
               AND {$auditRangeCondition}
             GROUP BY
                audit.user,
                audit.event,
                audit.category,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
             ORDER BY date DESC
             LIMIT 60"
        );

        while ($row = sqlFetchArray($discreteActivityStatement)) {
            $recentCohortAudit[] = $row;
        }

        $patientCreationStatement = sqlStatement(
            "SELECT
                audit.user,
                'patient-record-insert' AS event,
                'Patient Record' AS category,
                MAX(audit.patient_id) AS patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND {$patientCreationBundleCondition}
               AND {$auditRangeCondition}
             GROUP BY
                audit.user,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 10)
             ORDER BY date DESC
             LIMIT 60"
        );

        while ($row = sqlFetchArray($patientCreationStatement)) {
            $recentCohortAudit[] = $row;
        }

        $chartSessionStatement = sqlStatement(
            "SELECT
                audit.user,
                'patient-chart-session' AS event,
                'Patient Chart' AS category,
                audit.patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND {$meaningfulPatientChartCondition}
               AND {$auditRangeCondition}
             GROUP BY
                audit.user,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 1800)
             ORDER BY date DESC
             LIMIT 60"
        );

        while ($row = sqlFetchArray($chartSessionStatement)) {
            $recentCohortAudit[] = $row;
        }

        usort(
            $recentCohortAudit,
            static function (array $left, array $right): int {
                return strcmp($right['date'], $left['date']);
            }
        );

        $recentCohortAudit = array_slice($recentCohortAudit, 0, 30);

        if (!empty($recentCohortAudit)) {
            $lastCohortAudit = $recentCohortAudit[0];
        }

        if ($patientChartSessions > 0) {
            $auditBreakdown[] = [
                'event' => 'patient-chart-session',
                'category' => 'Patient Chart',
                'total' => $patientChartSessions
            ];
        }

        if ($patientCreationCount > 0) {
            $auditBreakdown[] = [
                'event' => 'patient-record-insert',
                'category' => 'Patient Record',
                'total' => $patientCreationCount
            ];
        }

        $auditBreakdownStatement = sqlStatement(
            "SELECT
                audit.event,
                audit.category,
                COUNT(
                    DISTINCT CONCAT_WS(
                        '|',
                        audit.user,
                        audit.patient_id,
                        FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
                    )
                ) AS total
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND {$meaningfulNonCreationCondition}
               AND {$auditRangeCondition}
             GROUP BY audit.event, audit.category
             ORDER BY total DESC, audit.event, audit.category
             LIMIT 14"
        );
    } else {
        $recentAuditStatement = sqlStatement(
            "SELECT
                audit.user,
                audit.event,
                audit.category,
                audit.patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND {$auditScopeCondition}
               AND {$auditRangeCondition}
             GROUP BY
                audit.user,
                audit.event,
                audit.category,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 300)
             ORDER BY date DESC
             LIMIT 30"
        );

        while ($row = sqlFetchArray($recentAuditStatement)) {
            $recentCohortAudit[] = $row;
        }

        $auditBreakdownStatement = sqlStatement(
            "SELECT
                audit.event,
                audit.category,
                COUNT(*) AS total
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND {$auditScopeCondition}
               AND {$auditRangeCondition}
             GROUP BY audit.event, audit.category
             ORDER BY total DESC, audit.event, audit.category
             LIMIT 15"
        );
    }

    while ($row = sqlFetchArray($auditBreakdownStatement)) {
        $auditBreakdown[] = $row;
    }
}

$myActiveDays = 0;
$myAuditEvents = 0;
$myLogins = 0;
$myPatientChartSessions = 0;
$myClinicalChanges = 0;
$myModuleEvents = 0;
$myLastAudit = false;
$myRecentAudit = [];

if (
    !$canManageEducation &&
    $isTrackedStudent &&
    $currentUsername !== ''
) {
    $myActiveDaysRow = sqlQuery(
        "SELECT COUNT(DISTINCT DATE(audit.date)) AS total
         FROM log AS audit
         WHERE audit.user = ?
           AND {$effectiveAuditCondition}
           AND {$auditRangeCondition}",
        [$currentUsername]
    );

    $myActiveDays = (int) ($myActiveDaysRow['total'] ?? 0);

    $myAuditEventsRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM log AS audit
         WHERE audit.user = ?
           AND {$auditScopeCondition}
           AND {$auditRangeCondition}",
        [$currentUsername]
    );

    $myAuditEvents = (int) ($myAuditEventsRow['total'] ?? 0);

    $myLoginsRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM log AS audit
         WHERE audit.user = ?
           AND audit.success = 1
           AND audit.event = 'login'
           AND {$auditRangeCondition}",
        [$currentUsername]
    );

    $myLogins = (int) ($myLoginsRow['total'] ?? 0);

    $myPatientSessionsRow = sqlQuery(
        "SELECT COUNT(
            DISTINCT CONCAT_WS(
                '|',
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 1800)
            )
         ) AS total
         FROM log AS audit
         WHERE audit.user = ?
           AND {$meaningfulPatientChartCondition}
           AND {$auditRangeCondition}",
        [$currentUsername]
    );

    $myPatientChartSessions = (int) (
        $myPatientSessionsRow['total']
        ?? 0
    );

    $myPatientCreationRow = sqlQuery(
        "SELECT COUNT(
            DISTINCT FLOOR(UNIX_TIMESTAMP(audit.date) / 10)
         ) AS total
         FROM log AS audit
         WHERE audit.user = ?
           AND {$patientCreationBundleCondition}
           AND {$auditRangeCondition}",
        [$currentUsername]
    );

    $myPatientCreationCount = (int) (
        $myPatientCreationRow['total']
        ?? 0
    );

    if ($scopeKey === 'meaningful') {
        $myDiscreteActivityRow = sqlQuery(
            "SELECT COUNT(
                DISTINCT CONCAT_WS(
                    '|',
                    audit.event,
                    audit.category,
                    audit.patient_id,
                    FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
                )
             ) AS total
             FROM log AS audit
             WHERE audit.user = ?
               AND {$meaningfulNonCreationCondition}
               AND {$auditRangeCondition}",
            [$currentUsername]
        );

        $myAuditEvents =
            (int) ($myDiscreteActivityRow['total'] ?? 0)
            + $myPatientCreationCount
            + $myPatientChartSessions;
    }

    $myClinicalChangesRow = sqlQuery(
        "SELECT COUNT(
            DISTINCT CONCAT_WS(
                '|',
                audit.event,
                audit.category,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
            )
         ) AS total
         FROM log AS audit
         WHERE audit.user = ?
           AND audit.success = 1
           AND audit.event IN (
               'patient-record-insert',
               'patient-record-update',
               'patient-record-delete',
               'patient-record-replace'
           )
           AND NOT ({$patientCreationBundleCondition})
           AND audit.patient_id > 0
           AND {$auditRangeCondition}",
        [$currentUsername]
    );

    $myClinicalChanges =
        (int) ($myClinicalChangesRow['total'] ?? 0)
        + $myPatientCreationCount;

    $myModuleEventsRow = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM mod_maple_grove_education_events AS events
         WHERE events.username = ?
           AND {$moduleRangeCondition}",
        [$currentUsername]
    );

    $myModuleEvents = (int) ($myModuleEventsRow['total'] ?? 0);

    $myLastAudit = sqlQuery(
        "SELECT
            audit.event,
            audit.category,
            audit.patient_id,
            audit.date
         FROM log AS audit
         WHERE audit.user = ?
           AND {$effectiveAuditCondition}
         ORDER BY audit.date DESC, audit.id DESC
         LIMIT 1",
        [$currentUsername]
    );

    if ($scopeKey === 'meaningful') {
        $myDiscreteStatement = sqlStatement(
            "SELECT
                audit.event,
                audit.category,
                audit.patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             WHERE audit.user = ?
               AND {$meaningfulNonCreationCondition}
               AND {$auditRangeCondition}
             GROUP BY
                audit.event,
                audit.category,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
             ORDER BY date DESC
             LIMIT 60",
            [$currentUsername]
        );

        while ($row = sqlFetchArray($myDiscreteStatement)) {
            $myRecentAudit[] = $row;
        }

        $myPatientCreationStatement = sqlStatement(
            "SELECT
                'patient-record-insert' AS event,
                'Patient Record' AS category,
                MAX(audit.patient_id) AS patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             WHERE audit.user = ?
               AND {$patientCreationBundleCondition}
               AND {$auditRangeCondition}
             GROUP BY FLOOR(UNIX_TIMESTAMP(audit.date) / 10)
             ORDER BY date DESC
             LIMIT 60",
            [$currentUsername]
        );

        while ($row = sqlFetchArray($myPatientCreationStatement)) {
            $myRecentAudit[] = $row;
        }

        $myChartSessionStatement = sqlStatement(
            "SELECT
                'patient-chart-session' AS event,
                'Patient Chart' AS category,
                audit.patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             WHERE audit.user = ?
               AND {$meaningfulPatientChartCondition}
               AND {$auditRangeCondition}
             GROUP BY
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 1800)
             ORDER BY date DESC
             LIMIT 60",
            [$currentUsername]
        );

        while ($row = sqlFetchArray($myChartSessionStatement)) {
            $myRecentAudit[] = $row;
        }

        usort(
            $myRecentAudit,
            static function (array $left, array $right): int {
                return strcmp($right['date'], $left['date']);
            }
        );

        $myRecentAudit = array_slice($myRecentAudit, 0, 30);

        if (!empty($myRecentAudit)) {
            $myLastAudit = $myRecentAudit[0];
        }
    } else {
        $myRecentStatement = sqlStatement(
            "SELECT
                audit.event,
                audit.category,
                audit.patient_id,
                MIN(audit.date) AS session_start,
                MAX(audit.date) AS date,
                COUNT(*) AS repeated_count
             FROM log AS audit
             WHERE audit.user = ?
               AND {$auditScopeCondition}
               AND {$auditRangeCondition}
             GROUP BY
                audit.event,
                audit.category,
                audit.patient_id,
                FLOOR(UNIX_TIMESTAMP(audit.date) / 300)
             ORDER BY date DESC
             LIMIT 30",
            [$currentUsername]
        );

        while ($row = sqlFetchArray($myRecentStatement)) {
            $myRecentAudit[] = $row;
        }
    }
}

function renderAuditPatient($patientId): string
{
    $patientId = (int) $patientId;

    return $patientId > 0 ? (string) $patientId : '—';
}

function renderAuditTime(array $event): string
{
    $start = (string) ($event['session_start'] ?? '');
    $end = (string) ($event['date'] ?? '');

    if (
        ($event['event'] ?? '') === 'patient-chart-session' &&
        $start !== '' &&
        $end !== '' &&
        $start !== $end
    ) {
        return $start . ' – ' . $end;
    }

    return $end;
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
                Historical OpenEMR audit activity and education-module events.
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
                Audit Analytics MVP
            </span>
        </div>
    </div>

    <form method="get" class="form-inline mb-3">
        <label for="range" class="mr-2">
            <strong><?php echo xlt('Date Range'); ?></strong>
        </label>

        <select
            class="form-control mr-3"
            id="range"
            name="range"
            onchange="this.form.submit()"
        >
            <?php foreach ($rangeOptions as $key => $option) : ?>
                <option
                    value="<?php echo attr($key); ?>"
                    <?php echo (string) $key === $rangeKey ? 'selected' : ''; ?>
                >
                    <?php echo text($option['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="scope" class="mr-2">
            <strong><?php echo xlt('Audit Scope'); ?></strong>
        </label>

        <select
            class="form-control mr-2"
            id="scope"
            name="scope"
            onchange="this.form.submit()"
        >
            <?php foreach ($scopeOptions as $key => $label) : ?>
                <option
                    value="<?php echo attr($key); ?>"
                    <?php echo $key === $scopeKey ? 'selected' : ''; ?>
                >
                    <?php echo text($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <noscript>
            <button class="btn btn-primary" type="submit">
                <?php echo xlt('Apply'); ?>
            </button>
        </noscript>
    </form>

    <?php if ($scopeKey === 'all') : ?>
        <div class="alert alert-warning">
            All successful audit events includes noisy system activity such as
            repeated security-administration queries. Use the meaningful view
            for normal participation reporting.
        </div>
    <?php else : ?>
        <div class="alert alert-info">
            Meaningful activity excludes patient reads without a patient ID,
            converts repeated reads into 30-minute chart sessions, deduplicates
            identical actions within one minute, and combines the automatic
            demographics, insurance, and history rows created during patient
            registration into one Created Patient Record activity. Brief chart
            reads generated by that same registration are suppressed.
        </div>
    <?php endif; ?>

    <?php if ($canManageEducation) : ?>

        <h2 class="h5 mb-3">
            <?php echo text('Cohort Audit Activity — ' . $rangeLabel); ?>
        </h2>

        <div class="row">
            <div class="col-lg-3 col-md-4 mb-3">
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

            <div class="col-lg-3 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Active Students'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $activeStudentCount); ?>
                        </div>
                        <p class="mb-0 text-muted">
                            Students with audit activity in this range.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt($scopeKey === 'meaningful' ? 'Meaningful Activities' : 'Audit Rows'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $auditEventsCount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Successful Logins'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $loginCount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Patient Chart Sessions'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $patientChartSessions); ?>
                        </div>
                        <p class="mb-0 text-muted">
                            Distinct student/patient activity grouped into 30-minute blocks.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Clinical Record Changes'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $clinicalChangeCount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Scheduling Changes'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $schedulingChangeCount); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Education Module Events'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $moduleEventsCount); ?>
                        </div>
                        <p class="mb-0 text-muted">
                            Events recorded since this module was installed.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-muted">
                    <?php echo xlt('Last Recorded OpenEMR Activity'); ?>
                </h3>

                <?php if ($lastCohortAudit) : ?>
                    <p class="h5 mb-1">
                        <code><?php echo text($lastCohortAudit['user']); ?></code>
                        —
                        <?php
                        echo text(
                            EducationAnalytics::formatAuditEvent(
                                $lastCohortAudit['event'],
                                $lastCohortAudit['category']
                            )
                        );
                        ?>
                    </p>

                    <p class="mb-0 text-muted">
                        <?php echo text($lastCohortAudit['date']); ?>
                        <?php if ((int) $lastCohortAudit['patient_id'] > 0) : ?>
                            · Patient ID
                            <?php
                            echo text(
                                (string) $lastCohortAudit['patient_id']
                            );
                            ?>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="mb-0 text-muted">
                        No matching OpenEMR audit activity was found.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-8 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <strong>
                            <?php
                            echo text(
                                'Recent OpenEMR Activity — ' . $rangeLabel
                            );
                            ?>
                        </strong>
                    </div>

                    <?php if (empty($recentCohortAudit)) : ?>
                        <div class="card-body text-muted">
                            No matching audit activity occurred in this range.
                        </div>
                    <?php else : ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead>
                                <tr>
                                    <th><?php echo xlt('Username'); ?></th>
                                    <th><?php echo xlt('Activity'); ?></th>
                                    <th><?php echo xlt('Patient ID'); ?></th>
                                    <th><?php echo xlt('Time'); ?></th>
                                    <?php if ($scopeKey === 'all') : ?>
                                        <th><?php echo xlt('Grouped Rows'); ?></th>
                                    <?php endif; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentCohortAudit as $event) : ?>
                                    <tr>
                                        <td>
                                            <code>
                                                <?php echo text($event['user']); ?>
                                            </code>
                                        </td>
                                        <td>
                                            <?php
                                            echo text(
                                                EducationAnalytics::formatAuditEvent(
                                                    $event['event'],
                                                    $event['category']
                                                )
                                            );
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            echo text(
                                                renderAuditPatient(
                                                    $event['patient_id']
                                                )
                                            );
                                            ?>
                                        </td>
                                        <td><?php echo text(renderAuditTime($event)); ?></td>
                                        <?php if ($scopeKey === 'all') : ?>
                                            <td>
                                                <?php
                                                echo text(
                                                    (string) $event['repeated_count']
                                                );
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-xl-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <strong><?php echo xlt($scopeKey === 'meaningful' ? 'Activity Breakdown' : 'Audit Breakdown'); ?></strong>
                    </div>

                    <?php if (empty($auditBreakdown)) : ?>
                        <div class="card-body text-muted">
                            No matching audit categories occurred.
                        </div>
                    <?php else : ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($auditBreakdown as $event) : ?>
                                <li
                                    class="list-group-item d-flex justify-content-between align-items-start"
                                >
                                    <span class="mr-2">
                                        <?php
                                        echo text(
                                            EducationAnalytics::formatAuditEvent(
                                                $event['event'],
                                                $event['category']
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
            Your personal dashboard uses your existing OpenEMR audit history.
        </div>

        <h2 class="h5 mb-3">
            <?php echo text('My OpenEMR Activity — ' . $rangeLabel); ?>
        </h2>

        <div class="row">
            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Active Days'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myActiveDays); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt($scopeKey === 'meaningful' ? 'Meaningful Activities' : 'Audit Rows'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myAuditEvents); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Logins'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myLogins); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Chart Sessions'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myPatientChartSessions); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Clinical Changes'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myClinicalChanges); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-muted">
                            <?php echo xlt('Module Events'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $myModuleEvents); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-muted">
                    <?php echo xlt('My Last Recorded OpenEMR Activity'); ?>
                </h3>

                <?php if ($myLastAudit) : ?>
                    <p class="h5 mb-1">
                        <?php
                        echo text(
                            EducationAnalytics::formatAuditEvent(
                                $myLastAudit['event'],
                                $myLastAudit['category']
                            )
                        );
                        ?>
                    </p>
                    <p class="mb-0 text-muted">
                        <?php echo text($myLastAudit['date']); ?>
                        <?php if ((int) $myLastAudit['patient_id'] > 0) : ?>
                            · Patient ID
                            <?php echo text((string) $myLastAudit['patient_id']); ?>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="mb-0 text-muted">
                        No matching OpenEMR audit activity was found.
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

            <?php if (empty($myRecentAudit)) : ?>
                <div class="card-body text-muted">
                    No matching audit activity occurred in this range.
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                        <tr>
                            <th><?php echo xlt('Activity'); ?></th>
                            <th><?php echo xlt('Patient ID'); ?></th>
                            <th><?php echo xlt('Time'); ?></th>
                            <?php if ($scopeKey === 'all') : ?>
                                <th><?php echo xlt('Grouped Rows'); ?></th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($myRecentAudit as $event) : ?>
                            <tr>
                                <td>
                                    <?php
                                    echo text(
                                        EducationAnalytics::formatAuditEvent(
                                            $event['event'],
                                            $event['category']
                                        )
                                    );
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    echo text(
                                        renderAuditPatient(
                                            $event['patient_id']
                                        )
                                    );
                                    ?>
                                </td>
                                <td><?php echo text(renderAuditTime($event)); ?></td>
                                <?php if ($scopeKey === 'all') : ?>
                                    <td>
                                        <?php
                                        echo text(
                                            (string) $event['repeated_count']
                                        );
                                        ?>
                                    </td>
                                <?php endif; ?>
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

<script>
document.addEventListener("DOMContentLoaded", function () {
    const timestampRangePattern =
        /^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\s+–\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}))?$/;

    const formatLocalTimestamp = function (value) {
        // OpenEMR audit timestamps currently appear to be stored as UTC.
        const utcDate = new Date(value.replace(" ", "T") + "Z");

        if (Number.isNaN(utcDate.getTime())) {
            return value;
        }

        return utcDate.toLocaleString(undefined, {
            dateStyle: "medium",
            timeStyle: "medium"
        });
    };

    const walker = document.createTreeWalker(
        document.body,
        NodeFilter.SHOW_TEXT
    );

    const textNodes = [];

    while (walker.nextNode()) {
        textNodes.push(walker.currentNode);
    }

    textNodes.forEach(function (node) {
        const value = node.nodeValue.trim();
        const match = value.match(timestampRangePattern);

        if (!match) {
            return;
        }

        const start = formatLocalTimestamp(match[1]);
        const end = match[2] ? formatLocalTimestamp(match[2]) : '';

        node.nodeValue = end === '' ? start : start + ' – ' + end;
    });
});
</script>

</body>
</html>
