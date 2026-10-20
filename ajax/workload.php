<?php

use GlpiPlugin\Projectflow\Service\WorkloadService;

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');
$send = static function (bool $ok, array $data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $send(false, ['message' => 'Método não permitido.'], 405);
    if (!Session::validateCSRF($_POST, true)) {
        $send(false, ['message' => 'Token de segurança inválido ou expirado. Recarregue a página e tente novamente.'], 403);
    }
    if (!Project::canView() && !ProjectTask::canView()) $send(false, ['message' => 'Sem permissão.'], 403);

    $result = (new WorkloadService())->build([
        'start' => (string) ($_POST['start'] ?? ''),
        'weeks' => (int) ($_POST['weeks'] ?? 8),
        'mode' => (string) ($_POST['mode'] ?? 'remaining'),
        'project_id' => (int) ($_POST['project_id'] ?? 0),
        'group_id' => (int) ($_POST['group_id'] ?? 0),
        'focus_project_id' => (int) ($_POST['focus_project_id'] ?? 0),
    ]);
    if (empty($result['ok'])) $send(false, ['message' => (string) ($result['message'] ?? 'Não foi possível calcular a carga.')], 403);
    $send(true, ['workload' => $result]);
} catch (\Throwable $e) {
    \Toolbox::logInFile('projectflow', 'workload: ' . $e->getMessage() . "\n");
    $send(false, ['message' => 'Erro ao calcular a carga de trabalho.'], 500);
}
