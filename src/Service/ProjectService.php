<?php

namespace GlpiPlugin\Projectflow\Service;

use Change;
use CommonITILObject;
use Contact;
use Document;
use Document_Item;
use Dropdown;
use Group;
use GlpiPlugin\Projectflow\Config;
use Itil_Project;
use Html;
use Log;
use Problem;
use Project;
use ProjectCost;
use ProjectTask;
use ProjectTeam;
use Session;
use Supplier;
use Ticket;
use User;

class ProjectService
{
    public function __construct(
        private readonly ReferenceService $references = new ReferenceService(),
        private readonly MetaService $meta = new MetaService(),
        private readonly DocumentService $documents = new DocumentService(),
        private readonly WorklogService $worklogs = new WorklogService(),
        private readonly MeetingService $meetings = new MeetingService(),
        private readonly ContractService $contracts = new ContractService(),
    ) {}

    public function getAccessibleProjects(int $limit = 250): array
    {
        global $DB;
        if (!Project::canView()) return [];

        $table = Project::getTable();
        $visibility = Project::getVisibilityCriteria();
        $criteria = [
            'SELECT' => ["$table.*"],
            'DISTINCT' => true,
            'FROM' => $table,
            'WHERE' => array_merge(["$table.is_deleted" => 0, "$table.is_template" => 0], getEntitiesRestrictCriteria($table, '', '', true)),
            'ORDERBY' => ["$table.date_mod DESC"],
            'LIMIT' => max(1, min($limit, 1000)),
        ];
        if (!empty($visibility['LEFT JOIN'])) $criteria['LEFT JOIN'] = $visibility['LEFT JOIN'];
        if (!empty($visibility['WHERE'])) $criteria['WHERE'] = array_merge($criteria['WHERE'], $visibility['WHERE']);

        $raw = [];
        foreach ($DB->request($criteria) as $row) {
            $project = new Project();
            $project->getFromResultSet($row);
            if ($project->canViewItem()) $raw[] = $row;
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $raw);
        $states = $this->references->getStateMap();
        $metas = $this->meta->getForProjects($ids);
        $favorites = $this->meta->getFavoriteIds();
        $taskStats = $this->getTaskStatsForProjects($ids);

        $projects = [];
        foreach ($raw as $row) {
            $id = (int) $row['id'];
            $projects[] = $this->normalizeProject($row, $states, $metas[$id] ?? [], isset($favorites[$id]), $taskStats[$id] ?? []);
        }
        return $projects;
    }

    public function getProject(int $id): ?array
    {
        $project = new Project();
        if (!$project->getFromDB($id) || !$project->canViewItem()) return null;
        $states = $this->references->getStateMap();
        $taskStats = $this->getTaskStatsForProjects([$id])[$id] ?? [];
        $data = $this->normalizeProject($project->fields, $states, $this->meta->getForProject($id), isset($this->meta->getFavoriteIds()[$id]), $taskStats);
        $data['can_update'] = $project->can($id, UPDATE);
        $data['native_url'] = Project::getFormURLWithID($id);
        $data['team'] = $this->getTeam($id);
        $data['itil_items'] = $this->getItilItems($id);
        $data['hours'] = $this->worklogs->getSummary($id);
        $budgetMinutes = max(0, (int) ($data['hours_budget_minutes'] ?? 0));
        $usedMinutes = max(0, (int) ($data['hours']['total_minutes'] ?? 0));
        $remainingMinutes = max(0, $budgetMinutes - $usedMinutes);
        $data['hours_budget'] = [
            'minutes' => $budgetMinutes,
            'label' => WorklogService::formatMinutes($budgetMinutes),
            'remaining_minutes' => $remainingMinutes,
            'remaining_label' => WorklogService::formatMinutes($remainingMinutes),
            'consumed_percent' => $budgetMinutes > 0 ? min(999, (int) round(($usedMinutes / $budgetMinutes) * 100)) : null,
            'overrun_minutes' => max(0, $usedMinutes - $budgetMinutes),
            'overrun_label' => WorklogService::formatMinutes(max(0, $usedMinutes - $budgetMinutes)),
        ];
        $data['worklogs'] = $this->worklogs->getForProject($id, null, null, 100);
        $data['meetings'] = $this->meetings->getForProject($id);
        $data['contracts'] = $this->contracts->getForProject($id);
        $data['can_view_money'] = $this->canViewMoney($project);
        $data['costs'] = (($data['cost_mode'] ?? 'hours') === 'money' && $data['can_view_money']) ? $this->getCosts($id) : ['items'=>[], 'total'=>0.0, 'total_formatted'=>Html::formatNumber(0)];
        $data['documents'] = $this->getDocuments($id);
        $data['can_add_cost'] = $data['can_update'] && ($data['cost_mode'] ?? 'hours') === 'money' && $data['can_view_money'];
        $data['can_add_document'] = $data['can_update'] && Document::canCreate();
        $data['history'] = $this->getHistory($project);
        $data['counts'] = $this->getRelatedCounts($id) + ['meetings'=>count($data['meetings'])];
        return $data;
    }

