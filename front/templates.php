<?php
use Glpi\Application\View\TemplateRenderer;use GlpiPlugin\Projectflow\Application\TemplatesController;
include('../../../inc/includes.php');Session::checkLoginUser();if(!Project::canView()){Html::displayRightError();exit;}Html::header('Project Flow - Templates',$_SERVER['PHP_SELF'],'tools',GlpiPlugin\Projectflow\Menu::class);TemplateRenderer::getInstance()->display('@projectflow/templates.html.twig',(new TemplatesController())->index());Html::footer();
