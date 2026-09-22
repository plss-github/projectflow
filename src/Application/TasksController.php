<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Service\ReferenceService;
use GlpiPlugin\Projectflow\Service\TaskService;

class TasksController
{
    public function index(string $scope = 'mine', bool $includeFinished = false): array
    {
        $scope = $scope === 'all' ? 'all' : 'mine';
        $service = new TaskService();
        $tasks = $service->getTaskCenterTasks($scope === 'mine', $includeFinished);
        $stats = [
            'total' => count($tasks),
            'overdue' => 0,
            'attention' => 0,
            'today' => 0,
            'next7' => 0,
            'finished' => 0,
        ];
        $todayStart = strtotime('today 00:00:00');
        $todayEnd = strtotime('today 23:59:59');
        $next7End = strtotime('+7 days 23:59:59');
        $projects = [];
        foreach ($tasks as $task) {
            $stats['overdue'] += (int) !empty($task['is_overdue']);
            $stats['attention'] += (int) !empty($task['attention']);
            $stats['finished'] += (int) !empty($task['state']['is_finished']);
            if (!empty($task['plan_end_ts']) && empty($task['state']['is_finished'])) {
                $due = (int) $task['plan_end_ts'];
                if ($due >= $todayStart && $due <= $todayEnd) $stats['today']++;
                elseif ($due > $todayEnd && $due <= $next7End) $stats['next7']++;
            }
            $projectId = (int) $task['projects_id'];
            $projects[$projectId] = [
                'id' => $projectId,
                'name' => (string) $task['project_name'],
                'code' => (string) ($task['project_code'] ?? ''),
            ];
        }
        uasort($projects, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $refs = new ReferenceService();
        return [
            'scope' => $scope,
            'include_finished' => $includeFinished,
            'tasks' => $tasks,
            'stats' => $stats,
            'projects' => array_values($projects),
            'states' => $refs->getProjectStates(),
            'priorities' => $refs->getPriorities(),
            'dashboard_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/index.php',
            'tasks_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php',
        ];
    }
}
