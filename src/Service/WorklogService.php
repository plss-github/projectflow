<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use ProjectTask;
use Session;

class WorklogService
{
    private const TABLE = 'glpi_plugin_projectflow_worklogs';

    public function addTaskLog(int $taskId, array $input): int|false
    {
        global $DB;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem() || !$DB->tableExists(self::TABLE)) return false;
        $minutes = $this->minutesFromInput($input);
        if ($minutes <= 0 || $minutes > 24 * 60) return false;
        $workDate = $this->dateOnly($input['work_date'] ?? date('Y-m-d')) ?? date('Y-m-d');
        $comment = mb_substr(trim(strip_tags((string) ($input['comment'] ?? ''))), 0, 4000);
        $ok = $DB->insert(self::TABLE, [
            'projects_id' => (int) $task->fields['projects_id'], 'projecttasks_id' => $taskId, 'meetings_id' => 0,
            'users_id' => (int) Session::getLoginUserID(), 'work_type' => 'execution', 'work_date' => $workDate,
            'minutes' => $minutes, 'comment' => $comment, 'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return $ok ? (int) $DB->insertId() : false;
    }

    public function syncMeetingLog(int $projectId, int $meetingId, int $userId, string $date, int $minutes, string $comment, int $taskId = 0): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return false;
        $data = ['projects_id' => $projectId, 'projecttasks_id' => max(0, $taskId), 'meetings_id' => $meetingId, 'users_id' => $userId, 'work_type' => 'meeting', 'work_date' => $date, 'minutes' => max(0, $minutes), 'comment' => mb_substr(trim(strip_tags($comment)), 0, 4000)];
        if (countElementsInTable(self::TABLE, ['meetings_id' => $meetingId, 'work_type' => 'meeting'])) return $DB->update(self::TABLE, $data, ['meetings_id' => $meetingId, 'work_type' => 'meeting']);
        $data['date_creation'] = date('Y-m-d H:i:s');
        return (bool) $DB->insert(self::TABLE, $data);
    }

    public function deleteMeetingLog(int $meetingId): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return false;

        $criteria = ['meetings_id' => $meetingId, 'work_type' => 'meeting'];
        $exists = false;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $criteria, 'LIMIT' => 1]) as $_row) {
            $exists = true;
            break;
        }

        // Meetings created by older releases may not have a synchronized worklog.
        // Treat that legacy state as already clean so the meeting can still be removed.
        if (!$exists) return true;

        return $DB->delete(self::TABLE, $criteria);
    }

    public function getForTask(int $taskId, int $limit = 100): array
    {
        global $DB;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canViewItem() || !$DB->tableExists(self::TABLE)) return [];
        $items = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['projecttasks_id' => $taskId, 'work_type' => 'execution'], 'ORDERBY' => ['work_date DESC', 'id DESC'], 'LIMIT' => max(1, min($limit, 500))]) as $row) $items[] = $this->normalize($row, $task->canUpdateItem());
        return $items;
    }

    public function getForProject(int $projectId, ?string $start = null, ?string $end = null, int $limit = 300): array
    {
        global $DB;
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem() || !$DB->tableExists(self::TABLE)) return [];
        $where = ['projects_id' => $projectId];
        if ($start) $where[] = ['work_date' => ['>=', $start]];
        if ($end) $where[] = ['work_date' => ['<=', $end]];
        $items = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDERBY' => ['work_date DESC', 'id DESC'], 'LIMIT' => max(1, min($limit, 1000))]) as $row) $items[] = $this->normalize($row, $project->can($projectId, UPDATE));
        return $items;
    }

    public function getSummary(int $projectId, ?string $start = null, ?string $end = null): array
    {
        global $DB;

        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem() || !$DB->tableExists(self::TABLE)) {
            return [
                'execution_minutes' => 0, 'meeting_minutes' => 0, 'total_minutes' => 0,
                'execution_label' => '0h', 'meeting_label' => '0h', 'total_label' => '0h',
            ];
        }

        $where = ['projects_id' => $projectId];
        if ($start) $where[] = ['work_date' => ['>=', $start]];
        if ($end) $where[] = ['work_date' => ['<=', $end]];

        // Do not reuse getForProject() here: that method intentionally caps rows for UI
        // rendering, while project totals must account for every persisted worklog.
        $execution = 0;
        $meeting = 0;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
            $minutes = max(0, (int) ($row['minutes'] ?? 0));
            if (($row['work_type'] ?? 'execution') === 'meeting') {
                $meeting += $minutes;
            } else {
                $execution += $minutes;
            }
        }

        return [
            'execution_minutes' => $execution, 'meeting_minutes' => $meeting, 'total_minutes' => $execution + $meeting,
            'execution_label' => self::formatMinutes($execution), 'meeting_label' => self::formatMinutes($meeting), 'total_label' => self::formatMinutes($execution + $meeting),
        ];
    }

    public function delete(int $id, int $taskId = 0): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return false;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
        if (!$it->count()) return false;
        $row = $it->current();
        if ($taskId > 0 && (int) $row['projecttasks_id'] !== $taskId) return false;
        if ((int) $row['users_id'] !== (int) Session::getLoginUserID() && !Session::haveRight('config', UPDATE)) return false;
        if ($taskId > 0) { $task = new ProjectTask(); if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false; }
        return $DB->delete(self::TABLE, ['id' => $id]);
    }

    public static function formatMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes); $h = intdiv($minutes, 60); $m = $minutes % 60;
        return $m ? sprintf('%dh%02d', $h, $m) : sprintf('%dh', $h);
    }

    private function normalize(array $row, bool $canDelete): array
    {
        return [
            'id' => (int) $row['id'], 'project_id' => (int) $row['projects_id'], 'task_id' => (int) $row['projecttasks_id'], 'meeting_id' => (int) $row['meetings_id'],
            'user_id' => (int) $row['users_id'], 'user_name' => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : 'Sistema',
            'work_type' => (string) $row['work_type'], 'work_date' => $row['work_date'], 'minutes' => (int) $row['minutes'], 'duration_label' => self::formatMinutes((int) $row['minutes']),
            'comment' => (string) ($row['comment'] ?? ''), 'date_creation' => $row['date_creation'],
            'can_delete' => $canDelete && ((int) ($row['users_id'] ?? 0) === (int) Session::getLoginUserID() || Session::haveRight('config', UPDATE)),
        ];
    }

    private function minutesFromInput(array $input): int
    {
        if (isset($input['minutes_total'])) return max(0, (int) $input['minutes_total']);
        $hours = max(0.0, (float) str_replace(',', '.', (string) ($input['hours'] ?? 0)));
        $extra = max(0, min(59, (int) ($input['minutes'] ?? 0)));
        return (int) round($hours * 60) + $extra;
    }

    private function dateOnly(mixed $value): ?string
    {
        $v = trim((string) $value); if ($v === '') return null; $ts = strtotime($v); return $ts ? date('Y-m-d', $ts) : null;
    }
}
