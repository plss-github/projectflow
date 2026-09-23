<?php

namespace GlpiPlugin\Projectflow\Service;

use CommonITILObject;
use Contact;
use Dropdown;
use GlpiPlugin\Projectflow\Config;
use Group;
use Itil_Project;
use Log;
use Project;
use ProjectTask;
use ProjectTaskLink;
use ProjectTaskTeam;
use ProjectTask_Ticket;
use ProjectTeam;
use Session;
use Supplier;
use Ticket;
use User;

class TaskService
{
    public function __construct(
        private readonly ReferenceService $references = new ReferenceService(),
        private readonly MetaService $meta = new MetaService(),
        private readonly DocumentService $documents = new DocumentService(),
        private readonly ActivityService $activities = new ActivityService(),
        private readonly WorklogService $worklogs = new WorklogService(),
        private readonly AssetService $assets = new AssetService(),
    ) {}

    public function getProjectTasks(int $projectId): array
    {
        global $DB;
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem()) return [];

        $rows = [];
        foreach ($DB->request([
            'FROM' => ProjectTask::getTable(),
            'WHERE' => ['projects_id' => $projectId, 'is_deleted' => 0, 'is_template' => 0],
            'ORDERBY' => ['plan_start_date ASC', 'id ASC'],
        ]) as $row) {
            $task = new ProjectTask();
            $task->getFromResultSet($row);
            if ($task->canViewItem()) $rows[] = $row;
        }

        $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $teamSummary = $this->getTeamSummaries($ids);
        $metas = $this->meta->getTaskMetas($ids);
        $states = $this->references->getStateMap();
        $types = [];
        foreach ($this->references->getTaskTypes() as $type) $types[$type['id']] = $type['name'];

