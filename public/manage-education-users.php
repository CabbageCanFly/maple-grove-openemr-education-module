<?php

/**
 * Manage Maple Grove education users.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE
 * GNU General Public License 3
 */

require_once dirname(__FILE__, 5) . "/globals.php";

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$message = '';
$messageType = 'success';

$users = [];
$userStatement = sqlStatement(
    "SELECT id, username, fname, lname, active
     FROM users
     WHERE username <> ''
       AND username <> 'oe-system'
     ORDER BY active DESC, lname, fname, username"
);

while ($row = sqlFetchArray($userStatement)) {
    $users[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_POST['csrf_token_form']) ||
        !CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'])
    ) {
        CsrfUtils::csrfNotVerified();
    }

    $trackedUserIds = array_map('intval', $_POST['track_activity'] ?? []);
    $analyticsUserIds = array_map('intval', $_POST['can_view_analytics'] ?? []);

    $trackedLookup = array_fill_keys($trackedUserIds, true);
    $analyticsLookup = array_fill_keys($analyticsUserIds, true);

    sqlBeginTrans();

    try {
        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $trackActivity = isset($trackedLookup[$userId]) ? 1 : 0;
            $canViewAnalytics = isset($analyticsLookup[$userId]) ? 1 : 0;

            if ($trackActivity || $canViewAnalytics) {
                $educationRole = $canViewAnalytics ? 'instructor' : 'student';

                sqlStatement(
                    "INSERT INTO mod_maple_grove_education_users
                        (
                            openemr_user_id,
                            username,
                            education_role,
                            track_activity,
                            can_view_analytics
                        )
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        username = VALUES(username),
                        education_role = VALUES(education_role),
                        track_activity = VALUES(track_activity),
                        can_view_analytics = VALUES(can_view_analytics)",
                    [
                        $userId,
                        $user['username'],
                        $educationRole,
                        $trackActivity,
                        $canViewAnalytics
                    ]
                );
            } else {
                sqlStatement(
                    "DELETE FROM mod_maple_grove_education_users
                     WHERE openemr_user_id = ?",
                    [$userId]
                );
            }
        }

        sqlCommitTrans();
        $message = xlt('Education user settings saved.');
    } catch (Throwable $exception) {
        sqlRollbackTrans();
        error_log(
            'Maple Grove education user save failed: ' .
            $exception->getMessage()
        );
        $message = xlt('The education user settings could not be saved.');
        $messageType = 'danger';
    }
}

$educationUsers = [];
$educationStatement = sqlStatement(
    "SELECT
        openemr_user_id,
        education_role,
        track_activity,
        can_view_analytics
     FROM mod_maple_grove_education_users"
);

while ($row = sqlFetchArray($educationStatement)) {
    $educationUsers[(int) $row['openemr_user_id']] = $row;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <title>
        <?php echo xlt('Manage Education Users'); ?>
    </title>

    <?php Header::setupHeader(); ?>
</head>

<body class="body_top">
<div class="container-fluid mt-3 mb-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1">
                <?php echo xlt('Manage Education Users'); ?>
            </h1>

            <p class="text-muted mb-0">
                Select which OpenEMR accounts are tracked as students and
                which accounts may later view cohort analytics.
            </p>
        </div>

        <a
            class="btn btn-outline-secondary mt-2 mt-md-0"
            href="education-dashboard.php"
        >
            <?php echo xlt('Back to Dashboard'); ?>
        </a>
    </div>

    <?php if ($message !== '') : ?>
        <div class="alert alert-<?php echo attr($messageType); ?>">
            <?php echo text($message); ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        This prototype stores education roles inside the custom module.
        It does not change the user's normal OpenEMR permissions.
    </div>

    <form method="post">
        <input
            type="hidden"
            name="csrf_token_form"
            value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>"
        >

        <div class="card shadow-sm">
            <div class="card-header">
                <strong><?php echo xlt('OpenEMR Accounts'); ?></strong>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                    <tr>
                        <th><?php echo xlt('User'); ?></th>
                        <th><?php echo xlt('Username'); ?></th>
                        <th><?php echo xlt('Account Status'); ?></th>
                        <th class="text-center">
                            <?php echo xlt('Track as Student'); ?>
                        </th>
                        <th class="text-center">
                            <?php echo xlt('Can View Analytics'); ?>
                        </th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($users as $user) : ?>
                        <?php
                        $userId = (int) $user['id'];
                        $saved = $educationUsers[$userId] ?? [];
                        $fullName = trim(
                            ($user['fname'] ?? '') . ' ' .
                            ($user['lname'] ?? '')
                        );

                        if ($fullName === '') {
                            $fullName = $user['username'];
                        }
                        ?>

                        <tr>
                            <td><?php echo text($fullName); ?></td>
                            <td>
                                <code><?php echo text($user['username']); ?></code>
                            </td>
                            <td>
                                <?php if ((int) $user['active'] === 1) : ?>
                                    <span class="badge badge-success">
                                        <?php echo xlt('Active'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="badge badge-secondary">
                                        <?php echo xlt('Inactive'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <input
                                    type="checkbox"
                                    name="track_activity[]"
                                    value="<?php echo attr($userId); ?>"
                                    <?php
                                    echo !empty($saved['track_activity'])
                                        ? 'checked'
                                        : '';
                                    ?>
                                >
                            </td>
                            <td class="text-center">
                                <input
                                    type="checkbox"
                                    name="can_view_analytics[]"
                                    value="<?php echo attr($userId); ?>"
                                    <?php
                                    echo !empty($saved['can_view_analytics'])
                                        ? 'checked'
                                        : '';
                                    ?>
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer text-right">
                <button type="submit" class="btn btn-primary">
                    <?php echo xlt('Save Education Users'); ?>
                </button>
            </div>
        </div>
    </form>

</div>
</body>
</html>
