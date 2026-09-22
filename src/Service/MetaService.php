<?php

namespace GlpiPlugin\Projectflow\Service;

use Session;

class MetaService
{
    private const PROJECT_META = 'glpi_plugin_projectflow_projectmeta';
    private const TASK_META = 'glpi_plugin_projectflow_taskmeta';
    private const FAVORITES = 'glpi_plugin_projectflow_favorites';
    private const STATE_PROGRESS = 'glpi_plugin_projectflow_stateprogress';

    public function getForProject(int $projectId): array
    {
        global $DB;
        $defaults = [
            'health' => 'auto', 'risk_level' => 'normal', 'portfolio' => '', 'sponsor' => '', 'objective' => '',
            'execution_mode' => 'direct', 'cost_mode' => 'hours', 'hours_budget_minutes' => 0,
        ];
        if (!$DB->tableExists(self::PROJECT_META)) return $defaults;
        $it = $DB->request(['FROM' => self::PROJECT_META, 'WHERE' => ['projects_id' => $projectId], 'LIMIT' => 1]);
        return $it->count() ? array_merge($defaults, $it->current()) : $defaults;
    }

    public function getForProjects(array $projectIds): array
    {
        global $DB;
        if (!$projectIds || !$DB->tableExists(self::PROJECT_META)) return [];
        $result = [];
        foreach ($DB->request(['FROM' => self::PROJECT_META, 'WHERE' => ['projects_id' => array_values(array_unique(array_map('intval', $projectIds)))]]) as $row) {
            $result[(int) $row['projects_id']] = $row;
        }
        return $result;
    }

    public function save(int $projectId, array $input): bool
    {
        global $DB;
        if (!$DB->tableExists(self::PROJECT_META)) return false;
        $current = $this->getForProject($projectId);
        $health = (string) ($input['health'] ?? $current['health']);
        $risk = (string) ($input['risk_level'] ?? $current['risk_level']);
        $executionMode = (string) ($input['execution_mode'] ?? $current['execution_mode']);
        $costMode = (string) ($input['cost_mode'] ?? $current['cost_mode']);
        $data = [
            'projects_id' => $projectId,
            'health' => in_array($health, ['auto', 'good', 'attention', 'critical'], true) ? $health : 'auto',
            'risk_level' => in_array($risk, ['low', 'normal', 'high', 'critical'], true) ? $risk : 'normal',
            'portfolio' => mb_substr(trim(strip_tags((string) ($input['portfolio'] ?? $current['portfolio']))), 0, 190),
            'sponsor' => mb_substr(trim(strip_tags((string) ($input['sponsor'] ?? $current['sponsor']))), 0, 190),
            'objective' => mb_substr(trim(strip_tags((string) ($input['objective'] ?? $current['objective']))), 0, 4000),
            'execution_mode' => in_array($executionMode, ['direct', 'ticket'], true) ? $executionMode : 'direct',
            'cost_mode' => in_array($costMode, ['hours', 'money'], true) ? $costMode : 'hours',
            'hours_budget_minutes' => array_key_exists('hours_budget_hours', $input)
                ? max(0, min(10000000, (int) round(((float) str_replace(',', '.', (string) $input['hours_budget_hours'])) * 60)))
                : max(0, (int) ($current['hours_budget_minutes'] ?? 0)),
        ];
        if (countElementsInTable(self::PROJECT_META, ['projects_id' => $projectId])) {
            return $DB->update(self::PROJECT_META, $data, ['projects_id' => $projectId]);
        }
        return (bool) $DB->insert(self::PROJECT_META, $data);
    }

    public function getTaskMeta(int $taskId): array
    {
        global $DB;
        $defaults = [
            'requester_users_id' => 0, 'priority' => 3, 'attention' => 0, 'attention_note' => '', 'reminder_at' => null,
            'reminder_email' => 1, 'reminder_browser' => 1, 'reminder_sent_at' => null,
        ];
        if (!$DB->tableExists(self::TASK_META)) return $defaults;
        $it = $DB->request(['FROM' => self::TASK_META, 'WHERE' => ['projecttasks_id' => $taskId], 'LIMIT' => 1]);
        if (!$it->count()) return $defaults;
        $row = array_merge($defaults, $it->current());
        $row['priority'] = max(1, min(6, (int) $row['priority']));
        $row['attention'] = (int) !empty($row['attention']);
        $row['reminder_email'] = (int) !empty($row['reminder_email']);
        $row['reminder_browser'] = (int) !empty($row['reminder_browser']);
        return $row;
    }

    public function getTaskMetas(array $taskIds): array
    {
        global $DB;
        if (!$taskIds || !$DB->tableExists(self::TASK_META)) return [];
        $result = [];
        foreach ($DB->request(['FROM' => self::TASK_META, 'WHERE' => ['projecttasks_id' => array_values(array_unique(array_map('intval', $taskIds)))]]) as $row) {
            $id = (int) $row['projecttasks_id'];
            $result[$id] = array_merge($this->getTaskMetaDefaults(), $row);
            $result[$id]['priority'] = max(1, min(6, (int) $result[$id]['priority']));
        }
        return $result;
    }

    private function getTaskMetaDefaults(): array
    {
        return ['requester_users_id' => 0, 'priority' => 3, 'attention' => 0, 'attention_note' => '', 'reminder_at' => null, 'reminder_email' => 1, 'reminder_browser' => 1, 'reminder_sent_at' => null];
    }

