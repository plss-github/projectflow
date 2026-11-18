<?php

namespace GlpiPlugin\Projectflow;

use CommonDBTM;

/** Tabela de projetos arquivados exposta à pesquisa nativa (campo "Arquivado em"). */
class ArchiveItem extends CommonDBTM
{
    public static $rightname = 'project';

    public static function getTable($classname = null)
    {
        return Service\ArchiveService::TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return 'Arquivamento';
    }
}
