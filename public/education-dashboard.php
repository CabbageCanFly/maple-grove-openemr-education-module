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
$canViewPatientDemographics = EducationAnalytics::canViewPatientDemographics();

EducationAnalytics::recordTrackedEvent(
    $currentUserId,
    $currentUsername,
    'dashboard_opened',
    [],
    5
);

$rangeOptions = [
    'today' => 'Today',
    '7' => 'Last 7 Days',
    '30' => 'Last 30 Days',
    'all' => 'All Available History',
    'custom' => 'Custom Dates'
];

$scopeOptions = [
    'meaningful' => 'Meaningful Student Activity',
    'all' => 'All Successful Audit Events'
];

function dashboardValidDate(string $value): bool
{
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
        return false;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

$rangeKey = (string) ($_GET['range'] ?? '7');
$scopeKey = (string) ($_GET['scope'] ?? 'meaningful');
$customStartDate = trim((string) ($_GET['start_date'] ?? ''));
$customEndDate = trim((string) ($_GET['end_date'] ?? ''));
$dateRangeMessage = '';

if (!isset($rangeOptions[$rangeKey])) {
    $rangeKey = '7';
}

if (!isset($scopeOptions[$scopeKey])) {
    $scopeKey = 'meaningful';
}

if (!dashboardValidDate($customStartDate)) {
    $customStartDate = date('Y-m-d', strtotime('-6 days'));
}

if (!dashboardValidDate($customEndDate)) {
    $customEndDate = date('Y-m-d');
}

if ($customStartDate > $customEndDate) {
    [$customStartDate, $customEndDate] = [$customEndDate, $customStartDate];
    if ($rangeKey === 'custom') {
        $dateRangeMessage = 'Start and end dates were reversed so the earlier date comes first.';
    }
}

$rangeLabel = $rangeOptions[$rangeKey];

if ($rangeKey === 'custom') {
    // Both values are strict YYYY-MM-DD dates at this point, so interpolation
    // cannot introduce arbitrary SQL. The end date is inclusive.
    $auditRangeCondition = "audit.date >= '{$customStartDate} 00:00:00'\n        AND audit.date < DATE_ADD('{$customEndDate} 00:00:00', INTERVAL 1 DAY)";
    $moduleRangeCondition = "events.created_at >= '{$customStartDate} 00:00:00'\n        AND events.created_at < DATE_ADD('{$customEndDate} 00:00:00', INTERVAL 1 DAY)";
    $rangeLabel = $customStartDate . ' – ' . $customEndDate;
} elseif ($rangeKey === 'today') {
    $auditRangeCondition = 'audit.date >= CURDATE()';
    $moduleRangeCondition = 'events.created_at >= CURDATE()';
} elseif ($rangeKey === '7') {
    $auditRangeCondition = 'audit.date >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    $moduleRangeCondition = 'events.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($rangeKey === '30') {
    $auditRangeCondition = 'audit.date >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    $moduleRangeCondition = 'events.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
} else {
    $auditRangeCondition = '1 = 1';
    $moduleRangeCondition = '1 = 1';
}

$sharedRangeQuery = http_build_query(array_filter([
    'range' => $rangeKey,
    'scope' => $scopeKey,
    'start_date' => $rangeKey === 'custom' ? $customStartDate : '',
    'end_date' => $rangeKey === 'custom' ? $customEndDate : ''
]));

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

/**
 * Build a small, human-readable activity feed from a bounded set of recent
 * audit rows. This intentionally happens in PHP so the database does not have
 * to GROUP BY calculated time buckets across the entire audit history just to
 * render the recent-activity table.
 */
function normalizeRecentAuditRows(array $rows, string $scopeKey): array
{
    if ($scopeKey === 'all') {
        $activities = [];

        foreach ($rows as $row) {
            $event = (string) ($row['event'] ?? '');
            $category = trim((string) ($row['category'] ?? ''));
            $patientId = (int) ($row['patient_id'] ?? 0);
            $date = (string) ($row['date'] ?? '');
            $timestamp = strtotime($date . ' UTC');

            if ($date === '' || $timestamp === false) {
                continue;
            }

            $bucket = intdiv($timestamp, 300);
            $key = implode('|', [
                'raw',
                $event,
                $category,
                (string) $patientId,
                (string) $bucket
            ]);

            if (!isset($activities[$key])) {
                $activities[$key] = [
                    'user' => (string) ($row['user'] ?? ''),
                    'event' => $event,
                    'category' => $category,
                    'patient_id' => $patientId,
                    'session_start' => $date,
                    'date' => $date,
                    'repeated_count' => 1
                ];
            } else {
                $activities[$key]['repeated_count']++;

                if ($date < $activities[$key]['session_start']) {
                    $activities[$key]['session_start'] = $date;
                }

                if ($date > $activities[$key]['date']) {
                    $activities[$key]['date'] = $date;
                }
            }
        }

        $activities = array_values($activities);

        usort(
            $activities,
            static function (array $left, array $right): int {
                return strcmp($right['date'], $left['date']);
            }
        );

        return $activities;
    }

    $activities = [];
    $chartRowsByPatient = [];

    foreach ($rows as $row) {
        $event = (string) ($row['event'] ?? '');
        $category = trim((string) ($row['category'] ?? ''));
        $patientId = (int) ($row['patient_id'] ?? 0);
        $date = (string) ($row['date'] ?? '');
        $timestamp = strtotime($date . ' UTC');

        if ($date === '' || $timestamp === false) {
            continue;
        }

        if (
            in_array($event, ['patient-record-select', 'patient-access'], true)
        ) {
            if ($patientId > 0) {
                $chartRowsByPatient[$patientId][] = $row;
            }

            continue;
        }

        $isCreationBundle =
            $event === 'patient-record-insert'
            && in_array(
                $category,
                [
                    'Patient Demographics',
                    'Patient Insurance',
                    'Social and Family History'
                ],
                true
            );

        if ($isCreationBundle) {
            $bucket = intdiv($timestamp, 10);
            $key = 'patient-create|' . $bucket;
            $event = 'patient-record-insert';
            $category = 'Patient Record';
        } else {
            if (
                in_array(
                    $event,
                    [
                        'patient-record-insert',
                        'patient-record-update',
                        'patient-record-delete',
                        'patient-record-replace'
                    ],
                    true
                )
                && $patientId <= 0
            ) {
                continue;
            }

            $bucket = intdiv($timestamp, 60);
            $key = implode('|', [
                'activity',
                $event,
                $category,
                (string) $patientId,
                (string) $bucket
            ]);
        }

        if (!isset($activities[$key])) {
            $activities[$key] = [
                'user' => (string) ($row['user'] ?? ''),
                'event' => $event,
                'category' => $category,
                'patient_id' => $patientId,
                'session_start' => $date,
                'date' => $date,
                'repeated_count' => 1
            ];
        } else {
            $activities[$key]['repeated_count']++;

            if ($date < $activities[$key]['session_start']) {
                $activities[$key]['session_start'] = $date;
            }

            if ($date > $activities[$key]['date']) {
                $activities[$key]['date'] = $date;
            }

            if (
                $activities[$key]['patient_id'] <= 0
                && $patientId > 0
            ) {
                $activities[$key]['patient_id'] = $patientId;
            }
        }
    }

    foreach ($chartRowsByPatient as $rowsForPatient) {
        usort(
            $rowsForPatient,
            static function (array $left, array $right): int {
                return strcmp((string) $right['date'], (string) $left['date']);
            }
        );

        $session = null;
        $oldestTimestamp = null;

        foreach ($rowsForPatient as $row) {
            $date = (string) ($row['date'] ?? '');
            $timestamp = strtotime($date . ' UTC');

            if ($date === '' || $timestamp === false) {
                continue;
            }

            if (
                $session === null
                || $oldestTimestamp === null
                || ($oldestTimestamp - $timestamp) > 1800
            ) {
                if ($session !== null) {
                    $activities[] = $session;
                }

                $session = [
                    'user' => (string) ($row['user'] ?? ''),
                    'event' => 'patient-chart-session',
                    'category' => 'Patient Chart',
                    'patient_id' => (int) ($row['patient_id'] ?? 0),
                    'session_start' => $date,
                    'date' => $date,
                    'repeated_count' => 1
                ];
                $oldestTimestamp = $timestamp;
                continue;
            }

            $session['session_start'] = $date;
            $session['repeated_count']++;
            $oldestTimestamp = $timestamp;
        }

        if ($session !== null) {
            $activities[] = $session;
        }
    }

    $activities = array_values($activities);
    $creationTimesByPatient = [];

    foreach ($activities as $activity) {
        if (
            $activity['event'] === 'patient-record-insert'
            && $activity['category'] === 'Patient Record'
            && (int) $activity['patient_id'] > 0
        ) {
            $creationTimesByPatient[(int) $activity['patient_id']][] =
                strtotime($activity['date'] . ' UTC');
        }
    }

    foreach ($activities as $key => $activity) {
        if ($activity['event'] !== 'patient-chart-session') {
            continue;
        }

        $patientId = (int) $activity['patient_id'];
        $sessionStart = strtotime($activity['session_start'] . ' UTC');
        $sessionEnd = strtotime($activity['date'] . ' UTC');

        foreach ($creationTimesByPatient[$patientId] ?? [] as $createdAt) {
            if (
                $sessionStart !== false
                && $sessionEnd !== false
                && $createdAt !== false
                && $createdAt >= ($sessionStart - 60)
                && $createdAt <= ($sessionEnd + 60)
            ) {
                unset($activities[$key]);
                break;
            }
        }
    }

    $activities = array_values($activities);

    usort(
        $activities,
        static function (array $left, array $right): int {
            return strcmp($right['date'], $left['date']);
        }
    );

    return $activities;
}

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
    $trackedStudents = [];
    $trackedStudentStatement = sqlStatement(
        "SELECT username
         FROM mod_maple_grove_education_users
         WHERE track_activity = 1
         ORDER BY username"
    );

    while ($row = sqlFetchArray($trackedStudentStatement)) {
        $username = trim((string) ($row['username'] ?? ''));

        if ($username !== '') {
            $trackedStudents[] = $username;
        }
    }

    $trackedStudentCount = count($trackedStudents);

    $cacheKey = hash(
        'sha256',
        implode('|', $trackedStudents) . '|' . $rangeKey . '|' . $scopeKey . '|' . $customStartDate . '|' . $customEndDate
    );
    $cacheTtlSeconds = 60;
    $cachedAnalytics = $_SESSION['maple_grove_education_analytics_cache'][$cacheKey] ?? null;

    if (
        is_array($cachedAnalytics)
        && isset($cachedAnalytics['cached_at'])
        && (time() - (int) $cachedAnalytics['cached_at']) <= $cacheTtlSeconds
    ) {
        $activeStudentCount = (int) $cachedAnalytics['activeStudentCount'];
        $auditEventsCount = (int) $cachedAnalytics['auditEventsCount'];
        $loginCount = (int) $cachedAnalytics['loginCount'];
        $patientChartSessions = (int) $cachedAnalytics['patientChartSessions'];
        $clinicalChangeCount = (int) $cachedAnalytics['clinicalChangeCount'];
        $schedulingChangeCount = (int) $cachedAnalytics['schedulingChangeCount'];
        $moduleEventsCount = (int) $cachedAnalytics['moduleEventsCount'];
        $lastCohortAudit = $cachedAnalytics['lastCohortAudit'];
        $recentCohortAudit = $cachedAnalytics['recentCohortAudit'];
        $auditBreakdown = $cachedAnalytics['auditBreakdown'];
    } else {
        /*
         * One broad aggregate scan replaces several separate COUNT queries.
         * The user/date and date/user indexes added for this module make the
         * range and tracked-user filtering substantially cheaper.
         */
        $summaryRow = sqlQuery(
            "SELECT
                COUNT(DISTINCT audit.user) AS active_students,
                COUNT(*) AS raw_rows,
                SUM(CASE WHEN audit.event = 'login' THEN 1 ELSE 0 END) AS logins,
                COUNT(
                    DISTINCT CASE
                        WHEN ({$meaningfulNonCreationCondition}) THEN CONCAT_WS(
                            '|',
                            audit.user,
                            audit.event,
                            audit.category,
                            audit.patient_id,
                            FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
                        )
                        ELSE NULL
                    END
                ) AS discrete_activities,
                COUNT(
                    DISTINCT CASE
                        WHEN ({$patientCreationBundleCondition}) THEN CONCAT_WS(
                            '|',
                            audit.user,
                            FLOOR(UNIX_TIMESTAMP(audit.date) / 10)
                        )
                        ELSE NULL
                    END
                ) AS patient_creations,
                COUNT(
                    DISTINCT CASE
                        WHEN audit.event IN (
                            'patient-record-insert',
                            'patient-record-update',
                            'patient-record-delete',
                            'patient-record-replace'
                        )
                        AND NOT ({$patientCreationBundleCondition})
                        AND audit.patient_id > 0
                        THEN CONCAT_WS(
                            '|',
                            audit.user,
                            audit.event,
                            audit.category,
                            audit.patient_id,
                            FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
                        )
                        ELSE NULL
                    END
                ) AS clinical_changes,
                COUNT(
                    DISTINCT CASE
                        WHEN audit.event IN (
                            'scheduling-insert',
                            'scheduling-update',
                            'scheduling-delete'
                        )
                        THEN CONCAT_WS(
                            '|',
                            audit.user,
                            audit.event,
                            audit.patient_id,
                            FLOOR(UNIX_TIMESTAMP(audit.date) / 60)
                        )
                        ELSE NULL
                    END
                ) AS scheduling_changes
             FROM log AS audit
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.username = audit.user
             WHERE education_users.track_activity = 1
               AND audit.success = 1
               AND {$auditRangeCondition}"
        );

        $activeStudentCount = (int) ($summaryRow['active_students'] ?? 0);
        $loginCount = (int) ($summaryRow['logins'] ?? 0);
        $patientCreationCount = (int) ($summaryRow['patient_creations'] ?? 0);
        $clinicalChangeCount =
            (int) ($summaryRow['clinical_changes'] ?? 0)
            + $patientCreationCount;
        $schedulingChangeCount = (int) ($summaryRow['scheduling_changes'] ?? 0);

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
               AND {$patientChartCondition}
               AND {$auditRangeCondition}"
        );

        $patientChartSessions = (int) ($patientSessionsRow['total'] ?? 0);

        if ($scopeKey === 'meaningful') {
            $auditEventsCount =
                (int) ($summaryRow['discrete_activities'] ?? 0)
                + $patientCreationCount
                + $patientChartSessions;
        } else {
            $auditEventsCount = (int) ($summaryRow['raw_rows'] ?? 0);
        }

        $moduleEventsRow = sqlQuery(
            "SELECT COUNT(*) AS total
             FROM mod_maple_grove_education_events AS events
             INNER JOIN mod_maple_grove_education_users AS education_users
                 ON education_users.openemr_user_id = events.openemr_user_id
             WHERE education_users.track_activity = 1
               AND {$moduleRangeCondition}"
        );

        $moduleEventsCount = (int) ($moduleEventsRow['total'] ?? 0);

        /*
         * Build a fair recent feed. Each tracked student contributes at most
         * three normalized activities, preventing one very active account from
         * filling the entire cohort table. Each per-user lookup is bounded and
         * uses the user/date index instead of globally grouping the full range.
         */
        $recentPerStudent = 3;
        $candidateRowsPerStudent = 250;
        $candidateCondition = $scopeKey === 'all'
            ? 'audit.success = 1'
            : EducationAnalytics::meaningfulAuditCondition('audit');

        foreach ($trackedStudents as $trackedUsername) {
            $candidateRows = [];
            $candidateStatement = sqlStatement(
                "SELECT
                    audit.user,
                    audit.event,
                    audit.category,
                    audit.patient_id,
                    audit.date,
                    audit.id
                 FROM log AS audit
                 WHERE audit.user = ?
                   AND {$candidateCondition}
                   AND {$auditRangeCondition}
                 ORDER BY audit.date DESC, audit.id DESC
                 LIMIT {$candidateRowsPerStudent}",
                [$trackedUsername]
            );

            while ($row = sqlFetchArray($candidateStatement)) {
                $candidateRows[] = $row;
            }

            $normalizedRows = normalizeRecentAuditRows(
                $candidateRows,
                $scopeKey
            );

            foreach (
                array_slice($normalizedRows, 0, $recentPerStudent)
                as $activity
            ) {
                $recentCohortAudit[] = $activity;
            }
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

        /*
         * The side breakdown now reflects the displayed recent feed. This
         * avoids another expensive full-range GROUP BY while still showing the
         * mix of activity represented in the table beside it.
         */
        $breakdownCounts = [];

        foreach ($recentCohortAudit as $activity) {
            $key = ($activity['event'] ?? '') . '|' . ($activity['category'] ?? '');

            if (!isset($breakdownCounts[$key])) {
                $breakdownCounts[$key] = [
                    'event' => (string) ($activity['event'] ?? ''),
                    'category' => (string) ($activity['category'] ?? ''),
                    'total' => 0
                ];
            }

            $breakdownCounts[$key]['total']++;
        }

        $auditBreakdown = array_values($breakdownCounts);

        usort(
            $auditBreakdown,
            static function (array $left, array $right): int {
                return ((int) $right['total']) <=> ((int) $left['total']);
            }
        );

        $_SESSION['maple_grove_education_analytics_cache'][$cacheKey] = [
            'cached_at' => time(),
            'activeStudentCount' => $activeStudentCount,
            'auditEventsCount' => $auditEventsCount,
            'loginCount' => $loginCount,
            'patientChartSessions' => $patientChartSessions,
            'clinicalChangeCount' => $clinicalChangeCount,
            'schedulingChangeCount' => $schedulingChangeCount,
            'moduleEventsCount' => $moduleEventsCount,
            'lastCohortAudit' => $lastCohortAudit,
            'recentCohortAudit' => $recentCohortAudit,
            'auditBreakdown' => $auditBreakdown
        ];
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

$patientDirectoryActivities = [];

if ($canManageEducation) {
    $patientDirectoryActivities = $recentCohortAudit;

    if ($lastCohortAudit) {
        $patientDirectoryActivities[] = $lastCohortAudit;
    }
} elseif ($isTrackedStudent) {
    $patientDirectoryActivities = $myRecentAudit;

    if ($myLastAudit) {
        $patientDirectoryActivities[] = $myLastAudit;
    }
}

$patientDirectory = $canViewPatientDemographics
    ? loadPatientDirectory($patientDirectoryActivities)
    : [];

function loadPatientDirectory(array $activities): array
{
    $patientIds = [];

    foreach ($activities as $activity) {
        $patientId = (int) ($activity['patient_id'] ?? 0);

        if ($patientId > 0) {
            $patientIds[$patientId] = $patientId;
        }
    }

    if (empty($patientIds)) {
        return [];
    }

    $patientIds = array_values($patientIds);
    $placeholders = implode(',', array_fill(0, count($patientIds), '?'));
    $statement = sqlStatement(
        "SELECT pid, pubpid, fname, mname, lname
         FROM patient_data
         WHERE pid IN ({$placeholders})",
        $patientIds
    );

    $directory = [];

    while ($row = sqlFetchArray($statement)) {
        $pid = (int) ($row['pid'] ?? 0);

        if ($pid <= 0) {
            continue;
        }

        $nameParts = array_filter(
            [
                trim((string) ($row['fname'] ?? '')),
                trim((string) ($row['mname'] ?? '')),
                trim((string) ($row['lname'] ?? ''))
            ],
            static fn(string $part): bool => $part !== ''
        );

        $directory[$pid] = [
            'name' => trim(implode(' ', $nameParts)),
            'pubpid' => trim((string) ($row['pubpid'] ?? ''))
        ];
    }

    return $directory;
}

function renderAuditPatient(
    $patientId,
    array $patientDirectory,
    bool $canViewPatientDemographics
): string {
    $patientId = (int) $patientId;

    if ($patientId <= 0) {
        return text('—');
    }

    if (
        !$canViewPatientDemographics
        || !isset($patientDirectory[$patientId])
    ) {
        return text('PID ' . $patientId);
    }

    $patient = $patientDirectory[$patientId];
    $name = trim((string) ($patient['name'] ?? ''));
    $pubpid = trim((string) ($patient['pubpid'] ?? ''));

    if ($name === '') {
        $name = 'Patient ' . $patientId;
    }

    $url = '../../../../patient_file/summary/demographics.php?set_pid='
        . rawurlencode((string) $patientId);

    $secondaryId = $pubpid !== ''
        ? 'ID ' . $pubpid
        : 'PID ' . $patientId;

    return '<a href="' . attr($url) . '"'
        . ' onclick="return openPatientDashboard(' . $patientId . ', this.href);">'
        . text($name)
        . '</a>'
        . '<small class="text-muted d-block">'
        . text($secondaryId)
        . '</small>';
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

    <style>
        #dashboard-loading-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.82);
        }

        #dashboard-loading-overlay.is-visible {
            display: flex;
        }

        .dashboard-loading-card {
            min-width: 230px;
            padding: 1.25rem 1.5rem;
            text-align: center;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 0.4rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.12);
        }
        .dashboard-custom-date-fields {
            display: none;
            align-items: flex-end;
        }

        .dashboard-custom-date-fields.is-visible {
            display: flex;
        }
    </style>
</head>

<body class="body_top">
<div id="dashboard-loading-overlay" aria-live="polite" aria-busy="true">
    <div class="dashboard-loading-card">
        <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
        <div><strong><?php echo xlt('Loading dashboard…'); ?></strong></div>
        <div class="small text-muted mt-1"><?php echo xlt('Processing OpenEMR activity.'); ?></div>
    </div>
</div>
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
            <?php if ($canManageEducation || $isTrackedStudent) : ?>
                <a
                    class="btn btn-primary mr-2"
                    href="activity-explorer.php?<?php echo attr($sharedRangeQuery); ?>"
                    onclick="showDashboardLoading()"
                >
                    <?php echo xlt('Explore Activity'); ?>
                </a>
            <?php endif; ?>

            <?php if ($canManageEducation) : ?>
                <a
                    class="btn btn-outline-primary mr-2"
                    href="manage-education-users.php?<?php echo attr(http_build_query(array_filter([
                        'return_range' => $rangeKey,
                        'return_scope' => $scopeKey,
                        'return_start_date' => $rangeKey === 'custom' ? $customStartDate : '',
                        'return_end_date' => $rangeKey === 'custom' ? $customEndDate : ''
                    ]))); ?>"
                >
                    <?php echo xlt('Manage Education Users'); ?>
                </a>
            <?php endif; ?>

            <span class="badge badge-secondary p-2">
                Audit Analytics MVP
            </span>
        </div>
    </div>

    <form method="get" class="form-inline mb-3" id="analytics-filter-form">
        <label for="range" class="mr-2">
            <strong><?php echo xlt('Date Range'); ?></strong>
        </label>

        <select
            class="form-control mr-3"
            id="range"
            name="range"
            onchange="handleDashboardRangeChange(this)"
        >
            <?php foreach ($rangeOptions as $key => $label) : ?>
                <option
                    value="<?php echo attr($key); ?>"
                    <?php echo (string) $key === $rangeKey ? 'selected' : ''; ?>
                >
                    <?php echo text($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div
            class="dashboard-custom-date-fields mr-3 <?php echo $rangeKey === 'custom' ? 'is-visible' : ''; ?>"
            id="dashboard-custom-date-fields"
        >
            <div class="mr-2">
                <label for="start_date" class="small mb-0 d-block"><?php echo xlt('Start'); ?></label>
                <input
                    class="form-control"
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?php echo attr($customStartDate); ?>"
                >
            </div>
            <div class="mr-2">
                <label for="end_date" class="small mb-0 d-block"><?php echo xlt('End'); ?></label>
                <input
                    class="form-control"
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?php echo attr($customEndDate); ?>"
                >
            </div>
            <button
                class="btn btn-primary"
                type="submit"
                onclick="showDashboardLoading()"
            >
                <?php echo xlt('Apply Dates'); ?>
            </button>
        </div>

        <label for="scope" class="mr-2">
            <strong><?php echo xlt('Audit Scope'); ?></strong>
        </label>

        <select
            class="form-control mr-2"
            id="scope"
            name="scope"
            onchange="showDashboardLoading(); this.form.submit()"
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

    <?php if ($dateRangeMessage !== '') : ?>
        <div class="alert alert-warning py-2">
            <?php echo text($dateRangeMessage); ?>
        </div>
    <?php endif; ?>

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
                            <?php echo xlt($scopeKey === 'meaningful' ? 'Meaningful Activity Events' : 'Audit Rows'); ?>
                        </h3>
                        <div class="display-4">
                            <?php echo text((string) $auditEventsCount); ?>
                        </div>
                        <?php if ($scopeKey === 'meaningful') : ?>
                            <p class="mb-0 text-muted">
                                Normalized activity; logins and logouts count separately.
                            </p>
                        <?php endif; ?>
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
                            Approximate chart-activity blocks for the same student and patient.
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
                            · <?php
                            echo renderAuditPatient(
                                $lastCohortAudit['patient_id'],
                                $patientDirectory,
                                $canViewPatientDemographics
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
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                        <strong>
                            <?php
                            echo text(
                                'Recent Activity Preview — ' . $rangeLabel
                            );
                            ?>
                        </strong>
                        <a
                            class="btn btn-sm btn-outline-primary mt-1 mt-sm-0"
                            href="activity-explorer.php?<?php echo attr($sharedRangeQuery); ?>"
                            onclick="showDashboardLoading()"
                        >
                            <?php echo xlt('Browse All Activity'); ?>
                        </a>
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
                                    <th><?php echo xlt('Patient'); ?></th>
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
                                            echo renderAuditPatient(
                                                $event['patient_id'],
                                                $patientDirectory,
                                                $canViewPatientDemographics
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
                        <strong><?php echo xlt($scopeKey === 'meaningful' ? 'Recent Activity Mix' : 'Recent Audit Mix'); ?></strong>
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
                            <?php echo xlt($scopeKey === 'meaningful' ? 'Meaningful Activity Events' : 'Audit Rows'); ?>
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
                            · <?php
                            echo renderAuditPatient(
                                $myLastAudit['patient_id'],
                                $patientDirectory,
                                $canViewPatientDemographics
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

        <div class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                <strong>
                    <?php echo text('My Recent Activity Preview — ' . $rangeLabel); ?>
                </strong>
                <a
                    class="btn btn-sm btn-outline-primary mt-1 mt-sm-0"
                    href="activity-explorer.php?<?php echo attr($sharedRangeQuery); ?>"
                    onclick="showDashboardLoading()"
                >
                    <?php echo xlt('Browse All Activity'); ?>
                </a>
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
                            <th><?php echo xlt('Patient'); ?></th>
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
                                    echo renderAuditPatient(
                                        $event['patient_id'],
                                        $patientDirectory,
                                        $canViewPatientDemographics
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
function openPatientDashboard(patientId, fallbackUrl) {
    if (top.restoreSession) {
        top.restoreSession();
    }

    const patientDashboardUrl =
        'patient_file/summary/demographics.php?set_pid='
        + encodeURIComponent(String(patientId));

    const leftNav =
        top.left_nav && typeof top.left_nav.loadFrame === 'function'
            ? top.left_nav
            : (
                parent.left_nav
                && typeof parent.left_nav.loadFrame === 'function'
                    ? parent.left_nav
                    : null
            );

    if (leftNav) {
        leftNav.loadFrame('dem1', 'RTop', patientDashboardUrl);
        return false;
    }

    window.location.href = fallbackUrl;
    return false;
}

function handleDashboardRangeChange(select) {
    const customFields = document.getElementById("dashboard-custom-date-fields");

    if (select.value === "custom") {
        if (customFields) {
            customFields.classList.add("is-visible");
        }
        return;
    }

    if (customFields) {
        customFields.classList.remove("is-visible");
    }

    showDashboardLoading();
    select.form.submit();
}

function showDashboardLoading() {
    const overlay = document.getElementById("dashboard-loading-overlay");

    if (overlay) {
        overlay.classList.add("is-visible");
    }
}

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
