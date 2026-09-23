<?php

use GlpiPlugin\Projectflow\Service\ContractService;
use GlpiPlugin\Projectflow\Service\MeetingService;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\WeeklyReportService;


Session::checkLoginUser();


function projectflow_json_input(): array
{
    $data = $_POST;
    if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $data = $decoded + $data;
        }
    }
    return $data;
}

function projectflow_json_response(bool $ok, array $data = [], int $status = 200): never
{
    header('Content-Type: application/json; charset=UTF-8', true, $status);
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $input = projectflow_json_input();
    $action = (string)($input['action'] ?? $_GET['action'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        projectflow_json_response(false, ['message' => 'Método não permitido.'], 405);
    }
    if (!Session::validateCSRF($input, true)) {
        projectflow_json_response(false, ['message' => 'Token de segurança inválido ou expirado. Recarregue a página e tente novamente.'], 403);
    }
    $service = new ProjectService();

    switch ($action) {
        case 'create':
            $id = $service->create($input);
            if (!$id) projectflow_json_response(false, ['message' => 'Não foi possível criar o projeto. Verifique permissões e campos obrigatórios.'], 400);
            projectflow_json_response(true, ['id' => $id, 'url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/project.php?id=' . $id, 'message' => 'Projeto criado com sucesso.']);

        case 'template_create':
            $id = $service->createTemplate($input);
            if (!$id) projectflow_json_response(false, ['message' => 'Não foi possível criar o template. Verifique o nome, a entidade e suas permissões.'], 400);
            projectflow_json_response(true, ['id' => $id, 'url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/project.php?id=' . $id . '#execution', 'message' => 'Template criado.']);

        case 'update':
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0 || !$service->update($id, $input)) projectflow_json_response(false, ['message' => 'Não foi possível atualizar o projeto.'], 400);
            projectflow_json_response(true, ['id' => $id, 'message' => 'Projeto atualizado com sucesso.']);

        case 'favorite':
            $id = (int)($input['id'] ?? 0);
            $favorite = $id > 0 ? $service->toggleFavorite($id) : null;
            if ($favorite === null) projectflow_json_response(false, ['message' => 'Projeto não encontrado ou sem acesso.'], 404);
            projectflow_json_response(true, ['favorite' => $favorite]);

        case 'team_add':
            $id = (int)($input['project_id'] ?? 0);
            $type = (string)($input['itemtype'] ?? '');
            $itemId = (int)($input['items_id'] ?? 0);
            if (!$service->addTeamMember($id, $type, $itemId)) projectflow_json_response(false, ['message' => 'Não foi possível adicionar o membro à equipe.'], 400);
            projectflow_json_response(true, ['message' => 'Membro adicionado à equipe.']);

        case 'team_remove':
            $id = (int)($input['project_id'] ?? 0);
            $relationId = (int)($input['relation_id'] ?? 0);
            if (!$service->removeTeamMember($id, $relationId)) projectflow_json_response(false, ['message' => 'Não foi possível remover o membro.'], 400);
            projectflow_json_response(true, ['message' => 'Membro removido.']);


        case 'meeting_add':
            $projectId=(int)($input['project_id']??0);$meetingId=(new MeetingService())->create($projectId,$input);if(!$meetingId)projectflow_json_response(false,['message'=>'Não foi possível registrar a reunião.'],400);
            projectflow_json_response(true,['id'=>$meetingId,'meetings'=>(new MeetingService())->getForProject($projectId),'message'=>'Reunião registrada.']);

        case 'meeting_update':
            $projectId=(int)($input['project_id']??0);if(!(new MeetingService())->update($projectId,(int)($input['meeting_id']??0),$input))projectflow_json_response(false,['message'=>'Não foi possível atualizar a reunião.'],400);
            projectflow_json_response(true,['meetings'=>(new MeetingService())->getForProject($projectId),'message'=>'Reunião atualizada.']);

        case 'meeting_delete':
            $projectId=(int)($input['project_id']??0);if(!(new MeetingService())->delete($projectId,(int)($input['meeting_id']??0)))projectflow_json_response(false,['message'=>'Não foi possível remover a reunião.'],400);
            projectflow_json_response(true,['meetings'=>(new MeetingService())->getForProject($projectId),'message'=>'Reunião removida.']);

        case 'contract_link':
            $projectId=(int)($input['project_id']??0);if(!(new ContractService())->link($projectId,(int)($input['contract_id']??0)))projectflow_json_response(false,['message'=>'Não foi possível vincular o contrato.'],400);
            projectflow_json_response(true,['contracts'=>(new ContractService())->getForProject($projectId),'message'=>'Contrato vinculado.']);

        case 'contract_unlink':
            $projectId=(int)($input['project_id']??0);if(!(new ContractService())->unlink($projectId,(int)($input['relation_id']??0)))projectflow_json_response(false,['message'=>'Não foi possível desvincular o contrato.'],400);
            projectflow_json_response(true,['contracts'=>(new ContractService())->getForProject($projectId),'message'=>'Contrato desvinculado.']);

        case 'report_generate':
            $projectId=(int)($input['project_id']??0);$report=(new WeeklyReportService())->generate($projectId,$input['week_start']??null);if($report===null)projectflow_json_response(false,['message'=>'Não foi possível gerar o relatório.'],400);
            projectflow_json_response(true,['report'=>$report]);

        case 'report_save':
            $projectId=(int)($input['project_id']??0);$id=(new WeeklyReportService())->save($projectId,(string)($input['week_start']??''),(string)($input['content']??''));if(!$id)projectflow_json_response(false,['message'=>'Não foi possível salvar o relatório.'],400);
            projectflow_json_response(true,['id'=>$id,'message'=>'Relatório semanal salvo.']);

        case 'cost_add':
            $id = (int)($input['project_id'] ?? 0);
            $costId = $service->addCost($id, $input);
            if (!$costId) projectflow_json_response(false, ['message' => 'Não foi possível adicionar o custo. Verifique os campos e suas permissões.'], 400);
            projectflow_json_response(true, ['id' => $costId, 'costs' => $service->getCosts($id), 'message' => 'Custo adicionado ao projeto.']);

        case 'cost_remove':
            $id = (int)($input['project_id'] ?? 0);
            $costId = (int)($input['cost_id'] ?? 0);
            if (!$service->removeCost($id, $costId)) projectflow_json_response(false, ['message' => 'Não foi possível remover o custo.'], 400);
            projectflow_json_response(true, ['costs' => $service->getCosts($id), 'message' => 'Custo removido.']);

        default:
            projectflow_json_response(false, ['message' => 'Ação inválida.'], 400);
    }
} catch (Throwable $e) {
    Toolbox::logError('[Project Flow] project endpoint: ' . $e->getMessage());
    projectflow_json_response(false, ['message' => 'Ocorreu um erro interno ao processar a solicitação.'], 500);
}
