<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\ReferenceService;
use Session;

class WorkloadController
{
    public function index(): array
    {
        $projects = [];
        foreach ((new ProjectService())->getAccessibleProjectIndex(null) as $p) {
            $projects[] = ['id' => $p['id'], 'name' => $p['name'] . ($p['code'] ? ' · ' . $p['code'] : '')];
        }
        usort($projects, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return [
            'projects' => $projects,
            'groups' => (new ReferenceService())->getGroups(),
            'ajax_workload_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/ajax/workload.php',
            'dashboard_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/index.php',
            'tasks_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php?scope=mine',
            'csrf_token' => Session::getNewCSRFToken(),
        ];
    }
}
