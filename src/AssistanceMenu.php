<?php

namespace GlpiPlugin\Projectflow;

use CommonGLPI;
use ProjectTask;

class AssistanceMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Minhas tarefas';
    }

    public static function getMenuName($nb = 0): string
    {
        return 'Minhas tarefas';
    }

    public static function getIcon(): string
    {
        return 'ti ti-list-check';
    }

    public static function canView(): bool
    {
        return ProjectTask::canView();
    }

    public static function getMenuContent(): array
    {
        return [
            'title' => 'Minhas tarefas',
            'page' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php?scope=mine',
            'icon' => self::getIcon(),
        ];
    }
}
