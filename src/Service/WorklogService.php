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
        if (!$ok) return false;
        $id = (int) $DB->insertId();
        $this->syncTaskEffectiveDuration($taskId);
        return $id;
    }

    public function syncMeetingLog(int $projectId, int $meetingId, int $userId, string $date, int $minutes, string $comment, int $taskId = 0): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return false;
        $data = ['projects_id' => $projectId, 'projecttasks_id' => max(0, $taskId), 'meetings_id' => $meetingId, 'users_id' => $userId, 'work_type' => 'meeting', 'work_date' => $date, 'minutes' => max(0, $minutes), 'comment' => mb_substr(trim(strip_tags($comment)), 0, 4000)];
        $where = ['meetings_id' => $meetingId, 'work_type' => 'meeting'];
        $previousTask = (int) ($DB->request(['SELECT' => ['projecttasks_id'], 'FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 1])->current()['projecttasks_id'] ?? 0);
        if (countElementsInTable(self::TABLE, $where)) {
            $ok = (bool) $DB->update(self::TABLE, $data, $where);
        } else {
            $data['date_creation'] = date('Y-m-d H:i:s');
            $ok = (bool) $DB->insert(self::TABLE, $data);
        }
        if ($ok) {
            foreach (array_unique(array_filter([$previousTask, max(0, $taskId)])) as $affected) $this->syncTaskEffectiveDuration((int) $affected);
        }
        return $ok;
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

        $taskId = (int) ($DB->request(['SELECT' => ['projecttasks_id'], 'FROM' => self::TABLE, 'WHERE' => $criteria, 'LIMIT' => 1])->current()['projecttasks_id'] ?? 0);
        $ok = (bool) $DB->delete(self::TABLE, $criteria);
        if ($ok) $this->syncTaskEffectiveDuration($taskId);
        return $ok;
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

    /**
     * Delete a manual execution entry. Meeting entries are owned by MeetingService and can only
     * be removed together with their meeting, so the 1:1 synchronization is never broken.
     */
    public function delete(int $id, int $taskId): bool
    {
        global $DB;
        if ($id <= 0 || $taskId <= 0 || !$DB->tableExists(self::TABLE)) return false;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id, 'projecttasks_id' => $taskId], 'LIMIT' => 1]);
        if (!$it->count()) return false;
        $row = $it->current();
        if (($row['work_type'] ?? 'execution') !== 'execution' || (int) ($row['meetings_id'] ?? 0) > 0) return false;
        if ((int) $row['users_id'] !== (int) Session::getLoginUserID() && !Session::haveRight('config', UPDATE)) return false;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        $ok = (bool) $DB->delete(self::TABLE, ['id' => $id, 'projecttasks_id' => $taskId, 'work_type' => 'execution']);
        if ($ok) $this->syncTaskEffectiveDuration($taskId);
        return $ok;
    }

    /** Edit a manual execution entry (same ownership rules as delete). */
    public function update(int $id, int $taskId, array $input): bool
    {
        global $DB;
        if ($id <= 0 || $taskId <= 0 || !$DB->tableExists(self::TABLE)) return false;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id, 'projecttasks_id' => $taskId], 'LIMIT' => 1]);
        if (!$it->count()) return false;
        $row = $it->current();
        if (($row['work_type'] ?? 'execution') !== 'execution' || (int) ($row['meetings_id'] ?? 0) > 0) return false;
        if ((int) $row['users_id'] !== (int) Session::getLoginUserID() && !Session::haveRight('config', UPDATE)) return false;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        $minutes = $this->minutesFromInput($input);
        if ($minutes <= 0 || $minutes > 24 * 60) return false;
        $data = [
            'work_date' => $this->dateOnly($input['work_date'] ?? $row['work_date']) ?? $row['work_date'],
            'minutes' => $minutes,
            'comment' => mb_substr(trim(strip_tags((string) ($input['comment'] ?? $row['comment'] ?? ''))), 0, 4000),
        ];
        $ok = (bool) $DB->update(self::TABLE, $data, ['id' => $id, 'projecttasks_id' => $taskId, 'work_type' => 'execution']);
        if ($ok) $this->syncTaskEffectiveDuration($taskId);
        return $ok;
    }

    /**
     * Hours per task of a project: [taskId => execution/meeting/total minutes + labels].
     * Meetings linked to a task count for that task; project-level meetings stay at 0.
     */
    public function getTaskTotals(int $projectId): array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return [];
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['projecttasks_id', 'work_type', 'SUM' => 'minutes AS total'],
            'FROM' => self::TABLE,
            'WHERE' => ['projects_id' => $projectId, 'projecttasks_id' => ['>', 0]],
            'GROUPBY' => ['projecttasks_id', 'work_type'],
        ]) as $row) {
            $taskId = (int) $row['projecttasks_id'];
            $out[$taskId] ??= ['execution_minutes' => 0, 'meeting_minutes' => 0];
            $key = ($row['work_type'] ?? 'execution') === 'meeting' ? 'meeting_minutes' : 'execution_minutes';
            $out[$taskId][$key] += (int) $row['total'];
        }
        foreach ($out as &$item) {
            $item['total_minutes'] = $item['execution_minutes'] + $item['meeting_minutes'];
            $item['execution_label'] = self::formatMinutes($item['execution_minutes']);
            $item['meeting_label'] = self::formatMinutes($item['meeting_minutes']);
            $item['total_label'] = self::formatMinutes($item['total_minutes']);
        }
        unset($item);
        return $out;
    }

    /**
     * Mirror the hours of a task (execution + meetings) into the native ProjectTask
     * `effective_duration` (seconds), so GLPI's own project/task screens and the Planning
     * show the same effort.
     */
    public function syncTaskEffectiveDuration(int $taskId): void
    {
        global $DB;
        if ($taskId <= 0 || !$DB->tableExists(self::TABLE)) return;
        $row = $DB->request(['SELECT' => ['SUM' => 'minutes AS total'], 'FROM' => self::TABLE, 'WHERE' => ['projecttasks_id' => $taskId]])->current();
        $seconds = max(0, (int) ($row['total'] ?? 0)) * 60;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || (int) ($task->fields['effective_duration'] ?? 0) === $seconds) return;
        $task->update(['id' => $taskId, 'effective_duration' => $seconds]);
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
