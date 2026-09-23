<?php
use GlpiPlugin\Projectflow\Application\TemplatesController;
Session::checkLoginUser();if(!Project::canView()){Html::displayRightError();exit;}plugin_projectflow_register_assets();
Html::header('Project Flow - Templates',$_SERVER['PHP_SELF'],'tools',plugin_projectflow_header_item());plugin_projectflow_display('templates.html.twig',(new TemplatesController())->index());Html::footer();
