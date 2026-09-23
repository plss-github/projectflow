<?php

namespace GlpiPlugin\Projectflow\Service;

use Group;
use Group_User;
use Notification_NotificationTemplate;
use ProjectTask;
use ProjectTaskTeam;
use QueuedNotification;
use Throwable;
use User;
use UserEmail;

/**
 * Plain e-mail notifications queued in GLPI's native QueuedNotification (sent by the
 * `queuednotification` cron). Used by progress rules.
 */
class NotificationService
{
    /** Active, not deleted users of the task team (users + members of assigned groups). */
    public function taskTeamUserIds(int $taskId): array
    {
        global $DB;
        $ids = [];
        $team = ProjectTaskTeam::getTeamFor($taskId, true);
        foreach ($team[User::class] ?? [] as $member) {
            $uid = (int) ($member['items_id'] ?? 0);
            if ($uid > 0) $ids[$uid] = true;
        }
        $groups = [];
        foreach ($team[Group::class] ?? [] as $member) {
            $gid = (int) ($member['items_id'] ?? 0);
            if ($gid > 0) $groups[] = $gid;
        }
        return array_keys($ids + array_flip($this->groupUserIds($groups)));
    }

    public function groupUserIds(array $groupIds): array
    {
        global $DB;
        $groupIds = array_values(array_filter(array_map('intval', $groupIds)));
        if ($groupIds === [] || !$DB->tableExists(Group_User::getTable())) return [];
        $ids = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => Group_User::getTable(), 'WHERE' => ['groups_id' => $groupIds]]) as $row) {
            $uid = (int) $row['users_id'];
            if ($uid > 0) $ids[$uid] = true;
        }
        return array_keys($ids);
    }

    /** Queue one e-mail per active recipient. Returns how many were queued. */
    public function queueForTask(ProjectTask $task, array $userIds, string $subject, string $body): int
    {
        $taskId = (int) $task->getID();
        $entityId = (int) ($task->fields['entities_id'] ?? 0);
        $sender = \Config::getAdminEmailSender($entityId);
        $senderEmail = (string) ($sender['email'] ?? '');
        if ($senderEmail === '') return 0;
        $senderName = (string) ($sender['name'] ?? 'GLPI');
        $queued = 0;
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0) continue;
            try {
                $user = new User();
                if (!$user->getFromDB($uid) || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) continue;
                $email = UserEmail::getDefaultForUser($uid);
                if ($email === '') continue;
                $q = new QueuedNotification();
                if ($q->add([
                    'itemtype' => ProjectTask::class,
                    'items_id' => $taskId,
                    'entities_id' => $entityId,
                    'notificationtemplates_id' => 0,
                    'is_deleted' => 0,
                    'mode' => Notification_NotificationTemplate::MODE_MAIL,
                    'event' => 'projectflow_progress_rule',
                    'sender' => $senderEmail,
                    'sendername' => $senderName,
                    'recipient' => $email,
                    'recipientname' => getUserName($uid),
                    'name' => $subject,
                    'body_text' => $body,
                    'body_html' => '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>',
                ])) {
                    $queued++;
                }
            } catch (Throwable $e) {
                \Toolbox::logError('[Project Flow] notification queue: ' . $e->getMessage());
            }
        }
        return $queued;
    }
}
