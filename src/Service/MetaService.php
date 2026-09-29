<?php

namespace GlpiPlugin\Projectflow\Service;

use Session;

class MetaService
{
    private const PROJECT_META = 'glpi_plugin_projectflow_projectmeta';
    private const TASK_META = 'glpi_plugin_projectflow_taskmeta';
    private const FAVORITES = 'glpi_plugin_projectflow_favorites';
    private const STATE_PROGRESS = 'glpi_plugin_projectflow_stateprogress';

    /** hours = horas; money = custo financeiro; both = horas valorizadas (valor/hora) + custos avulsos. */
    public const COST_MODES = ['hours', 'money', 'both'];

    public static function costModeHasHours(string $mode): bool { return $mode === 'hours' || $mode === 'both'; }
    public static function costModeHasMoney(string $mode): bool { return $mode === 'money' || $mode === 'both'; }

    /** Accepts "150", "150.5", "150,50" and "1.234,56". */
    public static function parseMoney(mixed $value): float
    {
        $v = trim(str_replace(['R$', ' '], '', (string) $value));
        if ($v === '') return 0.0;
        if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
        return max(0.0, min(99999999.99, round((float) $v, 2)));
    }

    private function ensureHourRateColumn(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        global $DB;
        if ($DB->tableExists(self::PROJECT_META) && !$DB->fieldExists(self::PROJECT_META, 'hour_rate')) {
            $DB->doQuery('ALTER TABLE `' . self::PROJECT_META . '` ADD `hour_rate` DECIMAL(12,2) NOT NULL DEFAULT 0');
        }
    }

    /** Hourly cron attempts before an undeliverable e-mail reminder is abandoned (~24h). */
    public const REMINDER_MAX_ATTEMPTS = 24;

    public function getForProject(int $projectId): array
    {
        global $DB;
        $defaults = [
            'health' => 'auto', 'risk_level' => 'normal', 'portfolio' => '', 'sponsor' => '', 'objective' => '',
            'execution_mode' => 'direct', 'cost_mode' => 'hours', 'hours_budget_minutes' => 0, 'hour_rate' => 0,
        ];
        if (!$DB->tableExists(self::PROJECT_META)) return $defaults;
        $this->ensureHourRateColumn();
        $it = $DB->request(['FROM' => self::PROJECT_META, 'WHERE' => ['projects_id' => $projectId], 'LIMIT' => 1]);
        return $it->count() ? array_merge($defaults, $it->current()) : $defaults;
    }

    /** Parses a stored list of state ids ("1,4,7" or an array). Empty = every state. */
    public static function parseStateIds(mixed $value): array
    {
        if (is_string($value)) $value = $value === '' ? [] : explode(',', $value);
        if (!is_array($value)) return [];
        $ids = [];
        foreach ($value as $id) { $id = (int) $id; if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id; }
        return $ids;
    }

    /** Sorted id list for comparisons ('' and the full set both mean "every state"). */
    public static function normalizeStateIdsForCompare(mixed $value): array
    {
        $ids = self::parseStateIds($value);
        $all = array_map(static fn(array $s): int => (int) $s['id'], (new ReferenceService())->getProjectStates());
        $ids = array_values(array_intersect($ids, $all));
        if (count($ids) === count($all)) $ids = [];
        sort($ids);
        return $ids;
    }

    /** Keeps only existing states, in catalog order; the full set is stored as '' (= all states). */
    public function normalizeStateList(mixed $value, int $mustInclude = 0): string
    {
        $wanted = self::parseStateIds($value);
        if (!$wanted) return '';
        if ($mustInclude > 0 && !in_array($mustInclude, $wanted, true)) $wanted[] = $mustInclude;
        $all = array_map(static fn(array $s): int => (int) $s['id'], (new ReferenceService())->getProjectStates());
        $ids = array_values(array_filter($all, static fn(int $id): bool => in_array($id, $wanted, true)));
        return (!$ids || count($ids) === count($all)) ? '' : implode(',', $ids);
    }

