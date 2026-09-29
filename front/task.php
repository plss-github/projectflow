<?php

use GlpiPlugin\Projectflow\Application\TaskController;


Session::checkLoginUser();
$id=(int)($_GET['id']??0); if($id<=0){Html::displayNotFoundError();exit;}
$data=(new TaskController())->show($id); if($data===null){Html::displayRightError();exit;}
// ?embed=1: same screen without the GLPI menu/header, used by the task popup of "Minhas tarefas".
$embed = !empty($_GET['embed']);
$data['embed'] = $embed;
plugin_projectflow_register_assets();
if ($embed) {
    Html::popHeader('Tarefa - '.$data['task']['name'], '', true);
    plugin_projectflow_display('task-native.html.twig',$data);
    Html::popFooter();
} else {
    Html::header('Minhas tarefas - '.$data['task']['name'], $_SERVER['PHP_SELF'], 'tools', plugin_projectflow_header_item());
    plugin_projectflow_display('task-native.html.twig',$data);
    Html::footer();
}
