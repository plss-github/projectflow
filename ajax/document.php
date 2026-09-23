<?php

use GlpiPlugin\Projectflow\Service\DocumentService;


Session::checkLoginUser();

function projectflow_document_response(bool $ok, array $data = [], int $status = 200): never
{
    header('Content-Type: application/json; charset=UTF-8', true, $status);
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        projectflow_document_response(false, ['message' => 'Método não permitido.'], 405);
    }

    if (!Session::validateCSRF($_POST, true)) {
        projectflow_document_response(false, ['message' => 'Token de segurança inválido ou expirado. Recarregue a página e tente novamente.'], 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    $itemtype = (string) ($_POST['itemtype'] ?? '');
    $itemId = (int) ($_POST['items_id'] ?? 0);
    if (!in_array($itemtype, [Project::class, ProjectTask::class], true) || $itemId <= 0) {
        projectflow_document_response(false, ['message' => 'Item inválido.'], 400);
    }

    $service = new DocumentService();
    switch ($action) {
        case 'upload':
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                projectflow_document_response(false, ['message' => 'Selecione um arquivo para enviar.'], 400);
            }
            $id = $service->upload($itemtype, $itemId, $file, $_POST);
            if (!$id) {
                projectflow_document_response(false, ['message' => 'Falha no upload. Verifique o tipo de arquivo, tamanho e permissões de Documentos no GLPI.'], 400);
            }
            projectflow_document_response(true, ['id' => $id, 'documents' => $service->getForItem($itemtype, $itemId), 'message' => 'Documento anexado com sucesso.']);

        case 'link_existing':
            $documentId = (int) ($_POST['document_id'] ?? 0);
            if (!$service->linkExisting($itemtype, $itemId, $documentId)) {
                projectflow_document_response(false, ['message' => 'Não foi possível vincular o documento.'], 400);
            }
            projectflow_document_response(true, ['documents' => $service->getForItem($itemtype, $itemId), 'message' => 'Documento vinculado.']);

        case 'unlink':
            $relationId = (int) ($_POST['relation_id'] ?? 0);
            if (!$service->unlink($itemtype, $itemId, $relationId)) {
                projectflow_document_response(false, ['message' => 'Não foi possível desvincular o documento.'], 400);
            }
            projectflow_document_response(true, ['documents' => $service->getForItem($itemtype, $itemId), 'message' => 'Documento desvinculado.']);

        default:
            projectflow_document_response(false, ['message' => 'Ação inválida.'], 400);
    }
} catch (Throwable $e) {
    Toolbox::logError('[Project Flow] document endpoint: ' . $e->getMessage());
    projectflow_document_response(false, ['message' => 'Ocorreu um erro interno ao processar o documento.'], 500);
}