    private function ensureAllowedStatesColumn(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        global $DB;
        if ($DB->tableExists(self::TASK_META) && !$DB->fieldExists(self::TASK_META, 'allowed_states')) {
            $DB->doQuery('ALTER TABLE `' . self::TASK_META . '` ADD `allowed_states` VARCHAR(1000) NOT NULL DEFAULT \'\'');
        }
    }

    public function getForProjects(array $projectIds): array
    {
        global $DB;
        if (!$projectIds || !$DB->tableExists(self::PROJECT_META)) return [];
        $this->ensureHourRateColumn();
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
            'cost_mode' => in_array($costMode, self::COST_MODES, true) ? $costMode : 'hours',
            'hours_budget_minutes' => array_key_exists('hours_budget_hours', $input)
                ? max(0, min(10000000, (int) round(((float) str_replace(',', '.', (string) $input['hours_budget_hours'])) * 60)))
                : max(0, (int) ($current['hours_budget_minutes'] ?? 0)),
            'hour_rate' => array_key_exists('hour_rate', $input) && trim((string) $input['hour_rate']) !== ''
                ? self::parseMoney($input['hour_rate'])
                : max(0.0, (float) ($current['hour_rate'] ?? 0)),
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
            'reminder_email' => 1, 'reminder_browser' => 1, 'reminder_sent_at' => null, 'reminder_attempts' => 0, 'allowed_states' => '',
        ];
        if (!$DB->tableExists(self::TASK_META)) return $defaults;
        $this->ensureAllowedStatesColumn();
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
        $this->ensureAllowedStatesColumn();
        $result = [];
        foreach ($DB->request(['FROM' => self::TASK_META, 'WHERE' => ['projecttasks_id' => array_values(array_unique(array_map('intval', $taskIds)))]]) as $row) {
            $id = (int) $row['projecttasks_id'];
            $result[$id] = array_merge($this->getTaskMetaDefaults(), $row);
            $result[$id]['priority'] = max(1, min(6, (int) $result[$id]['priority']));
        }
        return $result;
    }

    public function getTaskMetaDefaults(): array
    {
        return ['requester_users_id' => 0, 'priority' => 3, 'attention' => 0, 'attention_note' => '', 'reminder_at' => null, 'reminder_email' => 1, 'reminder_browser' => 1, 'reminder_sent_at' => null, 'reminder_attempts' => 0, 'allowed_states' => ''];
    }

    /** States a task may use; empty = every state. */
    public function getAllowedStateIds(int $taskId): array
    {
        return self::parseStateIds((string) ($this->getTaskMeta($taskId)['allowed_states'] ?? ''));
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
            'allowed_states' => array_key_exists('allowed_states', $input)
                ? $this->normalizeStateList($input['allowed_states'], (int) ($input['_current_state_id'] ?? 0))
                : (string) ($current['allowed_states'] ?? ''),
        ];
        if ($changedReminder && $DB->fieldExists(self::TASK_META, 'reminder_attempts')) {
            $data['reminder_attempts'] = 0;
        }
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
            ] + (($paused = $this->getPausedTaskIds()) ? [['NOT' => ['projecttasks_id' => $paused]]] : []),
            // Reminders that keep failing (no recipient e-mail, no sender) sink to the end of
            // the queue so they can never starve newer reminders out of the batch.
            'ORDERBY' => $DB->fieldExists(self::TASK_META, 'reminder_attempts') ? ['reminder_attempts ASC', 'reminder_at ASC'] : ['reminder_at ASC'],
            'LIMIT' => 200,
        ]) as $row) $rows[] = $row;
        return $rows;
    }

    /**
     * Register an unsuccessful delivery attempt. Returns true when the reminder was abandoned
     * (marked as sent) after REMINDER_MAX_ATTEMPTS attempts.
     */
    public function markReminderFailed(int $taskId): bool
    {
        global $DB;
        if (!$DB->tableExists(self::TASK_META) || !$DB->fieldExists(self::TASK_META, 'reminder_attempts')) return false;
        $it = $DB->request(['SELECT' => ['reminder_attempts'], 'FROM' => self::TASK_META, 'WHERE' => ['projecttasks_id' => $taskId], 'LIMIT' => 1]);
        if (!$it->count()) return false;
        $attempts = min(255, (int) $it->current()['reminder_attempts'] + 1);
        $data = ['reminder_attempts' => $attempts];
        $abandon = $attempts >= self::REMINDER_MAX_ATTEMPTS;
        if ($abandon) $data['reminder_sent_at'] = date('Y-m-d H:i:s');
        $DB->update(self::TASK_META, $data, ['projecttasks_id' => $taskId]);
        return $abandon;
    }

    public function markReminderSent(int $taskId): void
    {
        global $DB;
        if ($DB->tableExists(self::TASK_META)) {
            $DB->update(self::TASK_META, ['reminder_sent_at' => date('Y-m-d H:i:s')], ['projecttasks_id' => $taskId]);
        }
    }

    // ---- Estados que pausam o projeto ------------------------------------------------------
    private static ?array $pausedStatesCache = null;

    private function ensurePausedColumn(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        global $DB;
        if ($DB->tableExists(self::STATE_PROGRESS) && !$DB->fieldExists(self::STATE_PROGRESS, 'is_paused')) {
            $DB->doQuery('ALTER TABLE `' . self::STATE_PROGRESS . '` ADD `is_paused` TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    /** States flagged as "pausa o projeto": a project in one of them sends no notifications. */
    public function getPausedStateIds(): array
    {
        if (self::$pausedStatesCache !== null) return self::$pausedStatesCache;
        global $DB;
        if (!$DB->tableExists(self::STATE_PROGRESS)) return self::$pausedStatesCache = [];
        $this->ensurePausedColumn();
        $ids = [];
        foreach ($DB->request(['SELECT' => ['projectstates_id'], 'FROM' => self::STATE_PROGRESS, 'WHERE' => ['is_paused' => 1]]) as $row) $ids[] = (int) $row['projectstates_id'];
        return self::$pausedStatesCache = $ids;
    }

    public function isPausedState(int $stateId): bool
    {
        return $stateId > 0 && in_array($stateId, $this->getPausedStateIds(), true);
    }

    public function setStatePaused(int $stateId, bool $paused): bool
    {
        global $DB;
        if ($stateId <= 0 || !$DB->tableExists(self::STATE_PROGRESS)) return false;
        $this->ensurePausedColumn();
        self::$pausedStatesCache = null;
        if (countElementsInTable(self::STATE_PROGRESS, ['projectstates_id' => $stateId])) {
            return (bool) $DB->update(self::STATE_PROGRESS, ['is_paused' => $paused ? 1 : 0], ['projectstates_id' => $stateId]);
        }
        return (bool) $DB->insert(self::STATE_PROGRESS, ['projectstates_id' => $stateId, 'percent_done' => 0, 'is_paused' => $paused ? 1 : 0]);
    }

    public function isProjectPaused(int $projectId): bool
    {
        global $DB;
        if ($projectId <= 0 || !$this->getPausedStateIds()) return false;
        $it = $DB->request(['SELECT' => ['projectstates_id'], 'FROM' => 'glpi_projects', 'WHERE' => ['id' => $projectId], 'LIMIT' => 1]);
        return $it->count() > 0 && $this->isPausedState((int) $it->current()['projectstates_id']);
    }

    /** Ids of the tasks that belong to paused projects. */
    public function getPausedTaskIds(): array
    {
        global $DB;
        $states = $this->getPausedStateIds();
        if (!$states) return [];
        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['glpi_projecttasks.id'],
            'FROM' => 'glpi_projecttasks',
            'INNER JOIN' => ['glpi_projects' => ['ON' => ['glpi_projecttasks' => 'projects_id', 'glpi_projects' => 'id']]],
            'WHERE' => ['glpi_projects.projectstates_id' => $states],
        ]) as $row) $ids[] = (int) $row['id'];
        return $ids;
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
