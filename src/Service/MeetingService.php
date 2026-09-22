<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use ProjectTask;
use Session;

class MeetingService
{
    private const TABLE = 'glpi_plugin_projectflow_meetings';

    public function __construct(private readonly WorklogService $worklogs = new WorklogService()) {}

    public function getForProject(int $projectId, int $limit = 200): array
    {
        global $DB;
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem() || !$DB->tableExists(self::TABLE)) return [];
        $items = [];
        foreach ($DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['projects_id' => $projectId],
            'ORDERBY' => ['start_at DESC', 'id DESC'],
            'LIMIT' => max(1, min($limit, 500)),
        ]) as $row) {
            $items[] = $this->normalize($row, $project->can($projectId, UPDATE));
        }
        return $items;
    }

    public function getForTask(int $taskId, int $limit = 200): array
    {
        global $DB;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canViewItem() || !$DB->tableExists(self::TABLE)) return [];
        $items = [];
        foreach ($DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['projecttasks_id' => $taskId],
            'ORDERBY' => ['start_at DESC', 'id DESC'],
            'LIMIT' => max(1, min($limit, 500)),
        ]) as $row) {
            $items[] = $this->normalize($row, $task->canUpdateItem());
        }
        return $items;
    }

    public function create(int $projectId, array $input): int|false
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return false;
        return $this->insertMeeting($projectId, 0, $input);
    }

    public function createForTask(int $taskId, array $input): int|false
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        return $this->insertMeeting((int) $task->fields['projects_id'], $taskId, $input);
    }

    public function update(int $projectId, int $meetingId, array $input): bool
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return false;
        return $this->updateMeeting(['id' => $meetingId, 'projects_id' => $projectId], $input);
    }

    public function updateForTask(int $taskId, int $meetingId, array $input): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        return $this->updateMeeting(['id' => $meetingId, 'projecttasks_id' => $taskId], $input);
    }

    public function delete(int $projectId, int $meetingId): bool
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return false;
        return $this->deleteMeeting(['id' => $meetingId, 'projects_id' => $projectId]);
    }

    public function deleteForTask(int $taskId, int $meetingId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        return $this->deleteMeeting(['id' => $meetingId, 'projecttasks_id' => $taskId]);
    }

    private function insertMeeting(int $projectId, int $taskId, array $input): int|false
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return false;
        $title = mb_substr(trim(strip_tags((string) ($input['title'] ?? ''))), 0, 255);
        if ($title === '') return false;
        $start = $this->date($input['start_at'] ?? null);
        if (!$start) return false;
        $minutes = max(1, min(1440, (int) ($input['duration_minutes'] ?? 60)));
        $now = date('Y-m-d H:i:s');
        $uid = (int) Session::getLoginUserID();
        $data = [
            'projects_id' => $projectId,
            'projecttasks_id' => $taskId,
            'users_id' => $uid,
            'title' => $title,
            'start_at' => $start,
            'duration_minutes' => $minutes,
            'participants' => mb_substr(trim(strip_tags((string) ($input['participants'] ?? ''))), 0, 10000),
            'summary' => mb_substr(trim(strip_tags((string) ($input['summary'] ?? ''))), 0, 10000),
            'decisions' => mb_substr(trim(strip_tags((string) ($input['decisions'] ?? ''))), 0, 10000),
            'actions' => mb_substr(trim(strip_tags((string) ($input['actions'] ?? ''))), 0, 10000),
            'date_creation' => $now,
            'date_mod' => $now,
        ];
        try {
            $DB->doQuery('START TRANSACTION');
            if (!$DB->insert(self::TABLE, $data)) {
                $DB->doQuery('ROLLBACK');
                return false;
            }
            $id = (int) $DB->insertId();
            if (!$this->worklogs->syncMeetingLog($projectId, $id, $uid, date('Y-m-d', strtotime($start)), $minutes, 'Reunião: ' . $title, $taskId)) {
                $DB->doQuery('ROLLBACK');
                return false;
            }
            $DB->doQuery('COMMIT');
            return $id;
        } catch (\Throwable $e) {
            try { $DB->doQuery('ROLLBACK'); } catch (\Throwable) {}
            \Toolbox::logError('[Project Flow] create meeting transaction: ' . $e->getMessage());
            return false;
        }
    }

    private function updateMeeting(array $where, array $input): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return false;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 1]);
        if (!$it->count()) return false;
        $old = $it->current();
        $data = ['date_mod' => date('Y-m-d H:i:s')];
        foreach (['title', 'participants', 'summary', 'decisions', 'actions'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = mb_substr(trim(strip_tags((string) $input[$field])), 0, $field === 'title' ? 255 : 10000);
            }
        }
        if (isset($data['title']) && $data['title'] === '') return false;
        if (array_key_exists('start_at', $input)) {
            $date = $this->date($input['start_at']);
            if (!$date) return false;
            $data['start_at'] = $date;
        }
        if (array_key_exists('duration_minutes', $input)) {
            $data['duration_minutes'] = max(1, min(1440, (int) $input['duration_minutes']));
        }
        try {
            $DB->doQuery('START TRANSACTION');
            if (!$DB->update(self::TABLE, $data, ['id' => (int) $old['id']])) {
                $DB->doQuery('ROLLBACK');
                return false;
            }
            $merged = array_merge($old, $data);
            if (!$this->worklogs->syncMeetingLog(
                (int) $merged['projects_id'],
                (int) $merged['id'],
                (int) $merged['users_id'],
                date('Y-m-d', strtotime((string) $merged['start_at'])),
                (int) $merged['duration_minutes'],
                'Reunião: ' . (string) $merged['title'],
                (int) ($merged['projecttasks_id'] ?? 0),
            )) {
                $DB->doQuery('ROLLBACK');
                return false;
            }
            $DB->doQuery('COMMIT');
            return true;
        } catch (\Throwable $e) {
            try { $DB->doQuery('ROLLBACK'); } catch (\Throwable) {}
            \Toolbox::logError('[Project Flow] update meeting transaction: ' . $e->getMessage());
            return false;
        }
    }

    private function deleteMeeting(array $where): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return false;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 1]);
        if (!$it->count()) return false;
        $row = $it->current();
        try {
            $DB->doQuery('START TRANSACTION');
            if (!$this->worklogs->deleteMeetingLog((int) $row['id'])) {
                $DB->doQuery('ROLLBACK');
                return false;
            }
            if (!$DB->delete(self::TABLE, ['id' => (int) $row['id']])) {
                $DB->doQuery('ROLLBACK');
                return false;
            }
            $DB->doQuery('COMMIT');
            return true;
        } catch (\Throwable $e) {
            try { $DB->doQuery('ROLLBACK'); } catch (\Throwable) {}
            \Toolbox::logError('[Project Flow] delete meeting transaction: ' . $e->getMessage());
            return false;
        }
    }

    private function normalize(array $row, bool $canUpdate): array
    {
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['projects_id'],
            'task_id' => (int) ($row['projecttasks_id'] ?? 0),
            'user_id' => (int) $row['users_id'],
            'user_name' => (int) $row['users_id'] ? getUserName((int) $row['users_id']) : 'Sistema',
            'title' => (string) $row['title'],
            'start_at' => $row['start_at'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'duration_label' => WorklogService::formatMinutes((int) $row['duration_minutes']),
            'participants' => (string) ($row['participants'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'decisions' => (string) ($row['decisions'] ?? ''),
            'actions' => (string) ($row['actions'] ?? ''),
            'can_update' => $canUpdate,
        ];
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
