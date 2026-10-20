<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Service\AssetService;
use GlpiPlugin\Projectflow\Service\MeetingService;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\ReferenceService;
use GlpiPlugin\Projectflow\Service\TaskCostService;
use GlpiPlugin\Projectflow\Service\TaskService;
use GlpiPlugin\Projectflow\Service\WorklogService;
use Session;

class TaskController
{
    public function show(int $taskId): ?array
    {
        $tasks = new TaskService();
        $task = $tasks->getTask($taskId);
        if ($task === null) return null;

        // The task screen only needs the project context (name/link/entity): avoid loading the
        // full project workspace (worklogs, history, documents, costs...) on every task open.
        $project = (new ProjectService())->getProjectSummary((int) $task['projects_id']);
        if ($project === null) return null;

        $refs = new ReferenceService();
        $meetings = (new MeetingService())->getForTask($taskId);
        $projectTasks = $tasks->getProjectTasks((int) $task['projects_id']);
        $subtasks = array_values(array_filter($projectTasks, static fn(array $item): bool => (int) ($item['parent_id'] ?? 0) === $taskId));
        $worklogMinutes = array_sum(array_map(static fn(array $item): int => (int) ($item['minutes'] ?? 0), $task['worklogs'] ?? []));
        $meetingMinutes = array_sum(array_map(static fn(array $item): int => (int) ($item['duration_minutes'] ?? 0), $meetings));
        $currentUserId = (int) Session::getLoginUserID();
        $myWorklogMinutes = array_sum(array_map(
            static fn(array $item): int => (int) ($item['user_id'] ?? 0) === $currentUserId ? (int) ($item['minutes'] ?? 0) : 0,
            $task['worklogs'] ?? []
        ));
        $myMeetingMinutes = array_sum(array_map(
            static fn(array $item): int => (int) ($item['user_id'] ?? 0) === $currentUserId ? (int) ($item['duration_minutes'] ?? 0) : 0,
            $meetings
        ));

        // Single timeline (as GLPI's ITIL timeline): comments, executions, meetings and dependencies,
        // newest first, ordered by when each entry was recorded.
        $timeline = [];
        foreach ($task['activities'] ?? [] as $item) $timeline[] = ['kind' => 'comment', 'at' => (string) ($item['date_creation'] ?? ''), 'item' => $item];
        foreach ($task['worklogs'] ?? [] as $item) $timeline[] = ['kind' => 'execution', 'at' => (string) ($item['date_creation'] ?: $item['work_date']), 'item' => $item];
        foreach ($meetings as $item) $timeline[] = ['kind' => 'meeting', 'at' => (string) ($item['date_creation'] ?? $item['start_at']), 'item' => $item];
        foreach ($task['dependencies'] ?? [] as $item) $timeline[] = ['kind' => 'dependency', 'at' => (string) ($item['date'] ?? ''), 'item' => $item];
        $costService = new TaskCostService();
        $costCtx = $costService->context($taskId);
        $costs = $costService->forTask($taskId);
        foreach ($costs as $item) $timeline[] = ['kind' => 'cost', 'at' => $item['date_creation'], 'item' => $item];
        usort($timeline, static fn(array $a, array $b): int => strcmp($b['at'], $a['at']));
        $timelineCounts = array_count_values(array_column($timeline, 'kind'));

        return [
            'task' => $task,
            'timeline' => $timeline,
            'timeline_counts' => $timelineCounts + ['comment' => 0, 'execution' => 0, 'meeting' => 0, 'dependency' => 0, 'cost' => 0],
            'cost_enabled' => (bool) ($costCtx['enabled'] ?? false),
            'can_add_cost' => (bool) ($costCtx['can_add'] ?? false),
            'can_approve_cost' => (bool) ($costCtx['can_approve'] ?? false),
            'cost_summary' => [
                'pending' => count(array_filter($costs, static fn($c) => $c['status'] === 'pending')),
                'approved_total' => ProjectService::brl(array_sum(array_map(static fn($c) => $c['status'] === 'approved' ? $c['cost'] : 0, $costs))),
            ],
            'project' => $project,
            'meetings' => $meetings,
            'subtasks' => $subtasks,
            'worklog_total_label' => WorklogService::formatMinutes($worklogMinutes),
            'meeting_total_label' => WorklogService::formatMinutes($meetingMinutes),
            'my_worklog_total_label' => WorklogService::formatMinutes($myWorklogMinutes),
            'my_meeting_total_label' => WorklogService::formatMinutes($myMeetingMinutes),
            'current_user_id' => $currentUserId,
            'states' => $refs->getProjectStates(),
            'priorities' => $refs->getPriorities(),
            'task_types' => $refs->getTaskTypes(),
            'users' => $refs->getUsers(),
            'groups' => $refs->getGroups(),
            'suppliers' => $refs->getSuppliers(),
            'contacts' => $refs->getContacts(),
            'ticket_categories' => $refs->getTicketCategories((int) $project['entity_id']),
            'asset_types' => (new AssetService())->getTypes(),
            'project_tasks' => $projectTasks,
            'ajax_task_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/ajax/task.php',
            'ajax_document_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/ajax/document.php',
            'my_tasks_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php?scope=mine',
            'tasks_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php?scope=mine',
            'dashboard_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/index.php',
            // GLPI preference (Minhas configurações > Layout do botão de ação): merged dropdown or split buttons.
            'action_btn_split' => (int) ($_SESSION['glpitimeline_action_btn_layout'] ?? 0) === \Config::TIMELINE_ACTION_BTN_SPLITTED,
            'task_base_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=',
            'csrf_token' => Session::getNewCSRFToken(),
        ];
    }
}
