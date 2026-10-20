<?php

namespace GlpiPlugin\Projectflow;

use CommonDBTM;
use GlpiPlugin\Projectflow\Service\MetaService;
use Group;
use Project;
use ProjectTask;
use ProjectTaskTeam;
use ProjectTeam;
use Rule;
use Toolbox;
use User;

/**
 * Runs the Project Flow business rules on native project / project task saves.
 *
 * - pre_item_add / pre_item_update: rules are evaluated. Native fields (state, priority...) are
 *   written in the input, so GLPI saves them with the item.
 * - Plugin actions (team, Project Flow fields, template) need the item id and are applied after
 *   the save. When the save comes from a Project Flow service (`_pf_defer_rules`), the service
 *   saves its own data first and calls finalize(), so the rule result is the last word.
 */
final class RuleEngine
{
    private const PROJECT_CORE = ['projectstates_id', 'priority', 'users_id', 'groups_id', 'projecttypes_id'];
    private const TASK_CORE = ['projectstates_id', 'projecttasktypes_id', 'is_milestone'];

    /** @var array<string, array<int, array>> plugin actions waiting to be applied, per type and id */
    private static array $pending = ['project' => [], 'task' => []];
    /** @var array<string, bool> items being processed (no re-entrance) */
    private static array $running = [];
    /** @var array<string, bool> templates already applied in this request (project:template) */
    private static array $appliedTemplates = [];

    // ------------------------------------------------------------------ hooks

    public static function preSave(CommonDBTM $item): void
    {
        if (!is_array($item->input ?? null) || !empty($item->input['_pf_skip_rules'])) {
            return;
        }
        $type = $item instanceof Project ? 'project' : ($item instanceof ProjectTask ? 'task' : '');
        if ($type === '') {
            return;
        }
        $isAdd = empty($item->fields['id']);
        $key = $type . ':' . ($isAdd ? 'new' : (int) $item->fields['id']);
        if (!empty(self::$running[$key])) {
            return;
        }
        self::$running[$key] = true;
        try {
            self::evaluate($item, $type, $isAdd);
        } catch (\Throwable $e) {
            Toolbox::logError('[Project Flow] regras de negócio: ' . $e->getMessage());
        } finally {
            unset(self::$running[$key]);
        }
    }

    public static function postAdd(CommonDBTM $item): void
    {
        $type = $item instanceof Project ? 'project' : ($item instanceof ProjectTask ? 'task' : '');
        $id = (int) ($item->fields['id'] ?? 0);
        $output = $item->input['_pf_rule_output'] ?? null;
        if ($type === '' || $id <= 0 || !is_array($output) || $output === []) {
            return;
        }
        self::$pending[$type][$id] = $output;
        if (empty($item->input['_pf_defer_rules'])) {
            self::apply($type, $id, $output);
        }
    }

    /** Apply (again) the actions waiting for an item, then forget them. */
    public static function finalize(string $type, int $id): void
    {
        if (!isset(self::$pending[$type][$id])) {
            return;
        }
        $output = self::$pending[$type][$id];
        unset(self::$pending[$type][$id]);
        self::apply($type, $id, $output);
    }

    public static function finalizeAll(string $type): void
    {
        foreach (array_keys(self::$pending[$type] ?? []) as $id) {
            self::finalize($type, (int) $id);
        }
    }

    // ------------------------------------------------------------------ evaluation

