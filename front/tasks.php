<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectflow\Application\TasksController;

include('../../../inc/includes.php');
Session::checkLoginUser();
if (!ProjectTask::canView()) {
    Html::displayRightError();
    exit;
}
$scope = (string) ($_GET['scope'] ?? 'mine');
$includeFinished = !empty($_GET['finished']);
Html::header('Minhas tarefas', $_SERVER['PHP_SELF'], 'helpdesk', GlpiPlugin\Projectflow\AssistanceMenu::class);
TemplateRenderer::getInstance()->display('@projectflow/tasks.html.twig', (new TasksController())->index($scope, $includeFinished));
Html::footer();