    public function saveTaskMeta(int $taskId, array $input): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TASK_META)) return false;
        $current = $this->getTaskMeta($taskId);
        $reminder = array_key_exists('reminder_at', $input) ? $this->date($input['reminder_at']) : $current['reminder_at'];
        $changedReminder = (string) ($current['reminder_at'] ?? '') !== (string) ($reminder ?? '');
        $data = [
            'projecttasks_id' => $taskId,
            'requester_users_id' => max(0, (int) ($input['requester_users_id'] ?? $current['requester_users_id'] ?? 0)),
            'priority' => max(1, min(6, (int) ($input['priority'] ?? $current['priority']))),
            'attention' => array_key_exists('attention', $input) ? (!empty($input['attention']) ? 1 : 0) : (int) $current['attention'],
            'attention_note' => mb_substr(trim(strip_tags((string) ($input['attention_note'] ?? $current['attention_note']))), 0, 4000),
            'reminder_at' => $reminder,
            'reminder_email' => array_key_exists('reminder_email', $input) ? (!empty($input['reminder_email']) ? 1 : 0) : (int) $current['reminder_email'],
            'reminder_browser' => array_key_exists('reminder_browser', $input) ? (!empty($input['reminder_browser']) ? 1 : 0) : (int) $current['reminder_browser'],
            'reminder_sent_at' => $changedReminder ? null : $current['reminder_sent_at'],
        ];
        if (countElementsInTable(self::TASK_META, ['projecttasks_id' => $taskId])) {
            return $DB->update(self::TASK_META, $data, ['projecttasks_id' => $taskId]);
        }
        return (bool) $DB->insert(self::TASK_META, $data);
    }

    public function getTaskPriority(int $taskId): int { return (int) $this->getTaskMeta($taskId)['priority']; }

    public function getTaskPriorities(array $taskIds): array
    {
        $out = [];
        foreach ($this->getTaskMetas($taskIds) as $id => $meta) $out[$id] = (int) $meta['priority'];
        return $out;
    }

    public function saveTaskPriority(int $taskId, mixed $priority): bool { return $this->saveTaskMeta($taskId, ['priority' => $priority]); }

    public function getPendingReminders(?string $now = null): array
    {
        global $DB;
        if (!$DB->tableExists(self::TASK_META)) return [];
        $now ??= date('Y-m-d H:i:s');
        $rows = [];
        foreach ($DB->request([
            'FROM' => self::TASK_META,
            'WHERE' => [
                'NOT' => ['reminder_at' => null],
                ['reminder_at' => ['<=', $now]],
                'reminder_email' => 1,
                'reminder_sent_at' => null,
            ],
            'ORDERBY' => ['reminder_at ASC'],
            'LIMIT' => 200,
        ]) as $row) $rows[] = $row;
        return $rows;
    }

    public function markReminderSent(int $taskId): void
    {
        global $DB;
        if ($DB->tableExists(self::TASK_META)) {
            $DB->update(self::TASK_META, ['reminder_sent_at' => date('Y-m-d H:i:s')], ['projecttasks_id' => $taskId]);
        }
    }

    public function getStateProgressMap(): array
    {
        global $DB;
        $result = [];
        if (!$DB->tableExists(self::STATE_PROGRESS)) return $result;
        foreach ($DB->request(['FROM' => self::STATE_PROGRESS]) as $row) $result[(int) $row['projectstates_id']] = max(0, min(100, (int) $row['percent_done']));
        return $result;
    }

    public function getStateProgress(int $stateId, int $fallback = 0): int
    {
        $map = $this->getStateProgressMap();
        return $map[$stateId] ?? max(0, min(100, $fallback));
    }

    public function setStateProgress(int $stateId, int $percent): bool
    {
        global $DB;
        if ($stateId <= 0 || !$DB->tableExists(self::STATE_PROGRESS)) return false;
        $data = ['projectstates_id' => $stateId, 'percent_done' => max(0, min(100, $percent))];
        if (countElementsInTable(self::STATE_PROGRESS, ['projectstates_id' => $stateId])) return $DB->update(self::STATE_PROGRESS, $data, ['projectstates_id' => $stateId]);
        return (bool) $DB->insert(self::STATE_PROGRESS, $data);
    }

    public function getFavoriteIds(): array
    {
        global $DB;
        $userId = (int) Session::getLoginUserID();
        if (!$userId || !$DB->tableExists(self::FAVORITES)) return [];
        $ids = [];
        foreach ($DB->request(['SELECT' => ['projects_id'], 'FROM' => self::FAVORITES, 'WHERE' => ['users_id' => $userId]]) as $row) $ids[(int) $row['projects_id']] = true;
        return $ids;
    }

    public function toggleFavorite(int $projectId): bool
    {
        global $DB;
        $userId = (int) Session::getLoginUserID();
        if (!$userId || !$DB->tableExists(self::FAVORITES)) return false;
        $where = ['users_id' => $userId, 'projects_id' => $projectId];
        if (countElementsInTable(self::FAVORITES, $where)) { $DB->delete(self::FAVORITES, $where); return false; }
        $DB->insert(self::FAVORITES, $where);
        return true;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
