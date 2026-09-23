<?php

use GlpiPlugin\Projectflow\Application\TasksController;


Session::checkLoginUser();
if (!ProjectTask::canView()) {
    Html::displayRightError();
    exit;
}
$scope = (string) ($_GET['scope'] ?? 'mine');
$includeFinished = !empty($_GET['finished']);
plugin_projectflow_register_assets();
Html::header('Minhas tarefas', $_SERVER['PHP_SELF'], 'tools', plugin_projectflow_header_item());
plugin_projectflow_display('tasks.html.twig', (new TasksController())->index($scope, $includeFinished));
Html::footer();
