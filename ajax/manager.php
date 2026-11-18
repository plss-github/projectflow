<?php

use GlpiPlugin\Projectflow\Service\AiReportService;
use GlpiPlugin\Projectflow\Service\ManagerService;

Session::checkLoginUser();

$reply = static function (bool $ok, array $data = [], int $status = 200): never {
    header('Content-Type: application/json; charset=UTF-8', true, $status);
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $reply(false, ['message' => 'Método não permitido.'], 405);
if (!Session::validateCSRF($_POST, true)) $reply(false, ['message' => 'Token de segurança inválido ou expirado. Recarregue a página.'], 403);
if (!Project::canView()) $reply(false, ['message' => 'Sem permissão.'], 403);

$action = (string) ($_POST['action'] ?? '');
$uid = (int) Session::getLoginUserID();
$managerId = ManagerService::canViewOthers() ? max(0, (int) ($_POST['manager'] ?? 0)) : 0;
if ($managerId <= 0) $managerId = $uid;

switch ($action) {
    case 'ai_summary':
        try {
            $service = new ManagerService();
            $facts = $service->aiFacts($service->build($managerId));
            $summary = (new AiReportService())->generatePortfolio($facts, !empty($_POST['force']));
        } catch (\RuntimeException $e) {
            $reply(false, ['message' => $e->getMessage()], 400);
        }
        $reply(true, ['summary' => $summary]);
    default:
        $reply(false, ['message' => 'Ação inválida.'], 400);
}
