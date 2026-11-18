<?php

namespace GlpiPlugin\Projectflow;

use CommonDBTM;

/**
 * Tabela de metadados do projeto exposta à pesquisa nativa (campos Portfólio e Patrocinador).
 * Só serve para o motor de busca do GLPI saber a que classe a tabela pertence.
 */
class ProjectMetaItem extends CommonDBTM
{
    public static $rightname = 'project';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_projectflow_projectmeta';
    }

    public static function getTypeName($nb = 0)
    {
        return 'Project Flow';
    }
}
