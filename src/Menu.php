<?php

namespace GlpiPlugin\Projectflow;

use CommonGLPI;
use Project;
use ProjectTask;
use Session;

class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string { return 'Project Flow'; }
    public static function getMenuName($nb = 0): string { return 'Project Flow'; }
    public static function getIcon(): string { return 'ti ti-layout-kanban'; }

    public static function getMenuContent(): array
    {
        $base = PLUGIN_PROJECTFLOW_WEBDIR . '/front';
        $menu = [
            'title' => 'Project Flow',
            'page' => $base . '/index.php',
            'icon' => self::getIcon(),
            'options' => [
                'dashboard' => ['title' => 'Portfólio', 'page' => $base . '/index.php', 'icon' => 'ti ti-dashboard'],
            ],
        ];
        if (Project::canView()) {
            $menu['options']['templates'] = ['title' => 'Templates', 'page' => $base . '/templates.php', 'icon' => 'ti ti-template'];
        }
        if (Session::haveRight('config', UPDATE)) {
            $menu['options']['config'] = ['title' => 'Configurações', 'page' => $base . '/config.php', 'icon' => 'ti ti-settings'];
        }
        return $menu;
    }
}
