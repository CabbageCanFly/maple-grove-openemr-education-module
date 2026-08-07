<?php

/**
 * Maple Grove Education Activity Explorer
 *
 * Server-side, cursor-paginated browsing of OpenEMR audit activity.
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

if (!$canManageEducation && !$isTrackedStudent) {
    http_response_code(403);
    echo xlt('You are not authorized to view education activity.');
    exit;
}

$rangeOptions = [
    'today' => [
        'label' => 'Today',
        'audit_condition' => 'audit.date >= CURDATE()'
    ],
    '7' => [
        'label' => 'Last 7 Days',
        'audit_condition' => 'audit.date >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    ],
    '30' => [
        'label' => 'Last 30 Days',
        'audit_condition' => 'audit.date >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
    ],
    'all' => [
        'label' => 'All Available History',
        'audit_condition' => '1 = 1'
    ]
];

$scopeOptions = [
    'meaningful' => 'Meaningful Student Activity',
    'all' => 'All Successful Audit Events'
];

$activityTypeOptions = [
    'all' => 'All Activity Types',
    'auth' => 'Logins / Logouts',
    'patient' => 'Patient Chart Sessions',
    'clinical' => 'Clinical Record Changes',
    'scheduling' => 'Scheduling Changes',
    'sign_print' => 'E-Sign / Print'
];

$pageSizeOptions = [25, 50, 100];

$rangeKey = (string) ($_GET['range'] ?? '7');
$scopeKey = (string) ($_GET['scope'] ?? 'meaningful');
$activityTypeKey = (string) ($_GET['activity_type'] ?? 'all');
$studentFilter = trim((string) ($_GET['student'] ?? ''));
$patientSearch = trim((string) ($_GET['patient'] ?? ''));
$pageSize = (int) ($_GET['page_size'] ?? 50);
$beforeId = max(0, (int) ($_GET['before_id'] ?? 0));
$trailRaw = trim((string) ($_GET['trail'] ?? ''));

if (!isset($rangeOptions[$rangeKey])) {
    $rangeKey = '7';
}

if (!isset($scopeOptions[$scopeKey])) {
    $scopeKey = 'meaningful';
}

if (!isset($activityTypeOptions[$activityTypeKey])) {
    $activityTypeKey = 'all';
}

if (!in_array($pageSize, $pageSizeOptions, true)) {
    $pageSize = 50;
}

$patientSearch = substr($patientSearch, 0, 100);
$auditRangeCondition = $rangeOptions[$rangeKey]['audit_condition'];
$rangeLabel = $rangeOptions[$rangeKey]['label'];

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

if (!$canManageEducation) {
    $studentFilter = $currentUsername;
} elseif (
    $studentFilter !== ''
    && !in_array($studentFilter, $trackedStudents, true)
) {
    $studentFilter = '';
}

$trail = [];

if ($trailRaw !== '') {
    foreach (explode(',', $trailRaw) as $value) {
        $cursor = max(0, (int) $value);
        $trail[] = $cursor;

        if (count($trail) >= 100) {
            break;
        }
    }
}

function explorerActivityCondition(string $activityTypeKey, string $scopeKey): string
{
    if ($activityTypeKey === 'auth') {
        return "audit.success = 1 AND audit.event IN ('login', 'logout')";
    }

    if ($activityTypeKey === 'patient') {
        return EducationAnalytics::patientChartAuditCondition('audit');
    }

    if ($activityTypeKey === 'clinical') {
        return "audit.success = 1 AND audit.event IN (
            'patient-record-insert',
            'patient-record-update',
            'patient-record-delete',
            'patient-record-replace'
        )";
    }

    if ($activityTypeKey === 'scheduling') {
        return "audit.success = 1 AND audit.event IN (
            'scheduling-insert',
            'scheduling-update',
            'scheduling-delete'
        )";
    }

    if ($activityTypeKey === 'sign_print') {
        return "audit.success = 1 AND audit.event IN ('esign', 'print')";
    }

    return $scopeKey === 'all'
        ? 'audit.success = 1'
        : EducationAnalytics::meaningfulAuditCondition('audit');
}

function normalizeExplorerRows(array $rows, string $scopeKey): array
{
    if ($scopeKey === 'all') {
        $activities = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $date = (string) ($row['date'] ?? '');

            if ($id <= 0 || $date === '') {
                continue;
            }

            $activities[] = [
                'user' => (string) ($row['user'] ?? ''),
                'event' => (string) ($row['event'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'patient_id' => (int) ($row['patient_id'] ?? 0),
                'session_start' => $date,
                'date' => $date,
                'repeated_count' => 1,
                'first_log_id' => $id,
                'last_log_id' => $id,
                'patient_name' => (string) ($row['patient_name'] ?? ''),
                'pubpid' => (string) ($row['pubpid'] ?? '')
            ];
        }

        return $activities;
    }

    $activities = [];
    $chartRows = [];

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $user = trim((string) ($row['user'] ?? ''));
        $event = (string) ($row['event'] ?? '');
        $category = trim((string) ($row['category'] ?? ''));
        $patientId = (int) ($row['patient_id'] ?? 0);
        $date = (string) ($row['date'] ?? '');
        $timestamp = strtotime($date . ' UTC');

        if ($id <= 0 || $user === '' || $date === '' || $timestamp === false) {
            continue;
        }

        if (
            in_array($event, ['patient-record-select', 'patient-access'], true)
        ) {
            if ($patientId <= 0) {
                continue;
            }

            $chartKey = $user . '|' . $patientId;
            $chartRows[$chartKey][] = $row;
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
            $key = implode('|', ['patient-create', $user, (string) $bucket]);
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
                $user,
                $event,
                $category,
                (string) $patientId,
                (string) $bucket
            ]);
        }

        if (!isset($activities[$key])) {
            $activities[$key] = [
                'user' => $user,
                'event' => $event,
                'category' => $category,
                'patient_id' => $patientId,
                'session_start' => $date,
                'date' => $date,
                'repeated_count' => 1,
                'first_log_id' => $id,
                'last_log_id' => $id,
                'patient_name' => (string) ($row['patient_name'] ?? ''),
                'pubpid' => (string) ($row['pubpid'] ?? '')
            ];
        } else {
            $activity = &$activities[$key];
            $activity['repeated_count']++;
            $activity['first_log_id'] = min((int) $activity['first_log_id'], $id);
            $activity['last_log_id'] = max((int) $activity['last_log_id'], $id);

            if ($date < $activity['session_start']) {
                $activity['session_start'] = $date;
            }

            if ($date > $activity['date']) {
                $activity['date'] = $date;
            }

            if ($activity['patient_id'] <= 0 && $patientId > 0) {
                $activity['patient_id'] = $patientId;
                $activity['patient_name'] = (string) ($row['patient_name'] ?? '');
                $activity['pubpid'] = (string) ($row['pubpid'] ?? '');
            }

            unset($activity);
        }
    }

    foreach ($chartRows as $rowsForPatient) {
        usort(
            $rowsForPatient,
            static function (array $left, array $right): int {
                return ((int) $right['id']) <=> ((int) $left['id']);
            }
        );

        $session = null;
        $oldestTimestamp = null;

        foreach ($rowsForPatient as $row) {
            $id = (int) $row['id'];
            $date = (string) $row['date'];
            $timestamp = strtotime($date . ' UTC');

            if ($timestamp === false) {
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
                    'user' => (string) $row['user'],
                    'event' => 'patient-chart-session',
                    'category' => 'Patient Chart',
                    'patient_id' => (int) $row['patient_id'],
                    'session_start' => $date,
                    'date' => $date,
                    'repeated_count' => 1,
                    'first_log_id' => $id,
                    'last_log_id' => $id,
                    'patient_name' => (string) ($row['patient_name'] ?? ''),
                    'pubpid' => (string) ($row['pubpid'] ?? '')
                ];
                $oldestTimestamp = $timestamp;
                continue;
            }

            $session['session_start'] = $date;
            $session['repeated_count']++;
            $session['first_log_id'] = min((int) $session['first_log_id'], $id);
            $oldestTimestamp = $timestamp;
        }

        if ($session !== null) {
            $activities[] = $session;
        }
    }

    $activities = array_values($activities);
    $creationTimes = [];

    foreach ($activities as $activity) {
        if (
            $activity['event'] === 'patient-record-insert'
            && $activity['category'] === 'Patient Record'
            && (int) $activity['patient_id'] > 0
        ) {
            $key = $activity['user'] . '|' . (int) $activity['patient_id'];
            $creationTimes[$key][] = strtotime($activity['date'] . ' UTC');
        }
    }

    foreach ($activities as $key => $activity) {
        if ($activity['event'] !== 'patient-chart-session') {
            continue;
        }

        $patientKey = $activity['user'] . '|' . (int) $activity['patient_id'];
        $start = strtotime($activity['session_start'] . ' UTC');
        $end = strtotime($activity['date'] . ' UTC');

        foreach ($creationTimes[$patientKey] ?? [] as $createdAt) {
            if (
                $start !== false
                && $end !== false
                && $createdAt !== false
                && $createdAt >= ($start - 60)
                && $createdAt <= ($end + 60)
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
            $dateCompare = strcmp((string) $right['date'], (string) $left['date']);

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((int) $right['last_log_id']) <=> ((int) $left['last_log_id']);
        }
    );

    return $activities;
}

function renderExplorerPatient(
    array $activity,
    bool $canViewPatientDemographics
): string {
    $patientId = (int) ($activity['patient_id'] ?? 0);

    if ($patientId <= 0) {
        return text('—');
    }

    if (!$canViewPatientDemographics) {
        return text('PID ' . $patientId);
    }

    $name = trim((string) ($activity['patient_name'] ?? ''));
    $pubpid = trim((string) ($activity['pubpid'] ?? ''));

    if ($name === '') {
        $name = 'Patient ' . $patientId;
    }

    $url = '../../../../patient_file/summary/demographics.php?set_pid='
        . rawurlencode((string) $patientId);
    $secondaryId = $pubpid !== '' ? 'ID ' . $pubpid : 'PID ' . $patientId;

    return '<a href="' . attr($url) . '"'
        . ' onclick="return openPatientDashboard(' . $patientId . ', this.href);">'
        . text($name)
        . '</a>'
        . '<small class="text-muted d-block">'
        . text($secondaryId)
        . '</small>';
}

function renderExplorerTime(array $activity): string
{
    $start = (string) ($activity['session_start'] ?? '');
    $end = (string) ($activity['date'] ?? '');

    if (
        ($activity['event'] ?? '') === 'patient-chart-session'
        && $start !== ''
        && $end !== ''
        && $start !== $end
    ) {
        return $start . ' – ' . $end;
    }

    return $end;
}

function buildExplorerQuery(array $overrides = []): string
{
    global $rangeKey, $scopeKey, $activityTypeKey, $studentFilter;
    global $patientSearch, $pageSize, $beforeId, $trail;

    $values = [
        'range' => $rangeKey,
        'scope' => $scopeKey,
        'activity_type' => $activityTypeKey,
        'student' => $studentFilter,
        'patient' => $patientSearch,
        'page_size' => (string) $pageSize,
        'before_id' => $beforeId > 0 ? (string) $beforeId : '',
        'trail' => !empty($trail) ? implode(',', $trail) : ''
    ];

    foreach ($overrides as $key => $value) {
        $values[$key] = (string) $value;
    }

    $values = array_filter(
        $values,
        static fn(string $value): bool => $value !== ''
    );

    return 'activity-explorer.php?' . http_build_query($values);
}

$activityCondition = explorerActivityCondition($activityTypeKey, $scopeKey);
$candidateLimit = 1000;
$maxChunks = 6;
$queryCursor = $beforeId;
$rawRows = [];
$lastFetchCount = 0;
$chunksUsed = 0;

for ($chunk = 0; $chunk < $maxChunks; $chunk++) {
    $conditions = [
        'education_users.track_activity = 1',
        $activityCondition,
        $auditRangeCondition
    ];
    $params = [];

    if ($studentFilter !== '') {
        $conditions[] = 'audit.user = ?';
        $params[] = $studentFilter;
    }

    if ($queryCursor > 0) {
        $conditions[] = 'audit.id < ?';
        $params[] = $queryCursor;
    }

    if ($patientSearch !== '') {
        if ($canViewPatientDemographics) {
            $searchLike = '%' . $patientSearch . '%';
            $conditions[] = "(
                CAST(audit.patient_id AS CHAR) = ?
                OR patient.pubpid = ?
                OR patient.fname LIKE ?
                OR patient.lname LIKE ?
                OR CONCAT_WS(' ', patient.fname, patient.mname, patient.lname) LIKE ?
            )";
            $params[] = $patientSearch;
            $params[] = $patientSearch;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
        } elseif (ctype_digit($patientSearch)) {
            $conditions[] = 'audit.patient_id = ?';
            $params[] = (int) $patientSearch;
        } else {
            $conditions[] = '1 = 0';
        }
    }

    $whereSql = implode("\n AND ", $conditions);
    $statement = sqlStatement(
        "SELECT
            audit.id,
            audit.user,
            audit.event,
            audit.category,
            audit.patient_id,
            audit.date,
            patient.pubpid,
            CONCAT_WS(
                ' ',
                NULLIF(TRIM(patient.fname), ''),
                NULLIF(TRIM(patient.mname), ''),
                NULLIF(TRIM(patient.lname), '')
            ) AS patient_name
         FROM log AS audit
         INNER JOIN mod_maple_grove_education_users AS education_users
             ON education_users.username = audit.user
         LEFT JOIN patient_data AS patient
             ON patient.pid = audit.patient_id
         WHERE {$whereSql}
         ORDER BY audit.id DESC
         LIMIT {$candidateLimit}",
        $params
    );

    $chunkRows = [];

    while ($row = sqlFetchArray($statement)) {
        $chunkRows[] = $row;
    }

    $lastFetchCount = count($chunkRows);
    $chunksUsed++;

    if ($lastFetchCount === 0) {
        break;
    }

    array_push($rawRows, ...$chunkRows);
    $lastRow = end($chunkRows);
    $queryCursor = (int) ($lastRow['id'] ?? 0);

    $previewActivities = normalizeExplorerRows($rawRows, $scopeKey);

    if (count($previewActivities) >= ($pageSize + 10)) {
        break;
    }

    if ($lastFetchCount < $candidateLimit || $queryCursor <= 0) {
        break;
    }
}

$activities = normalizeExplorerRows($rawRows, $scopeKey);
$displayActivities = array_slice($activities, 0, $pageSize);
$hasMore = count($activities) > $pageSize
    || $lastFetchCount === $candidateLimit
    || $chunksUsed === $maxChunks;

$nextBeforeId = 0;

if (!empty($displayActivities)) {
    $lastDisplayed = end($displayActivities);
    $nextBeforeId = (int) ($lastDisplayed['first_log_id'] ?? 0);
}

$currentCursorForTrail = $beforeId > 0 ? $beforeId : 0;
$nextTrail = $trail;
$nextTrail[] = $currentCursorForTrail;

$previousCursor = null;
$previousTrail = $trail;

if (!empty($previousTrail)) {
    $previousCursor = array_pop($previousTrail);
}

$pageNumber = count($trail) + 1;

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Education Activity Explorer'); ?></title>
    <?php Header::setupHeader(); ?>

    <style>
        #activity-loading-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.82);
        }

        #activity-loading-overlay.is-visible {
            display: flex;
        }

        .activity-loading-card {
            min-width: 240px;
            padding: 1.25rem 1.5rem;
            text-align: center;
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 0.4rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.12);
        }

        .activity-filter-card label {
            font-weight: 600;
        }

        .activity-table td,
        .activity-table th {
            vertical-align: middle;
        }
    </style>
</head>
<body class="body_top">
<div id="activity-loading-overlay" aria-live="polite" aria-busy="true">
    <div class="activity-loading-card">
        <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
        <div><strong><?php echo xlt('Loading activity…'); ?></strong></div>
        <div class="small text-muted mt-1"><?php echo xlt('Filtering OpenEMR audit history.'); ?></div>
    </div>
</div>

<div class="container-fluid mt-3 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1"><?php echo xlt('Education Activity Explorer'); ?></h1>
            <p class="text-muted mb-0">
                Browse normalized student activity without loading the entire audit history at once.
            </p>
        </div>

        <a
            class="btn btn-outline-secondary mt-2 mt-md-0"
            href="education-dashboard.php?range=<?php echo attr(rawurlencode($rangeKey)); ?>&amp;scope=<?php echo attr(rawurlencode($scopeKey)); ?>"
            onclick="showActivityLoading()"
        >
            <?php echo xlt('Back to Dashboard'); ?>
        </a>
    </div>

    <div class="card shadow-sm mb-3 activity-filter-card">
        <div class="card-body">
            <form method="get" id="activity-filter-form">
                <div class="form-row">
                    <div class="form-group col-lg-2 col-md-4">
                        <label for="range"><?php echo xlt('Date Range'); ?></label>
                        <select class="form-control" id="range" name="range">
                            <?php foreach ($rangeOptions as $key => $option) : ?>
                                <option
                                    value="<?php echo attr($key); ?>"
                                    <?php echo (string) $key === $rangeKey ? 'selected' : ''; ?>
                                >
                                    <?php echo text($option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-lg-2 col-md-4">
                        <label for="scope"><?php echo xlt('Audit Scope'); ?></label>
                        <select class="form-control" id="scope" name="scope">
                            <?php foreach ($scopeOptions as $key => $label) : ?>
                                <option
                                    value="<?php echo attr($key); ?>"
                                    <?php echo $key === $scopeKey ? 'selected' : ''; ?>
                                >
                                    <?php echo text($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($canManageEducation) : ?>
                        <div class="form-group col-lg-2 col-md-4">
                            <label for="student"><?php echo xlt('Student'); ?></label>
                            <select class="form-control" id="student" name="student">
                                <option value=""><?php echo xlt('All Tracked Students'); ?></option>
                                <?php foreach ($trackedStudents as $username) : ?>
                                    <option
                                        value="<?php echo attr($username); ?>"
                                        <?php echo $studentFilter === $username ? 'selected' : ''; ?>
                                    >
                                        <?php echo text($username); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group col-lg-2 col-md-4">
                        <label for="activity_type"><?php echo xlt('Activity Type'); ?></label>
                        <select class="form-control" id="activity_type" name="activity_type">
                            <?php foreach ($activityTypeOptions as $key => $label) : ?>
                                <option
                                    value="<?php echo attr($key); ?>"
                                    <?php echo $activityTypeKey === $key ? 'selected' : ''; ?>
                                >
                                    <?php echo text($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-lg-3 col-md-6">
                        <label for="patient"><?php echo xlt('Patient Search'); ?></label>
                        <input
                            class="form-control"
                            type="search"
                            id="patient"
                            name="patient"
                            value="<?php echo attr($patientSearch); ?>"
                            placeholder="<?php echo attr($canViewPatientDemographics ? 'Name or patient ID' : 'Patient ID'); ?>"
                        >
                    </div>

                    <div class="form-group col-lg-1 col-md-2">
                        <label for="page_size"><?php echo xlt('Rows'); ?></label>
                        <select class="form-control" id="page_size" name="page_size">
                            <?php foreach ($pageSizeOptions as $option) : ?>
                                <option
                                    value="<?php echo attr((string) $option); ?>"
                                    <?php echo $pageSize === $option ? 'selected' : ''; ?>
                                >
                                    <?php echo text((string) $option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button
                    class="btn btn-primary mr-2"
                    type="submit"
                    onclick="showActivityLoading()"
                >
                    <?php echo xlt('Apply Filters'); ?>
                </button>

                <a
                    class="btn btn-outline-secondary"
                    href="activity-explorer.php?range=<?php echo attr(rawurlencode($rangeKey)); ?>&amp;scope=meaningful"
                    onclick="showActivityLoading()"
                >
                    <?php echo xlt('Reset Filters'); ?>
                </a>
            </form>
        </div>
    </div>

    <?php if ($scopeKey === 'meaningful') : ?>
        <div class="alert alert-info py-2">
            Patient Chart Sessions are approximate activity periods: repeated reads of the same patient's chart are collapsed when they occur within 30 minutes of each other.
        </div>
    <?php else : ?>
        <div class="alert alert-warning py-2">
            Raw audit mode can contain substantial OpenEMR background and technical activity.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
            <strong>
                <?php echo text('Activity — ' . $rangeLabel); ?>
            </strong>
            <span class="small text-muted">
                <?php echo text('Page ' . $pageNumber . ' · ' . count($displayActivities) . ' activities'); ?>
            </span>
        </div>

        <?php if (empty($displayActivities)) : ?>
            <div class="card-body text-muted">
                No matching activity was found. Try broadening the filters.
            </div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 activity-table">
                    <thead>
                    <tr>
                        <?php if ($canManageEducation) : ?>
                            <th><?php echo xlt('Username'); ?></th>
                        <?php endif; ?>
                        <th><?php echo xlt('Activity'); ?></th>
                        <th><?php echo xlt('Patient'); ?></th>
                        <th><?php echo xlt('Time'); ?></th>
                        <?php if ($scopeKey === 'all') : ?>
                            <th><?php echo xlt('Audit ID'); ?></th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($displayActivities as $activity) : ?>
                        <tr>
                            <?php if ($canManageEducation) : ?>
                                <td><code><?php echo text($activity['user']); ?></code></td>
                            <?php endif; ?>
                            <td>
                                <?php
                                echo text(
                                    EducationAnalytics::formatAuditEvent(
                                        (string) $activity['event'],
                                        (string) $activity['category']
                                    )
                                );
                                ?>
                            </td>
                            <td>
                                <?php
                                echo renderExplorerPatient(
                                    $activity,
                                    $canViewPatientDemographics
                                );
                                ?>
                            </td>
                            <td><?php echo text(renderExplorerTime($activity)); ?></td>
                            <?php if ($scopeKey === 'all') : ?>
                                <td><code><?php echo text((string) $activity['last_log_id']); ?></code></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <?php if ($beforeId > 0 && $previousCursor !== null) : ?>
                    <a
                        class="btn btn-outline-secondary mr-2"
                        href="<?php echo attr(buildExplorerQuery([
                            'before_id' => $previousCursor > 0 ? (string) $previousCursor : '',
                            'trail' => !empty($previousTrail) ? implode(',', $previousTrail) : ''
                        ])); ?>"
                        onclick="showActivityLoading()"
                    >
                        <?php echo xlt('Newer'); ?>
                    </a>
                <?php elseif ($beforeId > 0) : ?>
                    <a
                        class="btn btn-outline-secondary mr-2"
                        href="<?php echo attr(buildExplorerQuery([
                            'before_id' => '',
                            'trail' => ''
                        ])); ?>"
                        onclick="showActivityLoading()"
                    >
                        <?php echo xlt('Back to Newest'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($hasMore && $nextBeforeId > 0 && !empty($displayActivities)) : ?>
                <a
                    class="btn btn-primary"
                    href="<?php echo attr(buildExplorerQuery([
                        'before_id' => (string) $nextBeforeId,
                        'trail' => implode(',', $nextTrail)
                    ])); ?>"
                    onclick="showActivityLoading()"
                >
                    <?php echo xlt('Older Activity'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <p class="small text-muted mt-2 mb-0">
        Results are fetched in bounded chunks and paginated so browsing older history does not require rendering the entire OpenEMR audit table at once.
    </p>
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

function showActivityLoading() {
    const overlay = document.getElementById("activity-loading-overlay");

    if (overlay) {
        overlay.classList.add("is-visible");
    }
}

document.addEventListener("DOMContentLoaded", function () {
    const timestampRangePattern =
        /^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\s+–\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}))?$/;

    const formatLocalTimestamp = function (value) {
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
        const end = match[2] ? formatLocalTimestamp(match[2]) : "";
        node.nodeValue = end === "" ? start : start + " – " + end;
    });
});
</script>
</body>
</html>
