<?php

use GlpiPlugin\Projectflow\Automation;

function plugin_projectflow_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_PROJECTFLOW_VERSION);

    if (!$DB->tableExists('glpi_plugin_projectflow_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_configs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(128) NOT NULL,
            `value` LONGTEXT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_projectmeta')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_projectmeta` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projects_id` INT UNSIGNED NOT NULL,
            `health` VARCHAR(20) NOT NULL DEFAULT 'auto',
            `risk_level` VARCHAR(20) NOT NULL DEFAULT 'normal',
            `portfolio` VARCHAR(190) NOT NULL DEFAULT '',
            `sponsor` VARCHAR(190) NOT NULL DEFAULT '',
            `objective` TEXT NULL,
            `execution_mode` VARCHAR(20) NOT NULL DEFAULT 'direct',
            `cost_mode` VARCHAR(20) NOT NULL DEFAULT 'hours',
            `hours_budget_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
            `hour_rate` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_project` (`projects_id`),
            KEY `idx_portfolio` (`portfolio`),
            KEY `idx_execution_mode` (`execution_mode`),
            KEY `idx_cost_mode` (`cost_mode`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    } else {
        projectflow_add_column('glpi_plugin_projectflow_projectmeta', 'execution_mode', "VARCHAR(20) NOT NULL DEFAULT 'direct'");
        projectflow_add_column('glpi_plugin_projectflow_projectmeta', 'cost_mode', "VARCHAR(20) NOT NULL DEFAULT 'hours'");
        projectflow_add_column('glpi_plugin_projectflow_projectmeta', 'hours_budget_minutes', "INT UNSIGNED NOT NULL DEFAULT 0");
        projectflow_add_column('glpi_plugin_projectflow_projectmeta', 'hour_rate', "DECIMAL(12,2) NOT NULL DEFAULT 0");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_taskmeta')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_taskmeta` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projecttasks_id` INT UNSIGNED NOT NULL,
            `requester_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `priority` TINYINT UNSIGNED NOT NULL DEFAULT 3,
            `attention` TINYINT(1) NOT NULL DEFAULT 0,
            `attention_note` TEXT NULL,
            `reminder_at` DATETIME NULL,
            `reminder_email` TINYINT(1) NOT NULL DEFAULT 1,
            `reminder_browser` TINYINT(1) NOT NULL DEFAULT 1,
            `reminder_sent_at` DATETIME NULL,
            `reminder_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `allowed_states` VARCHAR(1000) NOT NULL DEFAULT '',
            `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_task` (`projecttasks_id`),
            KEY `idx_priority` (`priority`),
            KEY `idx_attention` (`attention`),
            KEY `idx_reminder_at` (`reminder_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    } else {
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'requester_users_id', "INT UNSIGNED NOT NULL DEFAULT 0");
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'attention', "TINYINT(1) NOT NULL DEFAULT 0");
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'attention_note', 'TEXT NULL');
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'reminder_at', 'DATETIME NULL');
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'reminder_email', "TINYINT(1) NOT NULL DEFAULT 1");
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'reminder_browser', "TINYINT(1) NOT NULL DEFAULT 1");
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'reminder_sent_at', 'DATETIME NULL');
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'reminder_attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
        projectflow_add_column('glpi_plugin_projectflow_taskmeta', 'allowed_states', "VARCHAR(1000) NOT NULL DEFAULT ''");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_stateprogress')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_stateprogress` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projectstates_id` INT UNSIGNED NOT NULL,
            `percent_done` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `is_paused` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_state` (`projectstates_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }
    projectflow_add_column('glpi_plugin_projectflow_stateprogress', 'is_paused', "TINYINT(1) NOT NULL DEFAULT 0");

    if (!$DB->tableExists('glpi_plugin_projectflow_favorites')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_favorites` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id` INT UNSIGNED NOT NULL,
            `projects_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_user_project` (`users_id`,`projects_id`),
            KEY `idx_project` (`projects_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_activities')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_activities` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projecttasks_id` INT UNSIGNED NOT NULL,
            `users_id` INT UNSIGNED NOT NULL,
            `content` TEXT NOT NULL,
            `date_creation` DATETIME NOT NULL,
            `date_mod` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_task` (`projecttasks_id`),
            KEY `idx_user` (`users_id`),
            KEY `idx_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_worklogs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_worklogs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projects_id` INT UNSIGNED NOT NULL,
            `projecttasks_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `meetings_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL,
            `work_type` VARCHAR(20) NOT NULL DEFAULT 'execution',
            `work_date` DATE NOT NULL,
            `minutes` INT UNSIGNED NOT NULL DEFAULT 0,
            `comment` TEXT NULL,
            `date_creation` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_project_date` (`projects_id`,`work_date`),
            KEY `idx_task` (`projecttasks_id`),
            KEY `idx_meeting` (`meetings_id`),
            KEY `idx_user` (`users_id`),
            KEY `idx_type` (`work_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_meetings')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_meetings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projects_id` INT UNSIGNED NOT NULL,
            `projecttasks_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `start_at` DATETIME NOT NULL,
            `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
            `participants` TEXT NULL,
            `summary` TEXT NULL,
            `decisions` TEXT NULL,
            `actions` TEXT NULL,
            `date_creation` DATETIME NOT NULL,
            `date_mod` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_project_start` (`projects_id`,`start_at`),
            KEY `idx_task` (`projecttasks_id`),
            KEY `idx_user` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    } else {
        projectflow_add_column('glpi_plugin_projectflow_meetings', 'projecttasks_id', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `projects_id`");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_taskassets')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_taskassets` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projecttasks_id` INT UNSIGNED NOT NULL,
            `itemtype` VARCHAR(100) NOT NULL,
            `items_id` INT UNSIGNED NOT NULL,
            `users_id` INT UNSIGNED NOT NULL,
            `date_creation` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_task_asset` (`projecttasks_id`,`itemtype`,`items_id`),
            KEY `idx_asset` (`itemtype`,`items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_projectflow_weeklyreports')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_projectflow_weeklyreports` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projects_id` INT UNSIGNED NOT NULL,
            `week_start` DATE NOT NULL,
            `week_end` DATE NOT NULL,
            `content` LONGTEXT NOT NULL,
            `users_id` INT UNSIGNED NOT NULL,
            `date_generation` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_project_week` (`projects_id`,`week_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    \GlpiPlugin\Projectflow\Service\ProgressRuleService::ensureTable();

    $states = [];
    if ($DB->tableExists('glpi_projectstates')) {
        foreach ($DB->request([
            'SELECT' => ['id', 'is_finished'],
            'FROM' => 'glpi_projectstates',
            'ORDERBY' => ['is_finished ASC', 'id ASC'],
        ]) as $row) {
            $states[] = ['id' => (int) $row['id'], 'finished' => (bool) $row['is_finished']];
        }
    }
    $firstOpenState = 0;
    foreach ($states as $state) {
        if (!$state['finished']) {
            $firstOpenState = $state['id'];
            break;
        }
    }

    $defaults = [
        'dashboard_limit' => '250',
        'show_finished_states' => '1',
        'compact_cards' => '0',
        'default_dashboard_view' => 'cards',
        'health_due_soon_days' => '7',
        'auto_progress_on_kanban' => '1',
        'auto_add_task_member_to_project_team' => '1',
        'default_project_state_id' => (string) $firstOpenState,
        'default_task_state_id' => (string) $firstOpenState,
        'default_execution_mode' => 'direct',
        'default_cost_mode' => 'hours',
        'reminder_email_enabled' => '1',
        'replace_native_projects_menu' => '1',
    ];
    foreach ($defaults as $name => $value) {
        if (countElementsInTable('glpi_plugin_projectflow_configs', ['name' => $name]) === 0) {
            $DB->insert('glpi_plugin_projectflow_configs', ['name' => $name, 'value' => $value]);
        }
    }
    // Options that were never implemented and are no longer read by the plugin.
    $DB->delete('glpi_plugin_projectflow_configs', ['name' => ['weekly_sprint_start']]);

    $unfinished = array_values(array_filter($states, static fn(array $state): bool => !$state['finished']));
    $unfinishedCount = count($unfinished);
    $unfinishedIndex = [];
    foreach ($unfinished as $index => $state) {
        $unfinishedIndex[$state['id']] = $index;
    }
    foreach ($states as $state) {
        if (countElementsInTable('glpi_plugin_projectflow_stateprogress', ['projectstates_id' => $state['id']])) {
            continue;
        }
        if ($state['finished']) {
            $percent = 100;
        } elseif ($unfinishedCount <= 1) {
            $percent = 0;
        } else {
            $percent = (int) round((($unfinishedIndex[$state['id']] ?? 0) / max(1, $unfinishedCount - 1)) * 90 / 5) * 5;
        }
        $DB->insert('glpi_plugin_projectflow_stateprogress', [
            'projectstates_id' => $state['id'],
            'percent_done' => max(0, min(100, $percent)),
        ]);
    }

    if (class_exists(CronTask::class)) {
        CronTask::register(
            Automation::class,
            'taskreminders',
            HOUR_TIMESTAMP,
            [
                'comment' => 'Project Flow - lembretes de tarefas',
                'mode' => CronTask::MODE_INTERNAL,
            ]
        );
    }

    $migration->executeMigration();
    return true;
}

function projectflow_add_column(string $table, string $field, string $definition): void
{
    global $DB;
    if ($DB->tableExists($table) && !$DB->fieldExists($table, $field)) {
        $DB->doQuery("ALTER TABLE `$table` ADD `$field` $definition");
    }
}

function plugin_projectflow_uninstall(): bool
{
    global $DB;

    CronTask::unregister('projectflow');
    foreach ([
        'glpi_plugin_projectflow_progressrules',
        'glpi_plugin_projectflow_weeklyreports',
        'glpi_plugin_projectflow_taskassets',
        'glpi_plugin_projectflow_meetings',
        'glpi_plugin_projectflow_worklogs',
        'glpi_plugin_projectflow_activities',
        'glpi_plugin_projectflow_favorites',
        'glpi_plugin_projectflow_stateprogress',
        'glpi_plugin_projectflow_taskmeta',
        'glpi_plugin_projectflow_projectmeta',
        'glpi_plugin_projectflow_configs',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }
    return true;
}


/**
 * Remove Project Flow metadata after a native project/task is permanently purged.
 * Native GLPI relations remain owned by GLPI; this function only removes tables
 * created by this plugin.
 */
function plugin_projectflow_item_purge(CommonDBTM $item): void
{
    global $DB;

    $id = (int) ($item->fields['id'] ?? 0);
    if ($id <= 0) {
        return;
    }

    if ($item instanceof ProjectTask) {
        $tables = [
            'glpi_plugin_projectflow_taskmeta' => ['projecttasks_id' => $id],
            'glpi_plugin_projectflow_activities' => ['projecttasks_id' => $id],
            'glpi_plugin_projectflow_worklogs' => ['projecttasks_id' => $id],
            'glpi_plugin_projectflow_meetings' => ['projecttasks_id' => $id],
            'glpi_plugin_projectflow_taskassets' => ['projecttasks_id' => $id],
        ];
        foreach ($tables as $table => $where) {
            if ($DB->tableExists($table)) {
                $DB->delete($table, $where);
            }
        }
        return;
    }

    if ($item instanceof Project) {
        $tables = [
            'glpi_plugin_projectflow_projectmeta' => ['projects_id' => $id],
            'glpi_plugin_projectflow_favorites' => ['projects_id' => $id],
            'glpi_plugin_projectflow_worklogs' => ['projects_id' => $id],
            'glpi_plugin_projectflow_meetings' => ['projects_id' => $id],
            'glpi_plugin_projectflow_weeklyreports' => ['projects_id' => $id],
        ];
        foreach ($tables as $table => $where) {
            if ($DB->tableExists($table)) {
                $DB->delete($table, $where);
            }
        }
    }
}


/**
 * Apply progress rules after a native ProjectTask update.
 */
function plugin_projectflow_item_update(CommonDBTM $item): void
{
    if (!$item instanceof ProjectTask) {
        return;
    }
    try {
        (new \GlpiPlugin\Projectflow\Service\ProgressRuleService())->onTaskUpdated($item);
    } catch (\Throwable $e) {
        \Toolbox::logError('[Project Flow] item_update: ' . $e->getMessage());
    }
}


/**
 * Mirror task attachments on the project: when a Document is linked to a ProjectTask, the
 * same Document is linked to the task's Project (native Document_Item, no file copy).
 */
function plugin_projectflow_document_item_add(CommonDBTM $item): void
{
    if (!$item instanceof Document_Item || ($item->fields['itemtype'] ?? '') !== ProjectTask::class) {
        return;
    }
    try {
        (new \GlpiPlugin\Projectflow\Service\DocumentService())->mirrorTaskDocumentOnProject(
            (int) ($item->fields['documents_id'] ?? 0),
            (int) ($item->fields['items_id'] ?? 0)
        );
    } catch (\Throwable $e) {
        \Toolbox::logError('[Project Flow] document mirror: ' . $e->getMessage());
    }
}


/**
 * Before a ProjectTask is added/updated (any screen): percent_done is taken from the state
 * (percent per state, finished state = 100) and GLPI's automatic percent is disabled.
 */
function plugin_projectflow_pre_task_save(CommonDBTM $item): void
{
    if (!$item instanceof ProjectTask || !is_array($item->input ?? null)) {
        return;
    }
    $input = &$item->input;
    $isAdd = empty($item->fields['id']);
    // Paused project: GLPI's own notifications for its tasks are not sent either.
    $projectId = (int) ($input['projects_id'] ?? ($item->fields['projects_id'] ?? 0));
    if ($projectId > 0 && (new \GlpiPlugin\Projectflow\Service\MetaService())->isProjectPaused($projectId)) {
        $input['_disablenotif'] = true;
    }
    plugin_projectflow_complete_task_dates($item);
    if (!$isAdd && !array_key_exists('percent_done', $input) && !array_key_exists('projectstates_id', $input) && !array_key_exists('auto_percent_done', $input)) {
        return;
    }
    $stateId = (int) ($input['projectstates_id'] ?? ($item->fields['projectstates_id'] ?? 0));
    $states = (new \GlpiPlugin\Projectflow\Service\ReferenceService())->getStateMap();
    if ($stateId > 0 && !empty($states[$stateId]['is_finished'])) {
        $percent = 100;
    } elseif ($stateId > 0) {
        $percent = (new \GlpiPlugin\Projectflow\Service\MetaService())->getStateProgress($stateId, (int) ($item->fields['percent_done'] ?? 0));
    } else {
        $percent = $isAdd ? 0 : (int) ($item->fields['percent_done'] ?? 0);
    }
    $input['percent_done'] = $percent;
    $input['auto_percent_done'] = 0;
}

/**
 * Project in a state flagged "pausa o projeto" (or being moved into one): GLPI's native
 * notifications for the project are not sent while it is paused.
 */
function plugin_projectflow_pre_project_save(CommonDBTM $item): void
{
    if (!$item instanceof Project || !is_array($item->input ?? null)) {
        return;
    }
    $stateId = (int) ($item->input['projectstates_id'] ?? ($item->fields['projectstates_id'] ?? 0));
    if ((new \GlpiPlugin\Projectflow\Service\MetaService())->isPausedState($stateId)) {
        $item->input['_disablenotif'] = true;
    }
}

/**
 * GLPI's planning (Assistência > Planejamento) only shows a project task to its executors when the
 * task has BOTH a planned start and a planned end. A task typed with only a deadline (or only a
 * start) is completed here: the missing date is derived from the planned duration (1h when none).
 */
function plugin_projectflow_complete_task_dates(ProjectTask $item): void
{
    $input = &$item->input;
    $isAdd = empty($item->fields['id']);
    if (!$isAdd && !array_key_exists('plan_start_date', $input) && !array_key_exists('plan_end_date', $input)) {
        return;
    }
    $start = array_key_exists('plan_start_date', $input) ? $input['plan_start_date'] : ($item->fields['plan_start_date'] ?? null);
    $end = array_key_exists('plan_end_date', $input) ? $input['plan_end_date'] : ($item->fields['plan_end_date'] ?? null);
    $start = ($start === null || trim((string) $start) === '' || $start === 'NULL') ? null : (string) $start;
    $end = ($end === null || trim((string) $end) === '' || $end === 'NULL') ? null : (string) $end;
    if (($start === null) === ($end === null)) {
        return; // both set or both empty: nothing to complete
    }
    $duration = (int) ($input['planned_duration'] ?? ($item->fields['planned_duration'] ?? 0));
    if ($duration <= 0) {
        $duration = HOUR_TIMESTAMP;
    }
    if ($start === null) {
        $ts = strtotime($end);
        if ($ts) $input['plan_start_date'] = date('Y-m-d H:i:s', $ts - $duration);
    } else {
        $ts = strtotime($start);
        if ($ts) $input['plan_end_date'] = date('Y-m-d H:i:s', $ts + $duration);
    }
}

/**
 * One-shot repair of tasks created before the rule above: tasks with a single planned date get the
 * other one, so they show up in the executors' GLPI planning.
 */
function plugin_projectflow_repair_task_planning_dates(): void
{
    global $DB;
    if (\GlpiPlugin\Projectflow\Config::bool('planning_dates_repaired', false)) {
        return;
    }
    try {
        $table = ProjectTask::getTable();
        $DB->update($table, [
            'plan_start_date' => new \Glpi\DBAL\QueryExpression('DATE_SUB(' . $DB::quoteName('plan_end_date') . ', INTERVAL GREATEST(' . $DB::quoteName('planned_duration') . ', 3600) SECOND)'),
        ], ['plan_start_date' => null, 'NOT' => ['plan_end_date' => null]]);
        $DB->update($table, [
            'plan_end_date' => new \Glpi\DBAL\QueryExpression('DATE_ADD(' . $DB::quoteName('plan_start_date') . ', INTERVAL GREATEST(' . $DB::quoteName('planned_duration') . ', 3600) SECOND)'),
        ], ['plan_end_date' => null, 'NOT' => ['plan_start_date' => null]]);
        \GlpiPlugin\Projectflow\Config::set('planning_dates_repaired', '1');
    } catch (\Throwable $e) {
        \Toolbox::logError('[Project Flow] planning dates repair: ' . $e->getMessage());
    }
}
