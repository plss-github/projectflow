<?php

namespace GlpiPlugin\Projectflow\Service;

use ProjectTask;
use Session;

class ActivityService
{
    private const TABLE = 'glpi_plugin_projectflow_activities';

    public function getForTask(int $taskId, int $limit = 150): array
    {
        global $DB;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canViewItem() || !$DB->tableExists(self::TABLE)) {
            return [];
        }
        $items = [];
        foreach ($DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['projecttasks_id' => $taskId],
            'ORDERBY' => ['date_creation DESC', 'id DESC'],
            'LIMIT' => max(1, min($limit, 500)),
        ]) as $row) {
            $canDelete = $task->canUpdateItem() && ((int) ($row['users_id'] ?? 0) === (int) Session::getLoginUserID() || Session::haveRight('config', UPDATE));
            $items[] = $this->normalize($row, $canDelete);
        }
        return $items;
    }

    public function add(int $taskId, string $content): int|false
    {
        global $DB;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem() || !$DB->tableExists(self::TABLE)) {
            return false;
        }
        $content = mb_substr(trim(strip_tags($content)), 0, 10000);
        if ($content === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $ok = $DB->insert(self::TABLE, [
            'projecttasks_id' => $taskId,
            'users_id' => (int) Session::getLoginUserID(),
            'content' => $content,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        return $ok ? (int) $DB->insertId() : false;
    }

    public function delete(int $taskId, int $activityId): bool
    {
        global $DB;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem() || !$DB->tableExists(self::TABLE)) {
            return false;
        }
        $it = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['id' => $activityId, 'projecttasks_id' => $taskId],
            'LIMIT' => 1,
        ]);
        if (!$it->count()) {
            return false;
        }
        $row = $it->current();
        if ((int) $row['users_id'] !== (int) Session::getLoginUserID() && !Session::haveRight('config', UPDATE)) {
            return false;
        }
        return $DB->delete(self::TABLE, ['id' => $activityId]);
    }

    public function getForProjectRange(int $projectId, string $start, string $end): array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        $taskNames = [];
        foreach ($DB->request([
            'FROM' => ProjectTask::getTable(),
            'WHERE' => ['projects_id' => $projectId, 'is_deleted' => 0, 'is_template' => 0],
        ]) as $row) {
            $task = new ProjectTask();
            $task->getFromResultSet($row);
            if (!$task->canViewItem()) {
                continue;
            }
            $taskNames[(int) $row['id']] = (string) $row['name'];
        }
        if ($taskNames === []) {
            return [];
        }

        $items = [];
        $where = [
            'projecttasks_id' => array_keys($taskNames),
            ['date_creation' => ['>=', $start . ' 00:00:00']],
            ['date_creation' => ['<=', $end . ' 23:59:59']],
        ];
        foreach ($DB->request([
            'FROM' => self::TABLE,
            'WHERE' => $where,
            'ORDERBY' => ['date_creation ASC', 'id ASC'],
        ]) as $row) {
            $items[] = $this->normalize($row, false) + [
                'task_name' => $taskNames[(int) $row['projecttasks_id']] ?? ('Tarefa #' . $row['projecttasks_id']),
            ];
        }
        return $items;
    }

    private function normalize(array $row, bool $canDelete): array
    {
        return [
            'id' => (int) $row['id'],
            'task_id' => (int) $row['projecttasks_id'],
            'user_id' => (int) $row['users_id'],
            'user_name' => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : 'Sistema',
            'content' => (string) $row['content'],
            'date_creation' => $row['date_creation'],
            'can_delete' => $canDelete,
        ];
    }
}
