<?php

/**
 * Shared access and event helpers for the Maple Grove education module.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE
 * GNU General Public License 3
 */

namespace OpenEMR\Modules\CustomModuleSkeleton;



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
     * Prototype rule: any account with at least one common OpenEMR
     * administration ACL may bootstrap and manage education analytics.
     *
     * This is intentionally broader than the final production rule.
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
            if (\OpenEMR\Common\Acl\AclMain::aclCheckCore('admin', $permission)) {
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
     * Record a meaningful event for a tracked student.
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
     * Convert an event key into a readable label.
     */
    public static function formatEventType(string $eventType): string
    {
        return ucwords(str_replace('_', ' ', $eventType));
    }
}
