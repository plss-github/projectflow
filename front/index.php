<?php
use Glpi\Application\View\TemplateRenderer;use GlpiPlugin\Projectflow\Application\DashboardController;
include('../../../inc/includes.php');Session::checkLoginUser();if(!Project::canView()&&!ProjectTask::canView()){Html::displayRightError();exit;}
Html::header('Project Flow',$_SERVER['PHP_SELF'],'tools',GlpiPlugin\Projectflow\Menu::class);TemplateRenderer::getInstance()->display('@projectflow/dashboard.html.twig',(new DashboardController())->index());Html::footer();
