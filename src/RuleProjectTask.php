<?php

namespace GlpiPlugin\Projectflow;

use Rule;

/**
 * Business rules for project tasks (Administração > Regras > Regras de negócios para tarefas de projeto).
 */
class RuleProjectTask extends Rule
{
    use RuleSupport;

    public const ONADD = 1;
    public const ONUPDATE = 2;

    public static $rightname = 'config';
    public $can_sort = true;

    public function getTitle()
    {
        return 'Regras de negócios para tarefas de projeto';
    }

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Regras de negócios para tarefas de projeto' : 'Regra de negócio para tarefas de projeto';
    }

    public function maxActionsCount()
    {
        return count($this->getActions());
    }

    public function getCriterias()
    {
        $t = 'glpi_projecttasks';
        return [
            'name' => ['name' => 'Nome da tarefa', 'table' => $t, 'field' => 'name'],
            'content' => ['name' => 'Descrição', 'table' => $t, 'field' => 'content'],
            'projects_id' => ['name' => 'Projeto', 'table' => 'glpi_projects', 'field' => 'name', 'type' => 'dropdown'],
            '_pf_projecttypes_id' => ['name' => 'Tipo do projeto', 'table' => 'glpi_projecttypes', 'field' => 'name', 'type' => 'dropdown'],
            '_pf_project_risk' => ['name' => 'Risco do projeto', 'table' => 'glpi_projects', 'field' => 'risk_level', 'type' => 'pf_select', 'values' => self::riskValues()],
            '_pf_project_portfolio' => ['name' => 'Portfólio do projeto', 'table' => 'glpi_projects', 'field' => 'portfolio'],
            'projectstates_id' => ['name' => 'Estado', 'table' => 'glpi_projectstates', 'field' => 'name', 'type' => 'dropdown'],
            'projecttasktypes_id' => ['name' => 'Tipo da tarefa', 'table' => 'glpi_projecttasktypes', 'field' => 'name', 'type' => 'dropdown'],
            '_pf_priority' => ['name' => 'Prioridade', 'table' => $t, 'field' => 'priority', 'type' => 'dropdown_priority'],
            'is_milestone' => ['name' => 'Marco', 'table' => $t, 'field' => 'is_milestone', 'type' => 'yesno'],
            '_pf_is_subtask' => ['name' => 'É subtarefa', 'table' => $t, 'field' => 'projecttasks_id', 'type' => 'yesno'],
            '_pf_assignee' => ['name' => 'Responsável', 'table' => 'glpi_users', 'field' => 'name', 'type' => 'dropdown_users'],
        ];
    }

    public function getActions()
    {
        $actions = [
            'projectstates_id' => ['name' => 'Estado', 'table' => 'glpi_projectstates', 'type' => 'dropdown'],
            'projecttasktypes_id' => ['name' => 'Tipo da tarefa', 'table' => 'glpi_projecttasktypes', 'type' => 'dropdown'],
            '_pf_priority' => ['name' => 'Prioridade', 'type' => 'dropdown_priority'],
            'is_milestone' => ['name' => 'Marco', 'type' => 'yesno'],
            '_pf_attention' => ['name' => 'Marcar ponto de atenção', 'type' => 'yesonly'],
            '_pf_attention_note' => ['name' => 'Nota do ponto de atenção', 'type' => 'text'],
            '_pf_team_user' => [
                'name' => 'Equipe da tarefa - adicionar usuário', 'type' => 'pf_user',
                'force_actions' => ['append'], 'permitseveral' => ['append'], 'appendto' => '_pf_team_users',
            ],
            '_pf_team_group' => [
                'name' => 'Equipe da tarefa - adicionar grupo', 'table' => 'glpi_groups', 'type' => 'dropdown',
                'force_actions' => ['append'], 'permitseveral' => ['append'], 'appendto' => '_pf_team_groups',
            ],
        ];
        return $actions + parent::getActions();
    }
}
