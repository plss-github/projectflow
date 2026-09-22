<?php
use Glpi\Application\View\TemplateRenderer;use GlpiPlugin\Projectflow\Application\ProjectController;
include('../../../inc/includes.php');Session::checkLoginUser();$id=(int)($_GET['id']??0);if($id<=0){Html::displayNotFoundError();exit;}$data=(new ProjectController())->show($id);if($data===null){Html::displayRightError();exit;}Html::header('Project Flow - '.$data['project']['name'],$_SERVER['PHP_SELF'],'tools',GlpiPlugin\Projectflow\Menu::class);TemplateRenderer::getInstance()->display('@projectflow/project.html.twig',$data);Html::footer();
