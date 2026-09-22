<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Service\AssetService;
use GlpiPlugin\Projectflow\Service\MeetingService;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\ReferenceService;
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

        $project = (new ProjectService())->getProject((int) $task['projects_id']);
        if ($project === null) return null;

        $refs = new ReferenceService();
        $meetings = (new MeetingService())->getForTask($taskId);
        $subtasks = $tasks->getDirectSubtasks($taskId);
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

        return [
            'task' => $task,
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
            'project_tasks' => $tasks->getProjectTasks((int) $task['projects_id']),
            'ajax_task_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/ajax/task.php',
            'ajax_document_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/ajax/document.php',
            'my_tasks_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php?scope=mine',
            'tasks_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php?scope=mine',
            'dashboard_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/index.php',
            'csrf_token' => Session::getNewCSRFToken(),
        ];
    }
}
