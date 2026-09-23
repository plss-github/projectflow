<?php

use GlpiPlugin\Projectflow\Application\TaskController;


Session::checkLoginUser();
$id=(int)($_GET['id']??0); if($id<=0){Html::displayNotFoundError();exit;}
$data=(new TaskController())->show($id); if($data===null){Html::displayRightError();exit;}
plugin_projectflow_register_assets();
Html::header('Minhas tarefas - '.$data['task']['name'], $_SERVER['PHP_SELF'], 'tools', plugin_projectflow_header_item());
plugin_projectflow_display('task-native.html.twig',$data);
Html::footer();
