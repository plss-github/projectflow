<?php

namespace GlpiPlugin\Projectflow;

use CommonDBTM;
use CronTask;
use GlpiPlugin\Projectflow\Service\MetaService;
use Notification_NotificationTemplate;
use Group;
use Group_User;
use ProjectTask;
use ProjectTaskTeam;
use QueuedNotification;
use Throwable;
use User;
use UserEmail;

class Automation extends CommonDBTM
{
    public static $rightname = 'config';

    public static function cronInfo($name): array
    {
        if ($name === 'taskreminders') {
            return ['description' => 'Project Flow - envia lembretes de tarefas por e-mail'];
        }
        return [];
    }

    public static function cronTaskreminders(CronTask $task): int
    {
        global $DB;
        if (!Config::bool('reminder_email_enabled', true)) {
            return 0;
        }

        $meta = new MetaService();
        $count = 0;

        foreach ($meta->getPendingReminders() as $row) {
            $taskId = (int) $row['projecttasks_id'];
            $projectTask = new ProjectTask();
            if (!$projectTask->getFromDB($taskId)) {
                $meta->markReminderSent($taskId);
                continue;
            }

            $recipientIds = [];
            $team = ProjectTaskTeam::getTeamFor($taskId, true);
            foreach ($team[User::class] ?? [] as $member) {
                $uid = (int) ($member['items_id'] ?? 0);
                if ($uid > 0) $recipientIds[$uid] = true;
            }

            // Expand direct group assignments so a task assigned only to a group
            // still notifies its users. Duplicate recipients are collapsed by ID.
            $groupIds = [];
            foreach ($team[Group::class] ?? [] as $member) {
                $gid = (int) ($member['items_id'] ?? 0);
                if ($gid > 0) $groupIds[$gid] = true;
            }
            if ($groupIds !== [] && class_exists(Group_User::class) && $DB->tableExists(Group_User::getTable())) {
                foreach ($DB->request([
                    'SELECT' => ['users_id'],
                    'FROM' => Group_User::getTable(),
                    'WHERE' => ['groups_id' => array_keys($groupIds)],
                ]) as $groupUser) {
                    $uid = (int) ($groupUser['users_id'] ?? 0);
                    if ($uid > 0) $recipientIds[$uid] = true;
                }
            }
            if (!empty($projectTask->fields['users_id'])) {
                $recipientIds[(int) $projectTask->fields['users_id']] = true;
            }

            $subject = 'Project Flow - lembrete: ' . (string) ($projectTask->fields['name'] ?? ('Tarefa #' . $taskId));
            $url = ProjectTask::getFormURLWithID($taskId);
            $body = 'Você possui um lembrete para a tarefa "' . (string) ($projectTask->fields['name'] ?? '') . '". '
                . 'Prazo: ' . ((string) ($projectTask->fields['plan_end_date'] ?? '') ?: 'não definido') . '. '
                . 'Acesse: ' . $url;

            $entityId = (int) ($projectTask->fields['entities_id'] ?? 0);
            $sender = \Config::getAdminEmailSender($entityId);
            $senderEmail = (string) ($sender['email'] ?? '');
            $senderName = (string) ($sender['name'] ?? 'GLPI');
            $queued = 0;

            foreach (array_keys($recipientIds) as $uid) {
                try {
                    $email = UserEmail::getDefaultForUser($uid);
                    if ($email === '' || $senderEmail === '') {
                        continue;
                    }
                    $q = new QueuedNotification();
                    $id = $q->add([
                        'itemtype' => ProjectTask::class,
                        'items_id' => $taskId,
                        'entities_id' => $entityId,
                        'notificationtemplates_id' => 0,
                        'is_deleted' => 0,
                        'mode' => Notification_NotificationTemplate::MODE_MAIL,
                        'event' => 'projectflow_reminder',
                        'sender' => $senderEmail,
                        'sendername' => $senderName,
                        'recipient' => $email,
                        'recipientname' => getUserName($uid),
                        'name' => $subject,
                        'body_text' => $body,
                        'body_html' => '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>',
                    ]);
                    if ($id) {
                        $queued++;
                        $count++;
                    }
                } catch (Throwable $e) {
                    \Toolbox::logError('[Project Flow] reminder queue: ' . $e->getMessage());
                }
            }

            // Only acknowledge the e-mail reminder after at least one message was
            // successfully queued. Otherwise the hourly cron can retry after mail or
            // assignment configuration is corrected.
            if ($queued > 0) {
                $meta->markReminderSent($taskId);
                $task->addVolume($queued);
            }
        }

        return $count;
    }
}