    private static function evaluate(CommonDBTM $item, string $type, bool $isAdd): void
    {
        $collection = $type === 'project' ? new RuleProjectCollection() : new RuleProjectTaskCollection();
        $lookFor = $collection->getFieldsToLookFor();
        if ($lookFor === []) {
            return; // no active rule of this kind
        }
        $input = &$item->input;
        $data = $type === 'project' ? self::projectData($item, $input, $isAdd) : self::taskData($item, $input, $isAdd);
        if ($data === null) {
            return; // templates and their tasks are blueprints: no rule
        }
        if (!$isAdd) {
            // As in ticket rules: on update only when a field used by a rule changes.
            $before = $type === 'project' ? self::projectData($item, [], false) : self::taskData($item, [], false);
            $changed = false;
            foreach ($lookFor as $field) {
                if ((string) ($before[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                    $changed = true;
                    break;
                }
            }
            if (!$changed) {
                return;
            }
        }
        $collection->setEntity((int) ($data['_pf_entity'] ?? 0));
        $output = $collection->processAllRules($data, [], [], [
            'condition' => $isAdd ? RuleProject::ONADD : RuleProject::ONUPDATE,
        ]);
        unset($output['_no_rule_matches'], $output['_rule_process'], $output['_ruleid'], $output['_stop_rules_processing']);
        if ($output === []) {
            return;
        }

        $core = $type === 'project' ? self::PROJECT_CORE : self::TASK_CORE;
        foreach ($core as $field) {
            if (array_key_exists($field, $output)) {
                $input[$field] = (int) $output[$field];
                if ($type === 'task' && $field === 'projectstates_id') {
                    $input['auto_projectstates'] = 0;
                }
            }
        }
        $output['_pf_event'] = $isAdd ? 'add' : 'update';
        $output['_pf_source_template'] = (int) ($data['_pf_template'] ?? 0);

        if ($isAdd) {
            $input['_pf_rule_output'] = $output; // applied by postAdd() once the id exists
            return;
        }
        $id = (int) $item->fields['id'];
        if (!empty($input['_pf_defer_rules'])) {
            self::$pending[$type][$id] = $output;
        } else {
            self::apply($type, $id, $output);
        }
    }

    /** Values the project criteria are checked against (null for templates). */
    private static function projectData(CommonDBTM $item, array $input, bool $isAdd): ?array
    {
        $fields = $isAdd ? [] : (array) $item->fields;
        $value = static fn(string $k, mixed $d = '') => array_key_exists($k, $input) ? $input[$k] : ($fields[$k] ?? $d);
        if (!empty($value('is_template', 0))) {
            return null;
        }
        $meta = is_array($input['_pf_meta'] ?? null) ? $input['_pf_meta'] : [];
        $current = $isAdd ? [] : (new MetaService())->getForProject((int) $fields['id']);
        $m = static fn(string $k, string $d) => (string) ($meta[$k] ?? $current[$k] ?? $d);
        $entity = (int) $value('entities_id', $_SESSION['glpiactive_entity'] ?? 0);
        return [
            'name' => (string) $value('name'),
            'code' => (string) $value('code'),
            'content' => (string) $value('content'),
            'entities_id' => $entity,
            'projecttypes_id' => (int) $value('projecttypes_id', 0),
            'projectstates_id' => (int) $value('projectstates_id', 0),
            'priority' => (int) $value('priority', 3),
            'users_id' => (int) $value('users_id', 0),
            'groups_id' => (int) $value('groups_id', 0),
            '_pf_template' => (int) ($meta['template_id'] ?? 0),
            '_pf_portfolio' => $m('portfolio', ''),
            '_pf_risk_level' => $m('risk_level', 'normal'),
            '_pf_health' => $m('health', 'auto'),
            '_pf_execution_mode' => $m('execution_mode', (string) Config::get('default_execution_mode', 'direct')),
            '_pf_cost_mode' => $m('cost_mode', (string) Config::get('default_cost_mode', 'hours')),
            '_pf_entity' => $entity,
        ];
    }

    /** Values the task criteria are checked against (null for tasks of templates). */
    private static function taskData(CommonDBTM $item, array $input, bool $isAdd): ?array
    {
        $fields = $isAdd ? [] : (array) $item->fields;
        $value = static fn(string $k, mixed $d = '') => array_key_exists($k, $input) ? $input[$k] : ($fields[$k] ?? $d);
        $projectId = (int) $value('projects_id', 0);
        $project = new Project();
        if ($projectId <= 0 || !$project->getFromDB($projectId) || !empty($project->fields['is_template'])) {
            return null;
        }
        $meta = is_array($input['_pf_meta'] ?? null) ? $input['_pf_meta'] : [];
        $metas = new MetaService();
        $taskMeta = $isAdd ? [] : $metas->getTaskMeta((int) $fields['id']);
        $projectMeta = $metas->getForProject($projectId);
        $assignee = (int) ($meta['assignee'] ?? 0);
        if ($assignee <= 0 && !$isAdd) {
            $assignee = self::firstTaskUser((int) $fields['id']);
        }
        return [
            'name' => (string) $value('name'),
            'content' => (string) $value('content'),
            'projects_id' => $projectId,
            '_pf_projecttypes_id' => (int) ($project->fields['projecttypes_id'] ?? 0),
            '_pf_project_risk' => (string) ($projectMeta['risk_level'] ?? 'normal'),
            '_pf_project_portfolio' => (string) ($projectMeta['portfolio'] ?? ''),
            'projectstates_id' => (int) $value('projectstates_id', 0),
            'projecttasktypes_id' => (int) $value('projecttasktypes_id', 0),
            '_pf_priority' => (int) ($meta['priority'] ?? $taskMeta['priority'] ?? 3),
            'is_milestone' => empty($value('is_milestone', 0)) ? 0 : 1,
            '_pf_is_subtask' => ((int) $value('projecttasks_id', 0)) > 0 ? 1 : 0,
            '_pf_assignee' => $assignee,
            '_pf_entity' => (int) ($project->fields['entities_id'] ?? 0),
        ];
    }

    private static function firstTaskUser(int $taskId): int
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['items_id'],
            'FROM' => ProjectTaskTeam::getTable(),
            'WHERE' => ['projecttasks_id' => $taskId, 'itemtype' => User::class],
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ]) as $row) {
            return (int) $row['items_id'];
        }
        return 0;
    }

    // ------------------------------------------------------------------ actions

    private static function apply(string $type, int $id, array $output): void
    {
        try {
            $type === 'project' ? self::applyProject($id, $output) : self::applyTask($id, $output);
        } catch (\Throwable $e) {
            Toolbox::logError('[Project Flow] regras de negócio (ações): ' . $e->getMessage());
        }
    }

    private static function applyProject(int $id, array $output): void
    {
        $project = new Project();
        if (!$project->getFromDB($id)) {
            return;
        }
        $users = array_map('intval', (array) ($output['_pf_team_users'] ?? []));
        $groups = array_map('intval', (array) ($output['_pf_team_groups'] ?? []));
        // A manager / manager group set by a rule is also part of the team (as in Project Flow forms).
        if (!empty($output['users_id'])) $users[] = (int) $output['users_id'];
        if (!empty($output['groups_id'])) $groups[] = (int) $output['groups_id'];
        foreach (array_unique($users) as $uid) self::addProjectMember($id, User::class, $uid);
        foreach (array_unique($groups) as $gid) self::addProjectMember($id, Group::class, $gid);

        $map = [
            '_pf_portfolio' => 'portfolio', '_pf_sponsor' => 'sponsor', '_pf_risk_level' => 'risk_level',
            '_pf_health' => 'health', '_pf_execution_mode' => 'execution_mode', '_pf_cost_mode' => 'cost_mode',
        ];
        $meta = [];
        foreach ($map as $action => $field) {
            if (array_key_exists($action, $output)) $meta[$field] = (string) $output[$action];
        }
        if ($meta !== []) {
            (new MetaService())->save($id, $meta);
        }

        $template = (int) ($output['_pf_apply_template'] ?? 0);
        if ($template > 0 && ($output['_pf_event'] ?? '') === 'add'
            && $template !== (int) ($output['_pf_source_template'] ?? 0)
            && empty(self::$appliedTemplates[$id . ':' . $template])) {
            self::$appliedTemplates[$id . ':' . $template] = true;
            self::applyTemplate($project, $template);
        }
    }

    private static function applyTask(int $id, array $output): void
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($id)) {
            return;
        }
        $meta = [];
        if (array_key_exists('_pf_priority', $output)) $meta['priority'] = (int) $output['_pf_priority'];
        if (!empty($output['_pf_attention'])) $meta['attention'] = 1;
        if (trim((string) ($output['_pf_attention_note'] ?? '')) !== '') {
            $meta['attention'] = 1;
            $meta['attention_note'] = (string) $output['_pf_attention_note'];
        }
        if ($meta !== []) {
            (new MetaService())->saveTaskMeta($id, $meta);
        }
        $projectId = (int) ($task->fields['projects_id'] ?? 0);
        $members = [];
        foreach ((array) ($output['_pf_team_users'] ?? []) as $uid) $members[] = [User::class, (int) $uid];
        foreach ((array) ($output['_pf_team_groups'] ?? []) as $gid) $members[] = [Group::class, (int) $gid];
        foreach ($members as [$itemtype, $itemId]) {
            if ($itemId <= 0) continue;
            $where = ['projecttasks_id' => $id, 'itemtype' => $itemtype, 'items_id' => $itemId];
            if (!countElementsInTable(ProjectTaskTeam::getTable(), $where)) {
                (new ProjectTaskTeam())->add($where);
            }
            if ($projectId > 0 && Config::bool('auto_add_task_member_to_project_team', true)) {
                self::addProjectMember($projectId, $itemtype, $itemId);
            }
        }
    }

    private static function addProjectMember(int $projectId, string $itemtype, int $itemId): void
    {
        if ($itemId <= 0) return;
        $member = getItemForItemtype($itemtype);
        if (!$member || !$member->getFromDB($itemId)) return;
        $where = ['projects_id' => $projectId, 'itemtype' => $itemtype, 'items_id' => $itemId];
        if (!countElementsInTable(ProjectTeam::getTable(), $where)) {
            (new ProjectTeam())->add($where);
        }
    }

    /**
     * Copy the tasks of a template into the project: hierarchy, type, state, duration, milestone,
     * Project Flow data (priority, attention, allowed states) and task team. Planned dates are
     * shifted by the difference between the project start and the template start (when both exist).
     */
    private static function applyTemplate(Project $project, int $templateId): void
    {
        global $DB;
        $template = new Project();
        if (!$template->getFromDB($templateId) || empty($template->fields['is_template'])) {
            return;
        }
        $projectId = (int) $project->fields['id'];
        $shift = 0;
        $tplStart = strtotime((string) ($template->fields['plan_start_date'] ?? '')) ?: 0;
        $prjStart = strtotime((string) ($project->fields['plan_start_date'] ?? '')) ?: 0;
        if ($tplStart && $prjStart) $shift = $prjStart - $tplStart;
        $date = static function (mixed $value) use ($shift): ?string {
            $ts = strtotime((string) $value);
            return ($value && $ts) ? date('Y-m-d H:i:s', $ts + $shift) : null;
        };

        $rows = [];
        foreach ($DB->request([
            'FROM' => ProjectTask::getTable(),
            'WHERE' => ['projects_id' => $templateId, 'is_deleted' => 0],
            'ORDER' => ['id ASC'],
        ]) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        if ($rows === []) return;
        $metas = new MetaService();
        $sourceMetas = $metas->getTaskMetas(array_keys($rows));

        $map = [];
        $guard = 0;
        while ($rows !== [] && $guard++ < 50) {
            foreach ($rows as $sourceId => $row) {
                $parent = (int) $row['projecttasks_id'];
                if ($parent > 0 && isset($rows[$parent])) continue; // parent not copied yet
                unset($rows[$sourceId]);
                $newId = (new ProjectTask())->add([
                    'projects_id' => $projectId,
                    'projecttasks_id' => $parent > 0 ? (int) ($map[$parent] ?? 0) : 0,
                    'entities_id' => (int) $project->fields['entities_id'],
                    'is_recursive' => (int) ($project->fields['is_recursive'] ?? 0),
                    'name' => (string) $row['name'],
                    'content' => (string) ($row['content'] ?? ''),
                    'comment' => (string) ($row['comment'] ?? ''),
                    'projectstates_id' => (int) $row['projectstates_id'],
                    'projecttasktypes_id' => (int) $row['projecttasktypes_id'],
                    'planned_duration' => (int) $row['planned_duration'],
                    'plan_start_date' => $date($row['plan_start_date'] ?? null),
                    'plan_end_date' => $date($row['plan_end_date'] ?? null),
                    'is_milestone' => (int) $row['is_milestone'],
                    'auto_percent_done' => 0,
                    'auto_projectstates' => 0,
                ]);
                if (!$newId) continue;
                $map[$sourceId] = (int) $newId;
                if (isset($sourceMetas[$sourceId])) {
                    $m = $sourceMetas[$sourceId];
                    $metas->saveTaskMeta((int) $newId, [
                        'priority' => (int) ($m['priority'] ?? 3),
                        'attention' => (int) ($m['attention'] ?? 0),
                        'attention_note' => (string) ($m['attention_note'] ?? ''),
                        'allowed_states' => (string) ($m['allowed_states'] ?? ''),
                        '_current_state_id' => (int) $row['projectstates_id'],
                    ]);
                }
                foreach ($DB->request([
                    'SELECT' => ['itemtype', 'items_id'],
                    'FROM' => ProjectTaskTeam::getTable(),
                    'WHERE' => ['projecttasks_id' => $sourceId],
                ]) as $member) {
                    (new ProjectTaskTeam())->add(['projecttasks_id' => (int) $newId, 'itemtype' => $member['itemtype'], 'items_id' => (int) $member['items_id']]);
                }
                // rules may have stashed actions for the new task: apply them over the copied data
                self::finalize('task', (int) $newId);
            }
        }
    }

    /** Removes the rules of this plugin (uninstall). */
    public static function purgeRules(): void
    {
        $rule = new Rule();
        foreach ([RuleProject::class, RuleProjectTask::class] as $subType) {
            $rule->deleteByCriteria(['sub_type' => $subType], true);
        }
    }
}
