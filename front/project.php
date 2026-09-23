<?php
use GlpiPlugin\Projectflow\Application\ProjectController;
Session::checkLoginUser();$id=(int)($_GET['id']??0);if($id<=0){Html::displayNotFoundError();exit;}$data=(new ProjectController())->show($id);if($data===null){Html::displayRightError();exit;}plugin_projectflow_register_assets();
Html::header('Project Flow - '.$data['project']['name'],$_SERVER['PHP_SELF'],'tools',plugin_projectflow_header_item());plugin_projectflow_display('project.html.twig',$data);Html::footer();
