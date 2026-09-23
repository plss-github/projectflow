<?php

use GlpiPlugin\Projectflow\Service\CatalogService;
use GlpiPlugin\Projectflow\Service\ProgressRuleService;

Session::checkLoginUser();

function projectflow_config_response(bool $ok, array $data = [], int $status = 200): never
{
    header('Content-Type: application/json; charset=UTF-8', true, $status);
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        projectflow_config_response(false, ['message' => 'Método não permitido.'], 405);
    }
    if (!Session::validateCSRF($_POST, true)) {
        projectflow_config_response(false, ['message' => 'Token de segurança inválido ou expirado. Recarregue a página e tente novamente.'], 403);
    }
    if (!CatalogService::canManage()) {
        projectflow_config_response(false, ['message' => 'Sem permissão para alterar as configurações.'], 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    $kind = (string) ($_POST['kind'] ?? '');
    $catalog = new CatalogService();
    $rules = new ProgressRuleService();

    switch ($action) {
        case 'catalog_save':
            if (!isset(CatalogService::KINDS[$kind])) projectflow_config_response(false, ['message' => 'Tipo de cadastro inválido.'], 400);
            $id = $catalog->save($kind, $_POST);
            if (!$id) projectflow_config_response(false, ['message' => 'Não foi possível salvar. Confira o nome.'], 400);
            projectflow_config_response(true, ['id' => $id, 'message' => 'Salvo.']);

        case 'catalog_delete':
            if (!isset(CatalogService::KINDS[$kind])) projectflow_config_response(false, ['message' => 'Tipo de cadastro inválido.'], 400);
            $result = $catalog->delete($kind, (int) ($_POST['id'] ?? 0));
            if ($result !== true) projectflow_config_response(false, ['message' => $result], 400);
            projectflow_config_response(true, ['message' => 'Excluído.']);

        case 'rule_save':
            $result = $rules->save($_POST);
            if (!is_int($result)) projectflow_config_response(false, ['message' => $result], 400);
            projectflow_config_response(true, ['id' => $result, 'message' => 'Regra salva.']);

        case 'rule_delete':
            if (!$rules->delete((int) ($_POST['id'] ?? 0))) projectflow_config_response(false, ['message' => 'Não foi possível excluir a regra.'], 400);
            projectflow_config_response(true, ['message' => 'Regra excluída.']);

        default:
            projectflow_config_response(false, ['message' => 'Ação inválida.'], 400);
    }
} catch (Throwable $e) {
    Toolbox::logError('[Project Flow] config endpoint: ' . $e->getMessage());
    projectflow_config_response(false, ['message' => 'Ocorreu um erro interno ao processar a solicitação.'], 500);
}