        $tasks = [];
        foreach ($rows as $row) {
            $task = new ProjectTask();
            $task->getFromResultSet($row);
            $id = (int) $row['id'];
            $normalized = $this->normalizeTask($row, $states, $task->canUpdateItem(), $types, $metas[$id] ?? $this->meta->getTaskMetaDefaults());
            $normalized['team_summary'] = $teamSummary[$id] ?? [];
            $tasks[] = $normalized;
        }
        return $this->decorateHierarchy($tasks);
    }

    public function getTask(int $taskId): ?array
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canViewItem()) return null;

        $states = $this->references->getStateMap();
        $types = [];
        foreach ($this->references->getTaskTypes() as $type) $types[$type['id']] = $type['name'];

        $data = $this->normalizeTask(
            $task->fields,
            $states,
            $task->canUpdateItem(),
            $types,
            $this->meta->getTaskMeta($taskId)
        );
        $data['content_raw'] = trim(strip_tags((string) ($task->fields['content'] ?? '')));
        $data['native_url'] = ProjectTask::getFormURLWithID($taskId);
        $data['team'] = $this->getTaskTeam($taskId);
        $data['dependencies'] = $this->getDependencies($taskId);
        $data['tickets'] = $this->getTickets($taskId);
        $data['documents'] = $this->documents->getForItem(ProjectTask::class, $taskId);
        $data['activities'] = $this->activities->getForTask($taskId);
        $data['worklogs'] = $this->worklogs->getForTask($taskId);
        $data['assets'] = $this->assets->getForTask($taskId);
        $data['history'] = $this->getHistory($task);
        $projectMeta = $this->meta->getForProject((int) $task->fields['projects_id']);
        $data['execution_mode'] = (string) ($projectMeta['execution_mode'] ?? 'direct');
        $data['cost_mode'] = (string) ($projectMeta['cost_mode'] ?? 'hours');
        $data['project_url'] = PLUGIN_PROJECTFLOW_WEBDIR . '/front/project.php?id=' . (int) $task->fields['projects_id'];
        $data['task_url'] = PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . $taskId;
        $data['can_create_ticket'] = Session::haveRight(Ticket::$rightname, CREATE);
        return $data;
    }

    public function create(int $projectId, array $input): int|false
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE) || !ProjectTask::canCreate()) return false;

        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 255);
        if ($name === '') return false;
        $projectMeta = $this->meta->getForProject($projectId);
        // Tasks stored in a template are only a blueprint: tickets are opened for real projects.
        $ticketMode = (($projectMeta['execution_mode'] ?? 'direct') === 'ticket') && empty($project->fields['is_template']);
        if ($ticketMode && !Session::haveRight(Ticket::$rightname, CREATE)) return false;
        $stateId = (int) ($input['projectstates_id'] ?? Config::int('default_task_state_id', 0));
        if ($stateId <= 0) $stateId = $this->firstOpenStateId();
        if (!$this->validState($stateId)) return false;
        $start = $this->date($input['plan_start_date'] ?? null);
        $end = $this->date($input['plan_end_date'] ?? null);
        if (!$this->validRange($start, $end)) return false;
        $parent = (int) ($input['projecttasks_id'] ?? 0);
        if ($parent > 0 && !$this->isTaskInProject($parent, $projectId)) return false;
        $milestone = !empty($input['is_milestone']);
        if ($milestone && $start) $end = $start;
        if (!$this->validRequester($input)) return false;

        $percent = max(0, min(100, (int) ($input['percent_done'] ?? 0)));
        if (Config::bool('auto_progress_on_kanban', true) && $stateId > 0) {
            $percent = $this->progressForState($stateId, $percent);
        }

        $payload = [
            'projects_id' => $projectId,
            'projecttasks_id' => $parent,
            'name' => $name,
            'projectstates_id' => $stateId,
            'projecttasktypes_id' => max(0, (int) ($input['projecttasktypes_id'] ?? 0)),
            'percent_done' => $percent,
            'auto_percent_done' => !empty($input['auto_percent_done']) ? 1 : 0,
            'auto_projectstates' => 0,
            'is_milestone' => $milestone ? 1 : 0,
            'plan_start_date' => $start,
            'plan_end_date' => $end,
            'planned_duration' => $this->plannedDuration($input),
            'content' => mb_substr(trim(strip_tags((string) ($input['content'] ?? ''))), 0, 10000),
        ];

        $id = (new ProjectTask())->add($payload);
        if (!$id) return false;
        $taskMetaInput = $input;
        if (!array_key_exists('requester_users_id', $taskMetaInput) || (int) $taskMetaInput['requester_users_id'] <= 0) {
            $taskMetaInput['requester_users_id'] = (int) Session::getLoginUserID();
        }
        $this->meta->saveTaskMeta((int) $id, $taskMetaInput);

        $assignee = (int) ($input['assignee_user_id'] ?? Session::getLoginUserID());
        if ($assignee > 0) $this->addTaskMember((int) $id, User::class, $assignee);

        if ($ticketMode) {
            $ticketId = $this->createTicket((int) $id, [
                'name' => $name, 'content' => $payload['content'], 'priority' => $input['priority'] ?? 3,
                'type' => $input['ticket_type'] ?? Ticket::DEMAND_TYPE, 'itilcategories_id' => $input['itilcategories_id'] ?? 0,
            ]);
            if (!$ticketId) {
                $created = new ProjectTask();
                if ($created->getFromDB((int) $id) && $created->canUpdateItem()) {
                    $created->delete(['id' => (int) $id], 1);
                }
                return false;
            }
        }
        return (int) $id;
    }

    public function update(int $taskId, array $input): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;

        $payload = ['id' => $taskId];
        if (array_key_exists('name', $input)) {
            $name = mb_substr(trim(strip_tags((string) $input['name'])), 0, 255);
            if ($name === '') return false;
            $payload['name'] = $name;
        }
        if (array_key_exists('content', $input)) $payload['content'] = mb_substr(trim(strip_tags((string) $input['content'])), 0, 10000);
        if (array_key_exists('projectstates_id', $input)) {
            $stateId = (int) $input['projectstates_id'];
            if (!$this->validState($stateId)) return false;
            $payload['projectstates_id'] = $stateId;
            $payload['auto_projectstates'] = 0;
            if (Config::bool('auto_progress_on_kanban', true) && !array_key_exists('percent_done', $input)) {
                $payload['percent_done'] = $this->progressForState($stateId, (int) ($task->fields['percent_done'] ?? 0));
            }
        }
        if (array_key_exists('projecttasktypes_id', $input)) $payload['projecttasktypes_id'] = max(0, (int) $input['projecttasktypes_id']);
        if (array_key_exists('percent_done', $input)) $payload['percent_done'] = max(0, min(100, (int) $input['percent_done']));
        if (array_key_exists('auto_percent_done', $input)) $payload['auto_percent_done'] = !empty($input['auto_percent_done']) ? 1 : 0;
        if (array_key_exists('is_milestone', $input)) $payload['is_milestone'] = !empty($input['is_milestone']) ? 1 : 0;
        if (array_key_exists('projecttasks_id', $input)) {
            $parent = (int) $input['projecttasks_id'];
            if ($parent > 0 && !$this->isTaskInProject($parent, (int) $task->fields['projects_id'])) return false;
            if ($parent === $taskId) return false;
            $payload['projecttasks_id'] = $parent;
        }
        if (array_key_exists('plan_start_date', $input)) $payload['plan_start_date'] = $this->date($input['plan_start_date']);
        if (array_key_exists('plan_end_date', $input)) $payload['plan_end_date'] = $this->date($input['plan_end_date']);
        if (array_key_exists('planned_duration_hours', $input) || array_key_exists('planned_duration', $input)) $payload['planned_duration'] = $this->plannedDuration($input);
        if (!$this->validRequester($input)) return false;

        $start = $payload['plan_start_date'] ?? ($task->fields['plan_start_date'] ?? null);
        $end = $payload['plan_end_date'] ?? ($task->fields['plan_end_date'] ?? null);
        if (($payload['is_milestone'] ?? $task->fields['is_milestone'] ?? 0) && $start) {
            $payload['plan_end_date'] = $start;
            $end = $start;
        }
        if (!$this->validRange($start, $end)) return false;

        $ok = (bool) $task->update($payload);
        if ($ok && array_intersect(['requester_users_id','priority','attention','attention_note','reminder_at','reminder_email','reminder_browser'], array_keys($input))) {
            $this->meta->saveTaskMeta($taskId, $input);
        }
        return $ok;
    }

    public function move(int $taskId, int $stateId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem() || !$this->validState($stateId)) return false;
        $payload = ['id' => $taskId, 'projectstates_id' => $stateId, 'auto_projectstates' => 0];
        if (Config::bool('auto_progress_on_kanban', true)) {
            $payload['percent_done'] = $this->progressForState($stateId, (int) ($task->fields['percent_done'] ?? 0));
        }
        return (bool) $task->update($payload);
    }

    public function bulk(int $projectId, array $taskIds, string $operation, int $stateId = 0): int
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return 0;
        $finishedStateId = 0;
        if ($operation === 'complete') {
            foreach ($this->references->getProjectStates() as $state) {
                if (!empty($state['is_finished'])) { $finishedStateId = (int) $state['id']; break; }
            }
        }
        $count = 0;
        foreach (array_values(array_unique(array_map('intval', $taskIds))) as $id) {
            if (!$this->isTaskInProject($id, $projectId)) continue;
            if ($operation === 'complete') {
                $ok = $finishedStateId > 0 ? $this->move($id, $finishedStateId) : $this->update($id, ['percent_done' => 100]);
                if ($ok) $count++;
            } elseif ($operation === 'state' && $this->move($id, $stateId)) {
                $count++;
            }
        }
        return $count;
    }

    public function addTaskMember(int $taskId, string $type, int $itemId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        if (!in_array($type, [User::class, Group::class, Supplier::class, Contact::class], true) || $itemId <= 0) return false;
        $member = getItemForItemtype($type);
        if (!$member || !$member->getFromDB($itemId) || !$member->canViewItem()) return false;

        $where = ['projecttasks_id' => $taskId, 'itemtype' => $type, 'items_id' => $itemId];
        $ok = countElementsInTable(ProjectTaskTeam::getTable(), $where) > 0 || (bool) (new ProjectTaskTeam())->add($where);
        if (!$ok) return false;

        // A task assignee is automatically promoted to the project team. This removes duplicate management steps.
        if (Config::bool('auto_add_task_member_to_project_team', true)) {
            $projectId = (int) ($task->fields['projects_id'] ?? 0);
            if ($projectId > 0 && countElementsInTable(ProjectTeam::getTable(), ['projects_id' => $projectId, 'itemtype' => $type, 'items_id' => $itemId]) === 0) {
                (new ProjectTeam())->add(['projects_id' => $projectId, 'itemtype' => $type, 'items_id' => $itemId]);
            }
        }
        return true;
    }

    public function removeTaskMember(int $taskId, int $relationId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        $relation = new ProjectTaskTeam();
        if (!$relation->getFromDB($relationId) || (int) $relation->fields['projecttasks_id'] !== $taskId) return false;
        // Deliberately keep the user in the project team: they may participate in other tasks.
        return (bool) $relation->delete(['id' => $relationId]);
    }

    public function addDependency(int $taskId, int $targetId, int $type): bool
    {
        $source = new ProjectTask();
        $target = new ProjectTask();
        if (!$source->getFromDB($taskId) || !$target->getFromDB($targetId) || !$source->canUpdateItem() || !$target->canViewItem()) return false;
        if ($taskId === $targetId || (int) $source->fields['projects_id'] !== (int) $target->fields['projects_id'] || $type < 0 || $type > 3) return false;
        $data = ['projecttasks_id_source' => $taskId, 'projecttasks_id_target' => $targetId, 'type' => $type];
        $link = new ProjectTaskLink();
        if ($link->checkIfExist($data)) return true;
        if ($this->wouldCreateDependencyCycle((int) $source->fields['projects_id'], $taskId, $targetId)) return false;
        return (bool) $link->add($data);
    }

    public function removeDependency(int $taskId, int $linkId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        $link = new ProjectTaskLink();
        if (!$link->getFromDB($linkId)) return false;
        if ((int) $link->fields['projecttasks_id_source'] !== $taskId && (int) $link->fields['projecttasks_id_target'] !== $taskId) return false;
        return (bool) $link->delete(['id' => $linkId]);
    }

    public function createTicket(int $taskId, array $input): int|false
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem() || !Session::haveRight(Ticket::$rightname, CREATE)) return false;
        $project = new Project();
        if (!$project->getFromDB((int) $task->fields['projects_id']) || !$project->canViewItem()) return false;

        $name = trim(strip_tags((string) ($input['name'] ?? $task->fields['name'] ?? '')));
        if ($name === '') $name = 'Chamado da tarefa #' . $taskId;
        $content = trim(strip_tags((string) ($input['content'] ?? $task->fields['content'] ?? '')));
        if ($content === '') $content = 'Chamado criado a partir da tarefa de projeto #' . $taskId . '.';
        $type = (int) ($input['type'] ?? Ticket::DEMAND_TYPE);
        if (!in_array($type, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)) $type = Ticket::DEMAND_TYPE;
        $priority = max(1, min(6, (int) ($input['priority'] ?? $this->meta->getTaskPriority($taskId))));
        $categoryId = max(0, (int) ($input['itilcategories_id'] ?? 0));
        $entityId = (int) ($task->fields['entities_id'] ?? $project->fields['entities_id'] ?? Session::getActiveEntity());

        $ticketInput = [
            'entities_id' => $entityId,
            'name' => mb_substr($name, 0, 255),
            'content' => mb_substr($content, 0, 10000),
            'type' => $type,
            'priority' => $priority,
            'itilcategories_id' => $categoryId,
            '_users_id_requester' => (int) Session::getLoginUserID(),
            '_projecttasks_id' => $taskId,
            '_projects_id' => [(int) $project->fields['id']],
        ];
        $ticket = new Ticket();
        $id = $ticket->add($ticketInput);
        if (!$id) return false;

        // Keep the task and project views synchronized even if the native ticket form hook
        // does not materialize the project relation in a particular GLPI configuration.
        $projectLink = [
            'projects_id' => (int) $project->fields['id'],
            'itemtype' => Ticket::class,
            'items_id' => (int) $id,
        ];
        if (countElementsInTable(Itil_Project::getTable(), $projectLink) === 0) {
            (new Itil_Project())->add($projectLink);
        }
        $taskLink = ['projecttasks_id' => $taskId, 'tickets_id' => (int) $id];
        if (countElementsInTable(ProjectTask_Ticket::getTable(), $taskLink) === 0) {
            (new ProjectTask_Ticket())->add($taskLink);
        }
        return (int) $id;
    }

    public function linkTicket(int $taskId, int $ticketId): bool
    {
        $task = new ProjectTask();
        $ticket = new Ticket();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem() || !$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) return false;
        $data = ['projecttasks_id' => $taskId, 'tickets_id' => $ticketId];
        $linked = countElementsInTable(ProjectTask_Ticket::getTable(), $data) > 0 || (bool) (new ProjectTask_Ticket())->add($data);
        if (!$linked) return false;

        $projectId = (int) ($task->fields['projects_id'] ?? 0);
        if ($projectId > 0) {
            $projectLink = ['projects_id' => $projectId, 'itemtype' => Ticket::class, 'items_id' => $ticketId];
            if (countElementsInTable(Itil_Project::getTable(), $projectLink) === 0) {
                (new Itil_Project())->add($projectLink);
            }
        }
        return true;
    }

    public function unlinkTicket(int $taskId, int $relationId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem()) return false;
        $relation = new ProjectTask_Ticket();
        if (!$relation->getFromDB($relationId) || (int) $relation->fields['projecttasks_id'] !== $taskId) return false;
        return (bool) $relation->delete(['id' => $relationId]);
    }

    public function getTaskCenterTasks(bool $mineOnly = true, bool $includeFinished = false, int $limit = 1500): array
    {
        global $DB;
        if (!ProjectTask::canView()) return [];
        $limit = max(1, min($limit, 5000));

        $mine = [];
        $uid = (int) Session::getLoginUserID();
        if ($uid > 0) {
            foreach (ProjectTask::getActiveProjectTaskIDsForUser([$uid]) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) $mine[$id] = true;
            }
        }
        if ($mineOnly && $mine === []) return [];

        // Resolve project visibility without the portfolio display cap. In "mine" scope only
        // the projects that actually own the user's tasks are loaded, so the queue never loses
        // tasks because a project is older than the N most recently modified projects.
        $where = ['is_deleted' => 0, 'is_template' => 0];
        $projectService = new ProjectService();
        if ($mineOnly) {
            $where['id'] = array_keys($mine);
            $projectIds = [];
            foreach ($DB->request(['SELECT' => ['projects_id'], 'DISTINCT' => true, 'FROM' => ProjectTask::getTable(), 'WHERE' => $where]) as $row) {
                $projectIds[] = (int) $row['projects_id'];
            }
            $projectMap = $projectService->getAccessibleProjectIndex($projectIds);
        } else {
            $projectMap = $projectService->getAccessibleProjectIndex(null);
        }
        if ($projectMap === []) return [];
        $where['projects_id'] = array_keys($projectMap);

        $states = $this->references->getStateMap();
        $defaultStateId = Config::int('default_task_state_id', $this->firstOpenStateId());

        // Filters are applied while reading and the limit is enforced on the filtered result,
        // never on the raw SQL result set.
        $rows = [];
        $offset = 0;
        $chunk = 500;
        do {
            $batch = 0;
            foreach ($DB->request([
                'FROM' => ProjectTask::getTable(),
                'WHERE' => $where,
                'ORDERBY' => ['plan_end_date ASC', 'id DESC'],
                'START' => $offset,
                'LIMIT' => $chunk,
            ]) as $row) {
                $batch++;
                $stateId = (int) ($row['projectstates_id'] ?? 0);
                if ($stateId <= 0) $stateId = $defaultStateId;
                if (!$includeFinished && !empty($states[$stateId]['is_finished'])) continue;
                $task = new ProjectTask();
                $task->getFromResultSet($row);
                if (!$task->canViewItem()) continue;
                $rows[] = $row;
                if (count($rows) >= $limit) break 2;
            }
            $offset += $chunk;
        } while ($batch === $chunk);

        $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $metas = $this->meta->getTaskMetas($ids);
        $teams = $this->getTeamSummaries($ids);
        $types = [];
        foreach ($this->references->getTaskTypes() as $type) $types[$type['id']] = $type['name'];

        $tasks = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $projectId = (int) $row['projects_id'];
            $project = $projectMap[$projectId] ?? null;
            if (!$project) continue;
            $taskObj = new ProjectTask();
            $taskObj->getFromResultSet($row);
            $normalized = $this->normalizeTask($row, $states, $taskObj->canUpdateItem(), $types, $metas[$id] ?? $this->meta->getTaskMetaDefaults());
            $normalized['team_summary'] = $teams[$id] ?? [];
            $normalized['is_mine'] = isset($mine[$id]);
            $normalized['project_name'] = (string) ($project['name'] ?? ('Projeto #' . $projectId));
            $normalized['project_code'] = (string) ($project['code'] ?? '');
            $normalized['project_url'] = PLUGIN_PROJECTFLOW_WEBDIR . '/front/project.php?id=' . $projectId;
            $normalized['task_url'] = PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . $id;
            $normalized['execution_mode'] = (string) ($project['execution_mode'] ?? 'direct');
            $tasks[] = $normalized;
        }

        usort($tasks, static function(array $a, array $b): int {
            if ($a['is_overdue'] !== $b['is_overdue']) return $a['is_overdue'] ? -1 : 1;
            if ($a['attention'] !== $b['attention']) return $a['attention'] ? -1 : 1;
            $ad = $a['plan_end_ts'] ?? PHP_INT_MAX;
            $bd = $b['plan_end_ts'] ?? PHP_INT_MAX;
            return ($ad <=> $bd) ?: ($a['id'] <=> $b['id']);
        });
        return $tasks;
    }

    public function getDirectSubtasks(int $taskId): array
    {
        $parent = new ProjectTask();
        if (!$parent->getFromDB($taskId) || !$parent->canViewItem()) return [];
        $items = [];
        foreach ($this->getProjectTasks((int) $parent->fields['projects_id']) as $task) {
            if ((int) ($task['parent_id'] ?? 0) === $taskId) $items[] = $task;
        }
        return $items;
    }

    public function getBoard(array $tasks, bool $showFinished = true): array
    {
        $progressMap = $this->meta->getStateProgressMap();
        $columns = [];
        foreach ($this->references->getProjectStates() as $state) {
            if (!$showFinished && $state['is_finished']) continue;
            $state['progress'] = $progressMap[$state['id']] ?? ($state['is_finished'] ? 100 : 0);
            $state['tasks'] = [];
            $columns[$state['id']] = $state;
        }
        foreach ($tasks as $task) {
            $stateId = (int) $task['state']['id'];
            if ($stateId <= 0) $stateId = Config::int('default_task_state_id', $this->firstOpenStateId());
            if (!isset($columns[$stateId])) {
                if (!$showFinished && $task['state']['is_finished']) continue;
                $columns[$stateId] = $task['state'] + ['progress' => $task['percent_done'], 'tasks' => []];
            }
            $columns[$stateId]['tasks'][] = $task;
        }
        return array_values($columns);
    }

    public function buildTimeline(array $tasks, array $project): array
    {
        $dated = array_values(array_filter($tasks, static fn(array $task): bool => !empty($task['plan_start_ts']) && !empty($task['plan_end_ts'])));
        if (!$dated) return ['has_dates' => false, 'items' => [], 'start_label' => '', 'end_label' => '', 'today_pct' => null];
        $starts = array_column($dated, 'plan_start_ts');
        $ends = array_column($dated, 'plan_end_ts');
        $windowStart = $project['plan_start_ts'] ?: min($starts);
        $windowEnd = $project['plan_end_ts'] ?: max($ends);
        $windowStart = min($windowStart, min($starts));
        $windowEnd = max($windowEnd, max($ends));
        if ($windowEnd <= $windowStart) $windowEnd = $windowStart + DAY_TIMESTAMP;
        $span = $windowEnd - $windowStart;
        $items = [];
        foreach ($dated as $task) {
            $start = (($task['plan_start_ts'] - $windowStart) / $span) * 100;
            $width = (($task['plan_end_ts'] - $task['plan_start_ts']) / $span) * 100;
            $items[] = $task + [
                'timeline_start_pct' => max(0, min(100, $start)),
                'timeline_width_pct' => max($task['is_milestone'] ? 0.8 : 1.5, min(100 - $start, $width)),
            ];
        }
        $today = time();
        $todayPct = $today < $windowStart ? 0 : ($today > $windowEnd ? 100 : (($today - $windowStart) / $span) * 100);
        return ['has_dates' => true, 'items' => $items, 'start_label' => date('d/m/Y', $windowStart), 'end_label' => date('d/m/Y', $windowEnd), 'today_pct' => round($todayPct, 2)];
    }

    private function getTickets(int $taskId): array
    {
        global $DB;
        $items = [];
        foreach ($DB->request([
            'FROM' => ProjectTask_Ticket::getTable(),
            'WHERE' => ['projecttasks_id' => $taskId],
            'ORDERBY' => ['id DESC'],
        ]) as $relation) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $relation['tickets_id']) || !$ticket->canViewItem()) continue;
            $status = (int) ($ticket->fields['status'] ?? 0);
            $priority = (int) ($ticket->fields['priority'] ?? 3);
            $items[] = [
                'relation_id' => (int) $relation['id'],
                'id' => (int) $ticket->fields['id'],
                'name' => (string) ($ticket->fields['name'] ?? ('Chamado #' . $ticket->fields['id'])),
                'type' => (int) ($ticket->fields['type'] ?? Ticket::DEMAND_TYPE),
                'type_name' => Ticket::getTicketTypeName((int) ($ticket->fields['type'] ?? Ticket::DEMAND_TYPE)),
                'status' => $status,
                'status_name' => Ticket::getStatus($status),
                'priority' => $priority,
                'priority_name' => CommonITILObject::getPriorityName($priority),
                'date' => $ticket->fields['date'] ?? null,
                'time_to_resolve' => $ticket->fields['time_to_resolve'] ?? null,
                'url' => Ticket::getFormURLWithID((int) $ticket->fields['id']),
                'is_closed' => in_array($status, array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()), true),
            ];
        }
        return $items;
    }

    private function getDependencies(int $taskId): array
    {
        global $DB;
        $labels = [0 => 'Fim → início', 1 => 'Início → início', 2 => 'Fim → fim', 3 => 'Início → fim'];
        $items = [];
        foreach ($DB->request(['FROM' => ProjectTaskLink::getTable(), 'WHERE' => ['OR' => [['projecttasks_id_source' => $taskId], ['projecttasks_id_target' => $taskId]]]]) as $row) {
            $outgoing = (int) $row['projecttasks_id_source'] === $taskId;
            $other = $outgoing ? (int) $row['projecttasks_id_target'] : (int) $row['projecttasks_id_source'];
            $task = new ProjectTask();
            $name = 'Tarefa #' . $other;
            if ($task->getFromDB($other) && $task->canViewItem()) {
                $name = (string) $task->fields['name'];
            }
            $items[] = ['id' => (int) $row['id'], 'direction' => $outgoing ? 'outgoing' : 'incoming', 'other_id' => $other, 'other_name' => $name, 'type' => (int) $row['type'], 'type_label' => $labels[(int) $row['type']] ?? 'Dependência'];
        }
        return $items;
    }

    private function getHistory(ProjectTask $task, int $limit = 100): array
    {
        if (!Log::canView()) return ['can_view' => false, 'items' => [], 'total' => null];
        $total = countElementsInTable(Log::getTable(), [
            'itemtype' => ProjectTask::class,
            'items_id' => (int) $task->getID(),
        ]);
        $items = [];
        foreach (Log::getHistoryData($task, 0, max(1, min($limit, 200))) as $entry) {
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

    private function wouldCreateDependencyCycle(int $projectId, int $source, int $target): bool
    {
        global $DB;
        $taskIds = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => ProjectTask::getTable(), 'WHERE' => ['projects_id' => $projectId, 'is_deleted' => 0]]) as $row) $taskIds[(int) $row['id']] = true;
        if (!isset($taskIds[$source], $taskIds[$target])) return true;

        $adjacency = [];
        $ids = array_keys($taskIds);
        foreach ($DB->request(['SELECT' => ['projecttasks_id_source', 'projecttasks_id_target'], 'FROM' => ProjectTaskLink::getTable(), 'WHERE' => ['projecttasks_id_source' => $ids]]) as $row) {
            $from = (int) $row['projecttasks_id_source'];
            $to = (int) $row['projecttasks_id_target'];
            if (isset($taskIds[$from], $taskIds[$to])) $adjacency[$from][] = $to;
        }
        $adjacency[$source][] = $target;
        $stack = [$target];
        $seen = [];
        while ($stack) {
            $node = (int) array_pop($stack);
            if ($node === $source) return true;
            if (isset($seen[$node])) continue;
            $seen[$node] = true;
            foreach ($adjacency[$node] ?? [] as $next) $stack[] = $next;
        }
        return false;
    }

    private function getTaskTeam(int $taskId): array
    {
        $team = ProjectTaskTeam::getTeamFor($taskId, true);
        $items = [];
        $labels = [User::class => 'Usuário', Group::class => 'Grupo', Supplier::class => 'Fornecedor', Contact::class => 'Contato'];
        foreach ($team as $type => $members) {
            foreach ($members as $member) {
                $items[] = [
                    'relation_id' => (int) $member['id'],
                    'type' => $type,
                    'type_label' => $labels[$type] ?? $type,
                    'id' => (int) $member['items_id'],
                    'name' => $member['display_name'] ?? $member['name'] ?? ('#' . $member['items_id']),
                ];
            }
        }
        return $items;
    }

    private function getTeamSummaries(array $ids): array
    {
        global $DB;
        if (!$ids) return [];
        $out = [];
        $cache = [];
        foreach ($DB->request(['FROM' => ProjectTaskTeam::getTable(), 'WHERE' => ['projecttasks_id' => $ids], 'ORDERBY' => ['id ASC']]) as $row) {
            $taskId = (int) $row['projecttasks_id'];
            if (count($out[$taskId] ?? []) >= 3) continue;
            $type = $row['itemtype'];
            $id = (int) $row['items_id'];
            $key = $type . ':' . $id;
            if (isset($cache[$key])) $name = $cache[$key];
            elseif ($type === User::class) $name = self::userName($id);
            elseif ($type === Group::class) $name = Dropdown::getDropdownName(Group::getTable(), $id);
            elseif ($type === Supplier::class) $name = Dropdown::getDropdownName(Supplier::getTable(), $id);
            elseif ($type === Contact::class) { $item = new Contact(); $name = $item->getFromDB($id) ? $item->getName() : ('Contato #' . $id); }
            else continue;
            $cache[$key] = $name;
            $out[$taskId][] = ['type' => $type, 'id' => $id, 'name' => $name];
        }
        return $out;
    }

    private function decorateHierarchy(array $tasks): array
    {
        $byId = [];
        $children = [];
        foreach ($tasks as $task) $byId[(int) $task['id']] = $task;
        foreach ($tasks as $task) {
            $id = (int) $task['id'];
            $parent = (int) ($task['parent_id'] ?? 0);
            if ($parent <= 0 || !isset($byId[$parent]) || $parent === $id) $parent = 0;
            $children[$parent][] = $id;
        }
        $ordered = [];
        $visited = [];
        $walk = function (int $id, int $depth) use (&$walk, &$ordered, &$visited, $byId, $children): void {
            if (isset($visited[$id]) || !isset($byId[$id])) return;
            $visited[$id] = true;
            $task = $byId[$id];
            $task['depth'] = min(8, max(0, $depth));
            $parent = (int) ($task['parent_id'] ?? 0);
            $task['parent_name'] = $parent > 0 && isset($byId[$parent]) ? (string) $byId[$parent]['name'] : '';
            $ordered[] = $task;
            foreach ($children[$id] ?? [] as $childId) $walk((int) $childId, $depth + 1);
        };
        foreach ($children[0] ?? [] as $rootId) $walk((int) $rootId, 0);
        foreach (array_keys($byId) as $id) $walk((int) $id, 0);
        return $ordered;
    }

    private function normalizeTask(array $row, array $states, bool $canUpdate, array $types, array $meta): array
    {
        $stateId = (int) ($row['projectstates_id'] ?? 0);
        if ($stateId <= 0) $stateId = Config::int('default_task_state_id', $this->firstOpenStateId());
        $state = $states[$stateId] ?? ['id' => $stateId, 'name' => 'Estado inicial', 'color' => '#64748b', 'is_finished' => false];
        $start = $row['plan_start_date'] ?? null;
        $end = $row['plan_end_date'] ?? null;
        $percent = max(0, min(100, (int) ($row['percent_done'] ?? 0)));
        $isOverdue = $end && strtotime($end) < time() && !$state['is_finished'] && $percent < 100;
        $typeId = (int) ($row['projecttasktypes_id'] ?? 0);
        $priority = max(1, min(6, (int) ($meta['priority'] ?? 3)));
        $requesterId = max(0, (int) ($meta['requester_users_id'] ?? 0));
        if ($requesterId <= 0) $requesterId = max(0, (int) ($row['users_id'] ?? 0));
        return [
            'id' => (int) $row['id'],
            'projects_id' => (int) $row['projects_id'],
            'parent_id' => (int) ($row['projecttasks_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'content' => trim(strip_tags((string) ($row['content'] ?? ''))),
            'percent_done' => $percent,
            'auto_percent_done' => (bool) ($row['auto_percent_done'] ?? false),
            'state' => $state,
            'priority' => $priority,
            'priority_name' => CommonITILObject::getPriorityName($priority),
            'requester_user_id' => $requesterId,
            'requester_name' => $requesterId > 0 ? self::userName($requesterId) : 'Não definido',
            'attention' => !empty($meta['attention']),
            'attention_note' => (string) ($meta['attention_note'] ?? ''),
            'reminder_at' => $meta['reminder_at'] ?? null,
            'reminder_email' => !empty($meta['reminder_email']),
            'reminder_browser' => !empty($meta['reminder_browser']),
            'reminder_due' => !empty($meta['reminder_browser']) && !empty($meta['reminder_at']) && strtotime((string) $meta['reminder_at']) <= time(),
            'projecttasktypes_id' => $typeId,
            'type_name' => $types[$typeId] ?? '',
            'plan_start_date' => $start,
            'plan_end_date' => $end,
            'plan_start_ts' => $start ? strtotime($start) : null,
            'plan_end_ts' => $end ? strtotime($end) : null,
            'real_start_date' => $row['real_start_date'] ?? null,
            'real_end_date' => $row['real_end_date'] ?? null,
            'planned_duration' => (int) ($row['planned_duration'] ?? 0),
            'planned_duration_hours' => round(((int) ($row['planned_duration'] ?? 0)) / 3600, 2),
            'effective_duration' => (int) ($row['effective_duration'] ?? 0),
            'is_milestone' => (bool) ($row['is_milestone'] ?? false),
            'is_overdue' => (bool) $isOverdue,
            'can_update' => $canUpdate,
            'creator_name' => !empty($row['users_id']) ? self::userName((int) $row['users_id']) : '',
            'date_mod' => $row['date_mod'] ?? null,
            'task_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . (int) $row['id'],
        ];
    }

    /** @var array<int,string> */
    private static array $userNames = [];

    private static function userName(int $id): string
    {
        return self::$userNames[$id] ??= (string) getUserName($id);
    }

    private function progressForState(int $stateId, int $fallback): int
    {
        if ($stateId <= 0) return 0;
        $state = $this->references->getStateMap()[$stateId] ?? null;
        if ($state && !empty($state['is_finished'])) return 100;
        return $this->meta->getStateProgress($stateId, $fallback);
    }

    private function plannedDuration(array $input): int
    {
        if (array_key_exists('planned_duration_hours', $input)) {
            $hours = max(0.0, (float) str_replace(',', '.', (string) $input['planned_duration_hours']));
            return (int) round($hours * 3600);
        }
        return max(0, (int) ($input['planned_duration'] ?? 0));
    }

    /** Same requester rule for create and update: an explicit requester must be a visible user. */
    private function validRequester(array $input): bool
    {
        if (!array_key_exists('requester_users_id', $input)) return true;
        $requesterId = max(0, (int) $input['requester_users_id']);
        if ($requesterId === 0 || $requesterId === (int) Session::getLoginUserID()) return true;
        $requester = new User();
        return $requester->getFromDB($requesterId) && $requester->canViewItem();
    }

    private function isTaskInProject(int $taskId, int $projectId): bool
    {
        $task = new ProjectTask();
        return $task->getFromDB($taskId) && (int) $task->fields['projects_id'] === $projectId;
    }

    private function validState(int $id): bool
    {
        return $id > 0 && isset($this->references->getStateMap()[$id]);
    }

    private function firstOpenStateId(): int
    {
        foreach ($this->references->getProjectStates() as $state) if (empty($state['is_finished'])) return (int) $state['id'];
        foreach ($this->references->getProjectStates() as $state) return (int) $state['id'];
        return 0;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function validRange(?string $start, ?string $end): bool
    {
        return !$start || !$end || strtotime($end) >= strtotime($start);
    }
}
