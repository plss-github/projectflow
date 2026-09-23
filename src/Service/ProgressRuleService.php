<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use ProjectTask;
use Session;

/**
 * Rules driven by a task's progress (percent_done):
 * - change state: when the progress is changed by hand (not together with a state change),
 *   the task goes to the state of the first matching rule;
 * - notify: when the progress enters the rule range, an e-mail is queued to the selected
 *   recipients (requester, project manager, task team, extra group).
 *
 * Applied from the ProjectTask ITEM_UPDATE hook, so it covers Project Flow screens, the
 * Kanban and the native GLPI forms alike.
 */
class ProgressRuleService
{
    public const TABLE = 'glpi_plugin_projectflow_progressrules';
    private static bool $running = false;

    /**
     * Create the rules table when missing. Called by install/update and by the settings page,
     * so files replaced without running the plugin update still get the table.
     */
    public static function ensureTable(): void
    {
        global $DB;
        if ($DB->tableExists(self::TABLE)) return;
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_progressrules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(190) NOT NULL DEFAULT '',
            `min_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `max_percent` TINYINT UNSIGNED NOT NULL DEFAULT 100,
            `projectstates_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `notify_requester` TINYINT(1) NOT NULL DEFAULT 0,
            `notify_manager` TINYINT(1) NOT NULL DEFAULT 0,
            `notify_team` TINYINT(1) NOT NULL DEFAULT 0,
            `notify_groups_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `ranking` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_mod` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_active_range` (`is_active`, `min_percent`, `max_percent`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    public function list(bool $onlyActive = false): array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return [];
        $where = $onlyActive ? ['is_active' => 1] : [];
        $states = (new ReferenceService())->getStateMap();
        $rows = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDERBY' => ['ranking ASC', 'min_percent ASC', 'id ASC']]) as $row) {
            $stateId = (int) $row['projectstates_id'];
            $groupId = (int) $row['notify_groups_id'];
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'min_percent' => (int) $row['min_percent'],
                'max_percent' => (int) $row['max_percent'],
                'projectstates_id' => $stateId,
                'state' => $states[$stateId] ?? null,
                'notify_requester' => !empty($row['notify_requester']),
                'notify_manager' => !empty($row['notify_manager']),
                'notify_team' => !empty($row['notify_team']),
                'notify_groups_id' => $groupId,
                'group_name' => $groupId > 0 ? \Dropdown::getDropdownName('glpi_groups', $groupId) : '',
                'is_active' => !empty($row['is_active']),
                'ranking' => (int) $row['ranking'],
            ];
        }
        return $rows;
    }

    public function save(array $input): int|string
    {
        global $DB;
        if (!CatalogService::canManage() || !$DB->tableExists(self::TABLE)) return 'Operação não permitida.';
        $min = max(0, min(100, (int) ($input['min_percent'] ?? 0)));
        $max = max(0, min(100, (int) ($input['max_percent'] ?? 100)));
        if ($max < $min) return 'O percentual final precisa ser maior ou igual ao inicial.';
        $stateId = max(0, (int) ($input['projectstates_id'] ?? 0));
        if ($stateId > 0 && !isset((new ReferenceService())->getStateMap()[$stateId])) return 'Estado inválido.';
        $data = [
            'name' => mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 190) ?: "{$min}% a {$max}%",
            'min_percent' => $min,
            'max_percent' => $max,
            'projectstates_id' => $stateId,
            'notify_requester' => !empty($input['notify_requester']) ? 1 : 0,
            'notify_manager' => !empty($input['notify_manager']) ? 1 : 0,
            'notify_team' => !empty($input['notify_team']) ? 1 : 0,
            'notify_groups_id' => max(0, (int) ($input['notify_groups_id'] ?? 0)),
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'ranking' => max(0, min(9999, (int) ($input['ranking'] ?? 0))),
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        if ($stateId === 0 && !$data['notify_requester'] && !$data['notify_manager'] && !$data['notify_team'] && $data['notify_groups_id'] === 0) {
            return 'Escolha um estado de destino ou ao menos um destinatário de notificação.';
        }
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            if (!countElementsInTable(self::TABLE, ['id' => $id])) return 'Regra não encontrada.';
            return $DB->update(self::TABLE, $data, ['id' => $id]) ? $id : 'Não foi possível salvar a regra.';
        }
        return $DB->insert(self::TABLE, $data) ? (int) $DB->insertId() : 'Não foi possível salvar a regra.';
    }

    public function delete(int $id): bool
    {
        global $DB;
        if (!CatalogService::canManage() || !$DB->tableExists(self::TABLE) || $id <= 0) return false;
        return (bool) $DB->delete(self::TABLE, ['id' => $id]);
    }

    /** Hook entry point: $task has just been updated (updates/oldvalues are populated). */
    public function onTaskUpdated(ProjectTask $task): void
    {
        if (self::$running) return;
        $updates = is_array($task->updates ?? null) ? $task->updates : [];
        if (!in_array('percent_done', $updates, true)) return;
        $rules = $this->list(true);
        if ($rules === []) return;

        $new = (int) ($task->fields['percent_done'] ?? 0);
        $old = (int) ($task->oldvalues['percent_done'] ?? -1);
        $explicitState = in_array('projectstates_id', $updates, true);

        self::$running = true;
        try {
            $stateApplied = false;
            foreach ($rules as $rule) {
                if ($new < $rule['min_percent'] || $new > $rule['max_percent']) continue;

                if (!$stateApplied && $rule['projectstates_id'] > 0) {
                    $stateApplied = true; // first matching state rule wins
                    if (!$explicitState && (int) ($task->fields['projectstates_id'] ?? 0) !== $rule['projectstates_id']) {
                        $update = new ProjectTask();
                        $update->update(['id' => (int) $task->getID(), 'projectstates_id' => $rule['projectstates_id'], 'auto_projectstates' => 0]);
                        $task->fields['projectstates_id'] = $rule['projectstates_id'];
                    }
                }

                $entering = $old < $rule['min_percent'] || $old > $rule['max_percent'];
                if ($entering) $this->notify($task, $rule, $new);
            }
        } catch (\Throwable $e) {
            \Toolbox::logError('[Project Flow] progress rule: ' . $e->getMessage());
        } finally {
            self::$running = false;
        }
    }

    private function notify(ProjectTask $task, array $rule, int $percent): void
    {
        global $CFG_GLPI;
        $notifier = new NotificationService();
        $taskId = (int) $task->getID();
        $recipients = [];
        if ($rule['notify_requester']) {
            $meta = (new MetaService())->getTaskMeta($taskId);
            $recipients[] = (int) ($meta['requester_users_id'] ?: ($task->fields['users_id'] ?? 0));
        }
        $project = new Project();
        $hasProject = $project->getFromDB((int) ($task->fields['projects_id'] ?? 0));
        if ($rule['notify_manager'] && $hasProject) $recipients[] = (int) ($project->fields['users_id'] ?? 0);
        if ($rule['notify_team']) $recipients = array_merge($recipients, $notifier->taskTeamUserIds($taskId));
        if ($rule['notify_groups_id'] > 0) $recipients = array_merge($recipients, $notifier->groupUserIds([$rule['notify_groups_id']]));
        // Do not e-mail the person who made the change.
        $recipients = array_diff(array_unique(array_filter($recipients)), [(int) Session::getLoginUserID()]);
        if ($recipients === []) return;

        $taskName = (string) ($task->fields['name'] ?? ('Tarefa #' . $taskId));
        $projectName = $hasProject ? (string) ($project->fields['name'] ?? '') : '';
        $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/projectflow/front/task.php?id=' . $taskId;
        $subject = sprintf('Project Flow - %s chegou a %d%%', $taskName, $percent);
        $body = sprintf(
            "A tarefa \"%s\"%s chegou a %d%% de andamento (regra: %s).\nAlterado por: %s\nAcesse: %s",
            $taskName,
            $projectName !== '' ? ' do projeto "' . $projectName . '"' : '',
            $percent,
            $rule['name'],
            getUserName((int) Session::getLoginUserID()) ?: 'Sistema',
            $url
        );
        $notifier->queueForTask($task, $recipients, $subject, $body);
    }
}
