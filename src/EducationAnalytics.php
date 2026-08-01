<?php

/**
 * Shared access, event, and audit helpers for the Maple Grove education module.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE
 * GNU General Public License 3
 */

namespace OpenEMR\Modules\CustomModuleSkeleton;

use OpenEMR\Common\Acl\AclMain;

class EducationAnalytics
{
    /**
     * Return the currently authenticated OpenEMR user ID.
     */
    public static function currentUserId(): int
    {
        return (int) (
            $_SESSION['authUserID']
            ?? $GLOBALS['authUserID']
            ?? 0
        );
    }

    /**
     * Return the currently authenticated OpenEMR username.
     */
    public static function currentUsername(): string
    {
        return (string) (
            $_SESSION['authUser']
            ?? $GLOBALS['authUser']
            ?? ''
        );
    }

    /**
     * Prototype rule: an account with any common OpenEMR administration
     * permission may bootstrap and manage education analytics.
     */
    public static function hasAnyAdminAccess(): bool
    {
        $adminPermissions = [
            'super',
            'users',
            'practice',
            'database',
            'forms',
            'language',
            'drugs',
            'calendar',
            'batchcom'
        ];

        foreach ($adminPermissions as $permission) {
            if (AclMain::aclCheckCore('admin', $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the education-module record for an OpenEMR user.
     */
    public static function getEducationUser(int $userId)
    {
        if ($userId <= 0) {
            return false;
        }

        return sqlQuery(
            "SELECT
                education_role,
                track_activity,
                can_view_analytics
             FROM mod_maple_grove_education_users
             WHERE openemr_user_id = ?",
            [$userId]
        );
    }

    /**
     * Determine whether the current user can view and configure analytics.
     */
    public static function canManageEducation(int $userId): bool
    {
        if (self::hasAnyAdminAccess()) {
            return true;
        }

        $educationUser = self::getEducationUser($userId);

        return !empty($educationUser['can_view_analytics']);
    }

    /**
     * Determine whether a user is selected for student activity tracking.
     */
    public static function isTrackedStudent(int $userId): bool
    {
        $educationUser = self::getEducationUser($userId);

        return !empty($educationUser['track_activity']);
    }

    /**
     * Record an education-module event for a tracked student.
     *
     * Identical event types can optionally be throttled to reduce noise.
     */
    public static function recordTrackedEvent(
        int $userId,
        string $username,
        string $eventType,
        array $metadata = [],
        int $throttleMinutes = 0
    ): void {
        if (
            $userId <= 0 ||
            $username === '' ||
            !self::isTrackedStudent($userId)
        ) {
            return;
        }

        if (!preg_match('/^[a-z0-9_]+$/', $eventType)) {
            return;
        }

        if ($throttleMinutes > 0) {
            $throttleMinutes = max(1, min($throttleMinutes, 1440));

            $recentEvent = sqlQuery(
                "SELECT id
                 FROM mod_maple_grove_education_events
                 WHERE openemr_user_id = ?
                   AND event_type = ?
                   AND created_at >= DATE_SUB(
                       NOW(),
                       INTERVAL {$throttleMinutes} MINUTE
                   )
                 ORDER BY created_at DESC
                 LIMIT 1",
                [$userId, $eventType]
            );

            if ($recentEvent) {
                return;
            }
        }

        $metadataJson = empty($metadata)
            ? null
            : json_encode($metadata, JSON_UNESCAPED_SLASHES);

        sqlStatement(
            "INSERT INTO mod_maple_grove_education_events
                (
                    openemr_user_id,
                    username,
                    event_type,
                    metadata,
                    created_at
                )
             VALUES (?, ?, ?, ?, NOW())",
            [
                $userId,
                $username,
                $eventType,
                $metadataJson
            ]
        );
    }

    /**
     * SQL condition for successful audit rows that map to meaningful actions.
     *
     * Patient read events are included only when OpenEMR identifies an actual
     * patient. This removes recurring patient-demographics polling rows with
     * patient_id = 0 from the normal education dashboard.
     */
    public static function meaningfulAuditCondition(string $alias = 'audit'): string
    {
        return "{$alias}.success = 1 AND (
            " . self::meaningfulDiscreteAuditCondition($alias, false) . "
            OR " . self::patientChartAuditCondition($alias, false) . "
        )";
    }

    /**
     * SQL condition for meaningful actions other than patient chart reads.
     */
    public static function meaningfulDiscreteAuditCondition(
        string $alias = 'audit',
        bool $includeSuccess = true
    ): string {
        $condition = "(
            {$alias}.event IN (
                'login',
                'logout',
                'esign',
                'print',
                'patient-record-insert',
                'patient-record-update',
                'patient-record-delete',
                'patient-record-replace',
                'scheduling-insert',
                'scheduling-update',
                'scheduling-delete'
            )
        )";

        return $includeSuccess
            ? "{$alias}.success = 1 AND {$condition}"
            : $condition;
    }

    /**
     * SQL condition for patient chart reads tied to an actual patient.
     */
    public static function patientChartAuditCondition(
        string $alias = 'audit',
        bool $includeSuccess = true
    ): string {
        $condition = "(
            {$alias}.patient_id > 0
            AND {$alias}.event IN (
                'patient-record-select',
                'patient-access'
            )
        )";

        return $includeSuccess
            ? "{$alias}.success = 1 AND {$condition}"
            : $condition;
    }

    /**
     * Convert a module event key into a readable label.
     */
    public static function formatEventType(string $eventType): string
    {
        return ucwords(str_replace('_', ' ', $eventType));
    }

    /**
     * Convert an OpenEMR audit event/category pair into a readable label.
     */
    public static function formatAuditEvent(
        string $event,
        string $category = ''
    ): string {
        $category = trim($category);
        $categoryLabel = $category !== '' ? $category : 'Patient Record';

        $labels = [
            'login' => 'Logged In',
            'logout' => 'Logged Out',
            'patient-access' => 'Accessed Patient Record',
            'patient-chart-session' => 'Patient Chart Session',
            'esign' => 'E-Signed Record',
            'print' => 'Printed or Exported Record',
            'scheduling-insert' => 'Created Appointment',
            'scheduling-update' => 'Updated Appointment',
            'scheduling-delete' => 'Deleted Appointment'
        ];

        if (isset($labels[$event])) {
            return $labels[$event];
        }

        $patientPrefixes = [
            'patient-record-select' => 'Viewed',
            'patient-record-insert' => 'Created',
            'patient-record-update' => 'Updated',
            'patient-record-delete' => 'Deleted',
            'patient-record-replace' => 'Replaced'
        ];

        if (isset($patientPrefixes[$event])) {
            return $patientPrefixes[$event] . ' ' . $categoryLabel;
        }

        $eventLabel = ucwords(str_replace(['-', '_'], ' ', $event));

        if ($category !== '' && strcasecmp($eventLabel, $category) !== 0) {
            return $eventLabel . ' — ' . $category;
        }

        return $eventLabel;
    }
}