    public function create(array $input): int|false
    {
        if (!Session::haveRight(Project::$rightname, CREATE)) return false;
        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 255);
        if ($name === '') return false;

        $entityId = (int) ($input['entities_id'] ?? Session::getActiveEntity());
        if (!Session::haveAccessToEntity($entityId, true)) return false;
        $stateId = (int) ($input['projectstates_id'] ?? Config::int('default_project_state_id', 0));
        if ($stateId <= 0) $stateId = $this->firstOpenStateId();
        if (!$this->validState($stateId)) return false;
        $start = $this->date($input['plan_start_date'] ?? null);
        $end = $this->date($input['plan_end_date'] ?? null);
        if (!$this->validRange($start, $end)) return false;

        $override = [
            'name' => $name,
            'code' => mb_substr(trim(strip_tags((string) ($input['code'] ?? ''))), 0, 255),
            'entities_id' => $entityId,
            'users_id' => (int) ($input['users_id'] ?? Session::getLoginUserID()),
            'groups_id' => (int) ($input['groups_id'] ?? 0),
            'projectstates_id' => $stateId,
            'projecttypes_id' => (int) ($input['projecttypes_id'] ?? 0),
            'priority' => $this->priority($input['priority'] ?? 3),
            'plan_start_date' => $start,
            'plan_end_date' => $end,
            'content' => mb_substr(trim(strip_tags((string) ($input['content'] ?? ''))), 0, 10000),
            'is_recursive' => !empty($input['is_recursive']) ? 1 : 0,
            'auto_percent_done' => !empty($input['auto_percent_done']) ? 1 : 0,
        ];
        $stateMap = $this->references->getStateMap();
        $override['percent_done'] = ($stateId && ($stateMap[$stateId]['is_finished'] ?? false)) ? 100 : 0;

        $templateId = (int) ($input['template_id'] ?? 0);
        if ($templateId > 0) {
            $template = new Project();
            if (!$template->getFromDB($templateId) || empty($template->fields['is_template']) || !$template->canViewItem()) return false;
            $projectId = $template->clone($override, true, false);
        } else {
            $projectId = (new Project())->add($override);
        }

