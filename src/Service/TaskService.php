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
        $data['can_manage_states'] = $this->canManageAllowedStates($taskId);
        return $data;
    }

    /**
     * Who may define the states a task is allowed to use: whoever can update the project, except
     * the task's executor (a user in the task team, directly or through a group). The project
     * manager is the only exception, even when executing the task.
     */
    public function canManageAllowedStates(int $taskId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId)) return false;
        $projectId = (int) $task->fields['projects_id'];
        if (!$this->canManageStatesInProject($projectId)) return false;
        if ($this->isProjectManager($projectId)) return true;
        return !$this->isTaskExecutor($taskId);
    }

    /** Same rule when creating a task: the future executor cannot restrict its own task. */
    public function canManageAllowedStatesOnCreate(int $projectId, int $assigneeId): bool
    {
        if (!$this->canManageStatesInProject($projectId)) return false;
        if ($this->isProjectManager($projectId)) return true;
        return $assigneeId !== (int) Session::getLoginUserID();
    }

    private function canManageStatesInProject(int $projectId): bool
    {
        $project = new Project();
        return $projectId > 0 && $project->getFromDB($projectId) && $project->can($projectId, UPDATE);
    }

    private function isProjectManager(int $projectId): bool
    {
        $project = new Project();
        $uid = (int) Session::getLoginUserID();
        return $uid > 0 && $project->getFromDB($projectId) && (int) ($project->fields['users_id'] ?? 0) === $uid;
    }

    private function isTaskExecutor(int $taskId): bool
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) return false;
        if (countElementsInTable(ProjectTaskTeam::getTable(), ['projecttasks_id' => $taskId, 'itemtype' => User::class, 'items_id' => $uid]) > 0) return true;
        $groups = array_values(array_filter(array_map('intval', (array) ($_SESSION['glpigroups'] ?? []))));
        return $groups && countElementsInTable(ProjectTaskTeam::getTable(), ['projecttasks_id' => $taskId, 'itemtype' => Group::class, 'items_id' => $groups]) > 0;
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
        // States this task may use (per task); empty = every state. The executor never defines them.
        $allowed = MetaService::parseStateIds($input['allowed_states'] ?? '');
        if ($allowed && !$this->canManageAllowedStatesOnCreate($projectId, (int) ($input['assignee_user_id'] ?? Session::getLoginUserID()))) return false;
        $explicitState = (int) ($input['projectstates_id'] ?? 0);
        $stateId = $explicitState > 0 ? $explicitState : $this->defaultTaskStateId($allowed);
        if (!$this->validState($stateId)) return false;
        if ($allowed && !in_array($stateId, $allowed, true)) {
            if ($explicitState > 0) return false;
            $stateId = $this->defaultTaskStateId($allowed);
        }
        $start = $this->date($input['plan_start_date'] ?? null);
        $end = $this->date($input['plan_end_date'] ?? null);
        if (!$this->validRange($start, $end)) return false;
        $parent = (int) ($input['projecttasks_id'] ?? 0);
        if ($parent > 0 && !$this->isTaskInProject($parent, $projectId)) return false;
        $milestone = !empty($input['is_milestone']);
        if ($milestone && $start) $end = $start;
        if (!$this->validRequester($input)) return false;

        // Progress is never typed: it always follows the state (percent per state).
        $percent = $this->progressForState($stateId, 0);

        $payload = [
            'projects_id' => $projectId,
            'projecttasks_id' => $parent,
            'name' => $name,
            'projectstates_id' => $stateId,
            'projecttasktypes_id' => max(0, (int) ($input['projecttasktypes_id'] ?? 0)),
            'percent_done' => $percent,
            'auto_percent_done' => 0,
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
        $taskMetaInput['_current_state_id'] = $stateId;
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

        // Allowed states are defined by the project side, never by the executor of the task.
        if (array_key_exists('allowed_states', $input)
            && MetaService::normalizeStateIdsForCompare($input['allowed_states']) !== MetaService::normalizeStateIdsForCompare($this->meta->getAllowedStateIds($taskId))
            && !$this->canManageAllowedStates($taskId)) {
            return false;
        }
        if (array_key_exists('allowed_states', $input) && !$this->canManageAllowedStates($taskId)) unset($input['allowed_states']);

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
            $allowed = array_key_exists('allowed_states', $input) ? MetaService::parseStateIds($input['allowed_states']) : $this->meta->getAllowedStateIds($taskId);
            if ($stateId !== (int) ($task->fields['projectstates_id'] ?? 0) && $allowed && !in_array($stateId, $allowed, true)) return false;
            $payload['projectstates_id'] = $stateId;
            $payload['auto_projectstates'] = 0;
            $payload['percent_done'] = $this->progressForState($stateId, (int) ($task->fields['percent_done'] ?? 0));
        }
        if (array_key_exists('projecttasktypes_id', $input)) $payload['projecttasktypes_id'] = max(0, (int) $input['projecttasktypes_id']);
        // percent_done / auto_percent_done from the request are ignored: progress follows the state.
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
        if ($ok && array_intersect(['requester_users_id','priority','attention','attention_note','reminder_at','reminder_email','reminder_browser','allowed_states'], array_keys($input))) {
            // The state the task is in stays allowed, so the restriction never strands a task.
            $input['_current_state_id'] = (int) ($payload['projectstates_id'] ?? $task->fields['projectstates_id'] ?? 0);
            $this->meta->saveTaskMeta($taskId, $input);
        }
        return $ok;
    }

    public function move(int $taskId, int $stateId): bool
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canUpdateItem() || !$this->validState($stateId)) return false;
        if ($stateId !== (int) ($task->fields['projectstates_id'] ?? 0) && !$this->stateAllowedForTask($taskId, $stateId)) return false;
        $payload = ['id' => $taskId, 'projectstates_id' => $stateId, 'auto_projectstates' => 0];
        $payload['percent_done'] = $this->progressForState($stateId, (int) ($task->fields['percent_done'] ?? 0));
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
                $target = $this->finishedStateForTask($id, $finishedStateId);
                $ok = $target > 0 && $this->move($id, $target);
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

        // "Mine" = tasks whose team contains the user or one of the user's groups. GLPI's
        // getActiveProjectTaskIDsForUser() only returns unfinished tasks, which made finished
        // tasks impossible to list even with "include finished".
        $mine = array_fill_keys($this->userTaskIds((int) Session::getLoginUserID()), true);
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

    /** IDs of tasks assigned to the user directly or through one of the user's groups. */
    private function userTaskIds(int $userId): array
    {
        global $DB;
        if ($userId <= 0) return [];
        $groups = [];
        foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $userId]]) as $row) {
            $groups[] = (int) $row['groups_id'];
        }
        $or = [['itemtype' => User::class, 'items_id' => $userId]];
        if ($groups !== []) $or[] = ['itemtype' => Group::class, 'items_id' => $groups];
        $ids = [];
        foreach ($DB->request(['SELECT' => ['projecttasks_id'], 'DISTINCT' => true, 'FROM' => ProjectTaskTeam::getTable(), 'WHERE' => ['OR' => $or]]) as $row) {
            $ids[] = (int) $row['projecttasks_id'];
        }
        return $ids;
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

    /** Initial state for a new task: the configured default when allowed, else the first allowed open state. */
    public function defaultTaskStateId(array $allowed = []): int
    {
        $default = Config::int('default_task_state_id', 0);
        if ($default <= 0) $default = $this->firstOpenStateId();
        if (!$allowed || in_array($default, $allowed, true)) return $default;
        foreach ($this->references->getProjectStates() as $state) {
            if (empty($state['is_finished']) && in_array((int) $state['id'], $allowed, true)) return (int) $state['id'];
        }
        return (int) $allowed[0];
    }

    private function stateAllowedForTask(int $taskId, int $stateId): bool
    {
        $allowed = $this->meta->getAllowedStateIds($taskId);
        return !$allowed || in_array($stateId, $allowed, true);
    }

    /** First finished state the task may use (bulk "complete"). */
    private function finishedStateForTask(int $taskId, int $fallback): int
    {
        $allowed = $this->meta->getAllowedStateIds($taskId);
        if (!$allowed) return $fallback;
        foreach ($this->references->getProjectStates() as $state) {
            if (!empty($state['is_finished']) && in_array((int) $state['id'], $allowed, true)) return (int) $state['id'];
        }
        return 0;
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
        // Week ticks (Mondays) for the Gantt header; thinned out on long projects.
        $ticks = [];
        $monday = strtotime('monday this week', $windowStart);
        $weeks = max(1, (int) ceil(($windowEnd - $monday) / (7 * DAY_TIMESTAMP)));
        $step = $weeks > 26 ? 4 : ($weeks > 12 ? 2 : 1);
        for ($w = 0, $t = $monday; $t <= $windowEnd; $w++, $t = strtotime('+1 week', $t)) {
            if ($t < $windowStart || $w % $step) continue;
            $ticks[] = ['pct' => round((($t - $windowStart) / $span) * 100, 2), 'label' => date('d/m', $t)];
        }
        $inWindow = $today >= $windowStart && $today <= $windowEnd;
        return ['has_dates' => true, 'items' => $items, 'ticks' => $ticks, 'start_label' => date('d/m/Y', $windowStart), 'end_label' => date('d/m/Y', $windowEnd), 'today_pct' => $inWindow ? round($todayPct, 2) : null, 'days' => (int) round($span / DAY_TIMESTAMP)];
    }

    /**
     * Gantt data for the Cronograma tab: day columns on short projects, week columns on long ones,
     * window aligned to whole weeks and stretched to include today, rows in tree order.
     */
    public function buildGantt(array $tree, array $project): array
    {
        $day = 86400;
        $midnight = static fn(int $ts): int => (int) strtotime(date('Y-m-d', $ts));
        $dated = array_values(array_filter($tree, static fn(array $t): bool => !empty($t['plan_start_ts']) && !empty($t['plan_end_ts'])));
        if (!$dated) return ['has_dates' => false, 'rows' => [], 'columns' => [], 'months' => []];
        $today = $midnight(time());
        $min = $midnight((int) min(array_column($dated, 'plan_start_ts')));
        $max = $midnight((int) max(array_column($dated, 'plan_end_ts')));
        if (!empty($project['plan_start_ts'])) $min = min($min, $midnight((int) $project['plan_start_ts']));
        if (!empty($project['plan_end_ts'])) $max = max($max, $midnight((int) $project['plan_end_ts']));
        if ($today < $min && $min - $today <= 45 * $day) $min = $today;
        if ($today > $max && $today - $max <= 45 * $day) $max = $today;
        $start = (int) strtotime('monday this week', $min);
        $end = (int) strtotime('sunday this week', $max);
        $days = (int) round(($end - $start) / $day) + 1;
        if ($days < 14) { $end = (int) strtotime('+' . (14 - $days) . ' days', $end); $days = 14; }
        $mode = $days <= 63 ? 'day' : 'week';
        $idx = static fn(int $ts): int => (int) round(($midnight($ts) - $start) / $day);
        $pct = static fn(float $d): float => round($d / $days * 100, 3);
        $wd = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
        $mn = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $columns = []; $months = [];
        for ($i = 0; $i < $days; $i++) {
            $ts = (int) strtotime('+' . $i . ' days', $start);
            $key = date('Y-m', $ts);
            if (!$months || $months[count($months) - 1]['key'] !== $key) $months[] = ['key' => $key, 'label' => $mn[(int) date('n', $ts)] . ' ' . date('Y', $ts), 'from' => $i, 'to' => $i];
            else $months[count($months) - 1]['to'] = $i;
            $w = (int) date('w', $ts);
            if ($mode === 'day') {
                $columns[] = ['label' => date('j', $ts), 'sub' => $wd[$w], 'is_weekend' => $w === 0 || $w === 6, 'is_today' => $ts === $today, 'is_week_start' => $w === 1];
            } elseif ($w === 1) {
                $columns[] = ['label' => date('d/m', $ts), 'sub' => 'S' . (int) date('W', $ts), 'is_weekend' => false, 'is_today' => $today >= $ts && $today < $ts + 7 * $day, 'is_week_start' => true];
            }
        }
        foreach ($months as &$m) { $m['left'] = $pct($m['from']); $m['width'] = $pct($m['to'] - $m['from'] + 1); $m['short'] = ($m['to'] - $m['from'] + 1) < ($mode === 'day' ? 4 : 10); } unset($m);
        $todayIdx = ($today >= $start && $today <= $end) ? $idx($today) : null;
        $rows = [];
        foreach ($dated as $t) {
            $s = $idx((int) $t['plan_start_ts']); $e = max($s, $idx((int) $t['plan_end_ts']));
            $s = max(0, min($days - 1, $s)); $e = max($s, min($days - 1, $e));
            $milestone = !empty($t['is_milestone']);
            $left = $milestone ? $pct($e + 0.5) : $pct($s);
            $width = $milestone ? 0 : $pct($e - $s + 1);
            $finished = !empty($t['state']['is_finished']) || (int) ($t['percent_done'] ?? 0) >= 100;
            $late = !$finished && $todayIdx !== null && $todayIdx > $e;
            $lateDays = (!$finished && $today > $midnight((int) $t['plan_end_ts'])) ? (int) round(($today - $midnight((int) $t['plan_end_ts'])) / $day) : 0;
            $tail = $left + $width;
            $rows[] = $t + [
                'g_left' => $left,
                'g_width' => $width,
                'g_late_left' => $late ? $pct($e + 1) : null,
                'g_late_width' => $late ? $pct($todayIdx - $e) : null,
                'g_late_days' => $lateDays,
                'g_finished' => $finished,
                'g_label_left' => ($late ? $pct($todayIdx + 1) : $tail) > 72,
                'g_range' => $milestone ? date('d/m', (int) $t['plan_end_ts']) : (date('d/m', (int) $t['plan_start_ts']) . ' – ' . date('d/m', (int) $t['plan_end_ts'])),
                'g_days' => $e - $s + 1,
            ];
        }
        return [
            'has_dates' => true, 'mode' => $mode, 'days' => $days, 'cols' => count($columns),
            'columns' => $columns, 'months' => $months, 'rows' => $rows,
            'today_pct' => $todayIdx !== null ? $pct($todayIdx + 0.5) : null,
            'today_label' => date('d/m', $today),
            'start_label' => date('d/m/Y', $start), 'end_label' => date('d/m/Y', $end),
        ];
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
            'allowed_state_ids' => MetaService::parseStateIds((string) ($meta['allowed_states'] ?? '')),
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
