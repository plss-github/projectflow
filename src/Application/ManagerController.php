<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Service\AiReportService;
use GlpiPlugin\Projectflow\Service\ManagerService;
use Session;
use User;

/** Central do gestor: one place to steer every project the user is responsible for. */
class ManagerController
{
    public function index(array $get): array
    {
        $uid = (int) Session::getLoginUserID();
        $canOthers = ManagerService::canViewOthers();
        $managerId = $canOthers ? max(0, (int) ($get['manager'] ?? 0)) : 0;
        if ($managerId <= 0) $managerId = $uid;
        $data = (new ManagerService())->build($managerId);
        $base = PLUGIN_PROJECTFLOW_WEBDIR;
        $firstTab = 'costs';
        foreach (['costs', 'overdue', 'unassigned', 'stale'] as $k) if (!empty($data['inbox'][$k])) { $firstTab = $k; break; }
        return $data + [
            'first_tab' => $firstTab,
            'is_self' => $managerId === $uid,
            'can_view_others' => $canOthers,
            'manager_dropdown' => $canOthers ? User::dropdown([
                'name' => 'manager', 'value' => $managerId, 'right' => 'all', 'display' => false,
                'width' => '260px', 'display_emptychoice' => false, 'entity' => $_SESSION['glpiactiveentities'] ?? 0,
                'on_change' => 'this.form.submit()',
            ]) : '',
            'ai_enabled' => AiReportService::isEnabled(),
            'today_label' => self::todayLabel(),
            'urls' => [
                'self' => $base . '/front/manager.php',
                'portfolio' => $base . '/front/index.php',
                'workload' => $base . '/front/workload.php',
                'tasks' => $base . '/front/tasks.php?scope=mine',
                'ajax_manager' => $base . '/ajax/manager.php',
                'ajax_task' => $base . '/ajax/task.php',
                'ajax_project' => $base . '/ajax/project.php',
            ],
            'csrf_token' => Session::getNewCSRFToken(),
            'user_dropdown' => User::dropdown([
                'name' => 'pf_assign_user', 'right' => 'all', 'display' => false, 'width' => '100%',
                'display_emptychoice' => true, 'emptylabel' => 'Escolha a pessoa', 'entity' => $_SESSION['glpiactiveentities'] ?? 0,
            ]),
        ];
    }

    private static function greeting(): string
    {
        $h = (int) date('G');
        return $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');
    }

    private static function todayLabel(): string
    {
        $dias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return $dias[(int) date('w')] . ', ' . (int) date('j') . ' de ' . $meses[(int) date('n') - 1] . ' de ' . date('Y');
    }
}