        if (!$projectId) return false;
        $projectId = (int) $projectId;
        $this->ensureMember($projectId, User::class, (int) Session::getLoginUserID());
        if ((int) ($override['users_id'] ?? 0) > 0) {
            $this->ensureMember($projectId, User::class, (int) $override['users_id']);
        }
        if ((int) ($override['groups_id'] ?? 0) > 0) {
            $this->ensureMember($projectId, Group::class, (int) $override['groups_id']);
        }
        $this->meta->save($projectId, [
            'execution_mode' => $input['execution_mode'] ?? Config::get('default_execution_mode','direct'),
            'cost_mode' => $input['cost_mode'] ?? Config::get('default_cost_mode','hours'),
        ] + $input);
        $this->repairClonedTaskStates($projectId);
        return (int) $projectId;
    }

    public function update(int $projectId, array $input): bool
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return false;
        $payload = ['id' => $projectId];

        if (array_key_exists('name', $input)) {
            $name = mb_substr(trim(strip_tags((string) $input['name'])), 0, 255);
            if ($name === '') return false;
            $payload['name'] = $name;
        }
        if (array_key_exists('code', $input)) $payload['code'] = mb_substr(trim(strip_tags((string) $input['code'])), 0, 255);
        if (array_key_exists('content', $input)) $payload['content'] = mb_substr(trim(strip_tags((string) $input['content'])), 0, 10000);
        if (array_key_exists('comment', $input)) $payload['comment'] = mb_substr(trim(strip_tags((string) $input['comment'])), 0, 10000);
        if (array_key_exists('priority', $input)) $payload['priority'] = $this->priority($input['priority']);
        foreach (['users_id','groups_id','projecttypes_id'] as $field) if (array_key_exists($field, $input)) $payload[$field] = max(0, (int) $input[$field]);
        if (array_key_exists('projectstates_id', $input)) {
            $stateId = (int) $input['projectstates_id'];
            if (!$this->validState($stateId)) return false;
            $payload['projectstates_id'] = $stateId;
        }
        if (array_key_exists('percent_done', $input)) $payload['percent_done'] = max(0, min(100, (int) $input['percent_done']));
        if (array_key_exists('auto_percent_done', $input)) $payload['auto_percent_done'] = !empty($input['auto_percent_done']) ? 1 : 0;
        if (array_key_exists('plan_start_date', $input)) $payload['plan_start_date'] = $this->date($input['plan_start_date']);
        if (array_key_exists('plan_end_date', $input)) $payload['plan_end_date'] = $this->date($input['plan_end_date']);
        $start = $payload['plan_start_date'] ?? ($project->fields['plan_start_date'] ?? null);
        $end = $payload['plan_end_date'] ?? ($project->fields['plan_end_date'] ?? null);
        if (!$this->validRange($start, $end)) return false;

        $ok = (bool) $project->update($payload);
        if ($ok) {
            $this->meta->save($projectId, $input);
            $managerId = (int) ($payload['users_id'] ?? ($project->fields['users_id'] ?? 0));
            $groupId = (int) ($payload['groups_id'] ?? ($project->fields['groups_id'] ?? 0));
            if ($managerId > 0) {
                $this->ensureMember($projectId, User::class, $managerId);
            }
            if ($groupId > 0) {
                $this->ensureMember($projectId, Group::class, $groupId);
            }
        }
        return $ok;
    }

    public function toggleFavorite(int $projectId): ?bool
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem()) return null;
        return $this->meta->toggleFavorite($projectId);
    }

    public function addTeamMember(int $projectId, string $type, int $itemId): bool
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return false;
        if (!in_array($type, [User::class, Group::class, Supplier::class, Contact::class], true) || $itemId <= 0) return false;
        $member = getItemForItemtype($type);
        if (!$member || !$member->getFromDB($itemId) || !$member->canViewItem()) return false;
        return $this->ensureMember($projectId, $type, $itemId);
    }

    public function removeTeamMember(int $projectId, int $relationId): bool
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return false;
        $relation = new ProjectTeam();
        if (!$relation->getFromDB($relationId) || (int) $relation->fields['projects_id'] !== $projectId) return false;
        return (bool) $relation->delete(['id' => $relationId]);
    }

    public function getDashboardStats(array $projects): array
    {
        $stats = ['total' => count($projects), 'active' => 0, 'overdue' => 0, 'attention' => 0, 'completed' => 0, 'favorites' => 0, 'my_tasks' => 0];
        foreach ($projects as $p) {
            if ($p['is_finished']) $stats['completed']++; else $stats['active']++;
            if ($p['is_overdue']) $stats['overdue']++;
            if (in_array($p['health_effective'], ['attention','critical'], true)) $stats['attention']++;
            if ($p['is_favorite']) $stats['favorites']++;
        }
        if (ProjectTask::canView()) $stats['my_tasks'] = count(ProjectTask::getActiveProjectTaskIDsForUser([(int) Session::getLoginUserID()]));
        return $stats;
    }

    public function getTeam(int $projectId): array
    {
        $team = ProjectTeam::getTeamFor($projectId, true);
        $items = [];
        $labels = [User::class => 'Usuário', Group::class => 'Grupo', Supplier::class => 'Fornecedor', Contact::class => 'Contato'];
        foreach ($team as $type => $members) foreach ($members as $member) $items[] = [
            'relation_id' => (int) $member['id'], 'type' => $type, 'type_label' => $labels[$type] ?? $type,
            'id' => (int) $member['items_id'], 'name' => $member['display_name'] ?? $member['name'] ?? ('#' . $member['items_id']),
        ];
        return $items;
    }

    public function getItilItems(int $projectId): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request(['FROM' => Itil_Project::getTable(), 'WHERE' => ['projects_id' => $projectId], 'ORDERBY' => ['id DESC']]) as $relation) {
            $type = $relation['itemtype'];
            if (!in_array($type, [Ticket::class, Change::class, Problem::class], true) || !class_exists($type)) continue;
            $item = new $type();
            if (!$item->getFromDB((int) $relation['items_id']) || !$item->canViewItem()) continue;
            $status = (int) ($item->fields['status'] ?? 0);
            $result[] = ['type' => $type, 'type_label' => $type::getTypeName(1), 'id' => (int) $item->fields['id'], 'name' => $item->fields['name'] ?? ('#'.$item->fields['id']), 'status' => method_exists($type, 'getStatusName') ? $type::getStatusName($status) : (string) $status, 'url' => $type::getFormURLWithID((int) $item->fields['id'])];
        }
        return $result;
    }

    public function getCosts(int $projectId): array
    {
        global $DB;
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem() || !$this->canViewMoney($project) || (($this->meta->getForProject($projectId)['cost_mode'] ?? 'hours') !== 'money')) {
            return ['items' => [], 'total' => 0.0, 'total_formatted' => Html::formatNumber(0)];
        }

        $items = [];
        $total = 0.0;
        foreach ($DB->request([
            'FROM' => ProjectCost::getTable(),
            'WHERE' => ['projects_id' => $projectId],
            'ORDERBY' => ['begin_date DESC', 'id DESC'],
        ]) as $row) {
            $cost = (float) ($row['cost'] ?? 0);
            $total += $cost;
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) (($row['name'] ?? '') ?: ('Custo #' . $row['id'])),
                'begin_date' => $row['begin_date'] ?? null,
                'end_date' => $row['end_date'] ?? null,
                'cost' => $cost,
                'cost_formatted' => Html::formatNumber($cost),
                'budgets_id' => (int) ($row['budgets_id'] ?? 0),
                'budget' => !empty($row['budgets_id']) ? Dropdown::getDropdownName('glpi_budgets', (int) $row['budgets_id']) : '',
                'comment' => trim((string) ($row['comment'] ?? '')),
            ];
        }
        return ['items' => $items, 'total' => $total, 'total_formatted' => Html::formatNumber($total)];
    }

    public function addCost(int $projectId, array $input): int|false
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE) || !$this->canViewMoney($project) || (($this->meta->getForProject($projectId)['cost_mode'] ?? 'hours') !== 'money')) {
            return false;
        }

        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 255);
        if ($name === '') {
            $name = 'Custo do projeto';
        }
        $begin = $this->dateOnly($input['begin_date'] ?? null);
        $end = $this->dateOnly($input['end_date'] ?? null);
        if (!$this->validRange($begin, $end)) {
            return false;
        }

        $cost = new ProjectCost();
        $id = $cost->add([
            'projects_id' => $projectId,
            'name' => $name,
            'begin_date' => $begin,
            'end_date' => $end,
            'cost' => $this->money($input['cost'] ?? 0),
            'budgets_id' => max(0, (int) ($input['budgets_id'] ?? 0)),
            'comment' => mb_substr(trim(strip_tags((string) ($input['comment'] ?? ''))), 0, 10000),
        ]);
        return $id ? (int) $id : false;
    }

    public function removeCost(int $projectId, int $costId): bool
    {
        $project = new Project();
        if (!$project->getFromDB($projectId)
            || !$project->can($projectId, UPDATE)
            || !$this->canViewMoney($project)
            || (($this->meta->getForProject($projectId)['cost_mode'] ?? 'hours') !== 'money')) {
            return false;
        }
        $cost = new ProjectCost();
        if (!$cost->getFromDB($costId) || (int) ($cost->fields['projects_id'] ?? 0) !== $projectId) {
            return false;
        }
        return (bool) $cost->delete(['id' => $costId]);
    }

    public function getDocuments(int $projectId): array
    {
        return $this->documents->getForItem(Project::class, $projectId);
    }

    public function getHistory(Project $project, int $limit = 50): array
    {
        if (!Log::canView()) {
            return ['can_view' => false, 'items' => [], 'total' => null];
        }

        $total = countElementsInTable(Log::getTable(), [
            'itemtype' => Project::class,
            'items_id' => (int) $project->getID(),
        ]);
        $items = [];
        foreach (Log::getHistoryData($project, 0, max(1, min($limit, 200))) as $entry) {
            if (empty($entry['display_history'])) continue;
            $change = html_entity_decode(trim(strip_tags((string) ($entry['change'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $field = html_entity_decode(trim(strip_tags((string) ($entry['field'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $items[] = [
                'id' => (int) ($entry['id'] ?? 0),
                'date_mod' => $entry['date_mod'] ?? null,
                'user_name' => trim((string) ($entry['user_name'] ?? '')) ?: 'Sistema',
                'field' => $field,
                'change' => $change !== '' ? $change : 'Alteração registrada',
            ];
        }
        return ['can_view' => true, 'items' => $items, 'total' => $total];
    }

    public function getRelatedCounts(int $projectId): array
    {
        $project = new Project();
        $canViewProject = $project->getFromDB($projectId) && $project->canViewItem();
        $canViewMoney = $canViewProject && $this->canViewMoney($project);
        $visibleTaskCount = $canViewProject ? (int) (($this->getTaskStatsForProjects([$projectId])[$projectId]['total'] ?? 0)) : 0;
        return [
            'tasks' => $visibleTaskCount,
            'itil' => countElementsInTable(Itil_Project::getTable(), ['projects_id' => $projectId]),
            'documents' => countElementsInTable(Document_Item::getTable(), ['itemtype' => Project::class, 'items_id' => $projectId]),
            'costs' => $canViewMoney ? countElementsInTable(ProjectCost::getTable(), ['projects_id' => $projectId]) : null,
            'history' => Log::canView() ? countElementsInTable(Log::getTable(), ['itemtype' => Project::class, 'items_id' => $projectId]) : null,
        ];
    }

    private function getTaskStatsForProjects(array $projectIds): array
    {
        global $DB;
        if (!$projectIds) return [];
        $stats = [];
        foreach ($projectIds as $id) {
            $stats[(int) $id] = ['total' => 0, 'completed' => 0, 'overdue' => 0, 'milestones' => 0, 'attention' => 0, 'avg_progress' => 0, '_sum' => 0];
        }
        $stateMap = $this->references->getStateMap();
        $rows = [];
        $taskIds = [];
        foreach ($DB->request([
            'FROM' => ProjectTask::getTable(),
            'WHERE' => ['projects_id' => $projectIds, 'is_deleted' => 0, 'is_template' => 0],
        ]) as $row) {
            $task = new ProjectTask();
            $task->getFromResultSet($row);
            if (!$task->canViewItem()) {
                continue;
            }
            $rows[] = $row;
            $taskIds[] = (int) $row['id'];
        }
        $metas = $this->meta->getTaskMetas($taskIds);
        foreach ($rows as $row) {
            $projectId = (int) $row['projects_id'];
            if (!isset($stats[$projectId])) continue;
            $percent = (int) $row['percent_done'];
            $stateId = (int) ($row['projectstates_id'] ?? 0);
            $isCompleted = $percent >= 100 || !empty($stateMap[$stateId]['is_finished']);
            $stats[$projectId]['total']++;
            $stats[$projectId]['_sum'] += $percent;
            if ($isCompleted) $stats[$projectId]['completed']++;
            if (!empty($row['is_milestone'])) $stats[$projectId]['milestones']++;
            if (!empty($metas[(int) $row['id']]['attention'])) $stats[$projectId]['attention']++;
            if (!empty($row['plan_end_date']) && strtotime($row['plan_end_date']) < time() && !$isCompleted) $stats[$projectId]['overdue']++;
        }
        foreach ($stats as &$item) {
            $item['avg_progress'] = $item['total'] ? (int) round($item['_sum'] / $item['total']) : 0;
            unset($item['_sum']);
        }
        unset($item);
        return $stats;
    }

    private function normalizeProject(array $row, array $states, array $meta, bool $favorite, array $taskStats): array
    {
        $stateId = (int) ($row['projectstates_id'] ?? 0);
        $effectiveStateId = $stateId > 0 ? $stateId : Config::int('default_project_state_id', $this->firstOpenStateId());
        $state = $states[$effectiveStateId] ?? ['id'=>$effectiveStateId,'name'=>'Estado inicial','color'=>'#64748b','is_finished'=>false];
        $managerId = (int) ($row['users_id'] ?? 0); $groupId = (int) ($row['groups_id'] ?? 0); $entityId = (int) ($row['entities_id'] ?? 0);
        $start = $row['plan_start_date'] ?? null; $end = $row['plan_end_date'] ?? null; $percent = max(0, min(100, (int) ($row['percent_done'] ?? 0)));
        $isFinished = (bool) $state['is_finished'] || $percent >= 100;
        $isOverdue = !$isFinished && $end && strtotime($end) < time();
        $healthConfigured = (string) ($meta['health'] ?? 'auto');
        $health = $healthConfigured;
        if ($health === 'auto') {
            $days = \GlpiPlugin\Projectflow\Config::int('health_due_soon_days', 7);
            if ($isOverdue || (($taskStats['overdue'] ?? 0) >= 3)) $health = 'critical';
            elseif (!$isFinished && (($taskStats['overdue'] ?? 0) > 0 || ($taskStats['attention'] ?? 0) > 0)) $health = 'attention';
            elseif (!$isFinished && $end && strtotime($end) <= strtotime('+' . max(1,$days) . ' days') && $percent < 80) $health = 'attention';
            else $health = 'good';
        }
        return [
            'id'=>(int)$row['id'],'name'=>(string)($row['name']??''),'code'=>(string)($row['code']??''),'content'=>trim(strip_tags((string)($row['content']??''))),'comment'=>trim(strip_tags((string)($row['comment']??''))),
            'priority'=>(int)($row['priority']??3),'priority_name'=>CommonITILObject::getPriorityName((int)($row['priority']??3)),'percent_done'=>$percent,'auto_percent_done'=>(bool)($row['auto_percent_done']??false),
            'state'=>$state,'is_finished'=>$isFinished,'manager_id'=>$managerId,'manager_name'=>$managerId?getUserName($managerId):'Não definido','group_id'=>$groupId,'group_name'=>$groupId?Dropdown::getDropdownName('glpi_groups',$groupId):'',
            'projecttypes_id'=>(int)($row['projecttypes_id']??0),'project_type_name'=>!empty($row['projecttypes_id'])?Dropdown::getDropdownName('glpi_projecttypes',(int)$row['projecttypes_id']):'',
            'entity_id'=>$entityId,'entity_name'=>$entityId?Dropdown::getDropdownName('glpi_entities',$entityId):'Entidade raiz','is_recursive'=>(bool)($row['is_recursive']??false),
            'plan_start_date'=>$start,'plan_end_date'=>$end,'plan_start_ts'=>$start?strtotime($start):null,'plan_end_ts'=>$end?strtotime($end):null,'real_start_date'=>$row['real_start_date']??null,'real_end_date'=>$row['real_end_date']??null,
            'is_overdue'=>$isOverdue,'date_mod'=>$row['date_mod']??null,'date_creation'=>$row['date_creation']??null,'plugin_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/project.php?id='.(int)$row['id'],
            'is_favorite'=>$favorite,'health_configured'=>$healthConfigured,'health_effective'=>$health,'risk_level'=>(string)($meta['risk_level']??'normal'),'portfolio'=>(string)($meta['portfolio']??''),'sponsor'=>(string)($meta['sponsor']??''),'objective'=>(string)($meta['objective']??''),'execution_mode'=>(string)($meta['execution_mode']??'direct'),'cost_mode'=>(string)($meta['cost_mode']??'hours'),'hours_budget_minutes'=>max(0,(int)($meta['hours_budget_minutes']??0)),'task_stats'=>$taskStats + ['total'=>0,'completed'=>0,'overdue'=>0,'milestones'=>0,'attention'=>0,'avg_progress'=>0],
        ];
    }

    private function ensureMember(int $projectId, string $type, int $itemId): bool
    {
        if ($itemId <= 0 || countElementsInTable(ProjectTeam::getTable(), ['projects_id'=>$projectId,'itemtype'=>$type,'items_id'=>$itemId])) return true;
        return (bool) (new ProjectTeam())->add(['projects_id'=>$projectId,'itemtype'=>$type,'items_id'=>$itemId]);
    }
    private function validState(int $id): bool { return $id > 0 && isset($this->references->getStateMap()[$id]); }
    private function firstOpenStateId(): int { foreach($this->references->getProjectStates() as $state) if(empty($state['is_finished'])) return (int)$state['id']; foreach($this->references->getProjectStates() as $state) return (int)$state['id']; return 0; }
    private function repairClonedTaskStates(int $projectId): void { $stateId=Config::int('default_task_state_id',$this->firstOpenStateId()); if($stateId<=0)return; global $DB; foreach($DB->request(['SELECT'=>['id','projectstates_id'],'FROM'=>ProjectTask::getTable(),'WHERE'=>['projects_id'=>$projectId,'is_deleted'=>0]]) as $row){ if((int)($row['projectstates_id']??0)>0)continue; $task=new ProjectTask(); if($task->getFromDB((int)$row['id'])&&$task->canUpdateItem())$task->update(['id'=>(int)$row['id'],'projectstates_id'=>$stateId,'auto_projectstates'=>0]); } }
    private function canViewMoney(Project $project): bool { $uid=(int)Session::getLoginUserID(); if($uid>0 && (int)($project->fields['users_id']??0)===$uid)return true; if(Session::haveRight('config',UPDATE))return true; if(method_exists($project,'isInTheManagerGroup') && $project->isInTheManagerGroup())return true; return false; }
    private function priority(mixed $v): int { return max(1, min(6, (int) $v)); }
    private function money(mixed $value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') return 0.0;
        $raw = preg_replace('/[^0-9,.-]/', '', $raw) ?? '0';
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        return max(0.0, round((float) $raw, 4));
    }
    private function date(mixed $v): ?string { $v=trim((string)$v); if($v==='') return null; $ts=strtotime($v); return $ts?date('Y-m-d H:i:s',$ts):null; }
    private function dateOnly(mixed $v): ?string { $v=trim((string)$v); if($v==='') return null; $ts=strtotime($v); return $ts?date('Y-m-d',$ts):null; }
    private function validRange(?string $s, ?string $e): bool { return !$s || !$e || strtotime($e) >= strtotime($s); }
}
