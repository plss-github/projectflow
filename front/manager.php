<?php

use GlpiPlugin\Projectflow\Application\ManagerController;

Session::checkLoginUser();
if (!Project::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
plugin_projectflow_register_assets();
Html::header('Central do gestor', $_SERVER['PHP_SELF'], 'tools', plugin_projectflow_header_item());
plugin_projectflow_display('manager.html.twig', (new ManagerController())->index($_GET));
Html::footer();
