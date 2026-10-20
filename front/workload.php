<?php

use GlpiPlugin\Projectflow\Application\WorkloadController;

Session::checkLoginUser();
if (!Project::canView() && !ProjectTask::canView()) {
    Html::displayRightError();
    exit;
}
plugin_projectflow_register_assets();
Html::header('Carga de trabalho', $_SERVER['PHP_SELF'], 'tools', plugin_projectflow_header_item());
plugin_projectflow_display('workload.html.twig', (new WorkloadController())->index());
Html::footer();
