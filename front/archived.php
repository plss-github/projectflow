<?php
use GlpiPlugin\Projectflow\Application\DashboardController;
Session::checkLoginUser();
if (!Project::canView()) {
    Html::displayRightError();
    exit;
}
plugin_projectflow_register_assets();
Html::header('Projetos arquivados', $_SERVER['PHP_SELF'], 'tools', plugin_projectflow_header_item());
plugin_projectflow_display('dashboard.html.twig', (new DashboardController())->index($_GET, true));
Html::footer();
