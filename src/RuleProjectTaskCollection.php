<?php

namespace GlpiPlugin\Projectflow;

use RuleCollection;
use Session;

class RuleProjectTaskCollection extends RuleCollection
{
    public static $rightname = 'config';
    public $stop_on_first_match = false;
    public $menu_option = 'projectflow_projecttask';

    public function __construct($entity = 0)
    {
        parent::__construct();
        $this->entity = (int) $entity;
    }

    // The `config` right has no CREATE/PURGE bit (only READ/UPDATE): without this, GLPI hides the
    // "Adicionar" button of the rules list and refuses to add or purge a rule.
    public static function canCreate(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE);
    }

    public function getTitle()
    {
        return 'Regras de negócios para tarefas de projeto';
    }
}
