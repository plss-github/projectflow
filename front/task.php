<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectflow\Application\TaskController;

include('../../../inc/includes.php');
Session::checkLoginUser();
$id=(int)($_GET['id']??0); if($id<=0){Html::displayNotFoundError();exit;}
$data=(new TaskController())->show($id); if($data===null){Html::displayRightError();exit;}
Html::header('Minhas tarefas - '.$data['task']['name'], $_SERVER['PHP_SELF'], 'helpdesk', GlpiPlugin\Projectflow\AssistanceMenu::class);
TemplateRenderer::getInstance()->display('@projectflow/task-native.html.twig',$data);
Html::footer();
