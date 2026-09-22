<?php

use GlpiPlugin\Projectflow\Service\ActivityService;
use GlpiPlugin\Projectflow\Service\AssetService;
use GlpiPlugin\Projectflow\Service\MeetingService;
use GlpiPlugin\Projectflow\Service\TaskService;
use GlpiPlugin\Projectflow\Service\WorklogService;

include('../../../inc/includes.php');
Session::checkLoginUser();


function projectflow_task_json_input(): array
{
    $data = $_POST;
    if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($decoded)) $data = $decoded + $data;
    }
    return $data;
}

function projectflow_task_json_response(bool $ok, array $data = [], int $status = 200): never
{
    header('Content-Type: application/json; charset=UTF-8', true, $status);
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $input = projectflow_task_json_input();
    $action = (string)($input['action'] ?? $_GET['action'] ?? '');
    if ($action !== 'get' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        projectflow_task_json_response(false, ['message' => 'Método não permitido.'], 405);
    }
    if ($action !== 'get' && !Session::validateCSRF($input, true)) {
        projectflow_task_json_response(false, ['message' => 'Token de segurança inválido ou expirado. Recarregue a página e tente novamente.'], 403);
    }
    $service = new TaskService();

    switch ($action) {
        case 'get':
            $task = $service->getTask((int)($input['id'] ?? $_GET['id'] ?? 0));
            if ($task === null) projectflow_task_json_response(false, ['message' => 'Tarefa não encontrada ou sem acesso.'], 404);
            projectflow_task_json_response(true, ['task' => $task]);

        case 'create':
            $projectId = (int)($input['project_id'] ?? 0);
            $id = $service->create($projectId, $input);
            if (!$id) projectflow_task_json_response(false, ['message' => 'Não foi possível criar a tarefa.'], 400);
            projectflow_task_json_response(true, ['id' => $id, 'message' => 'Tarefa criada com sucesso.']);

        case 'update':
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0 || !$service->update($id, $input)) projectflow_task_json_response(false, ['message' => 'Não foi possível atualizar a tarefa.'], 400);
            projectflow_task_json_response(true, ['id' => $id, 'message' => 'Tarefa atualizada.']);

        case 'move':
            $id = (int)($input['id'] ?? 0);
            $state = (int)($input['projectstates_id'] ?? 0);
            if ($id <= 0 || !$service->move($id, $state)) projectflow_task_json_response(false, ['message' => 'Não foi possível mover a tarefa.'], 400);
            projectflow_task_json_response(true, ['id' => $id, 'state' => $state]);

        case 'bulk':
            $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));
            $operation = (string)($input['operation'] ?? '');
            $state = (int)($input['projectstates_id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);
            $updated = $service->bulk($projectId, $ids, $operation, $state);
            projectflow_task_json_response(true, ['updated' => $updated, 'message' => $updated . ' tarefa(s) atualizada(s).']);

        case 'team_add':
            if (!$service->addTaskMember((int)($input['task_id'] ?? 0), (string)($input['itemtype'] ?? ''), (int)($input['items_id'] ?? 0))) {
                projectflow_task_json_response(false, ['message' => 'Não foi possível adicionar o responsável/equipe.'], 400);
            }
            projectflow_task_json_response(true, ['message' => 'Responsável/equipe adicionado.']);

        case 'team_remove':
            if (!$service->removeTaskMember((int)($input['task_id'] ?? 0), (int)($input['relation_id'] ?? 0))) {
                projectflow_task_json_response(false, ['message' => 'Não foi possível remover o membro.'], 400);
            }
            projectflow_task_json_response(true, ['message' => 'Membro removido.']);

        case 'dependency_add':
            if (!$service->addDependency((int)($input['task_id'] ?? 0), (int)($input['target_id'] ?? 0), (int)($input['type'] ?? 0))) {
                projectflow_task_json_response(false, ['message' => 'Dependência inválida, duplicada ou criaria um ciclo.'], 400);
            }
            projectflow_task_json_response(true, ['message' => 'Dependência adicionada.']);

        case 'dependency_remove':
            if (!$service->removeDependency((int)($input['task_id'] ?? 0), (int)($input['link_id'] ?? 0))) {
                projectflow_task_json_response(false, ['message' => 'Não foi possível remover a dependência.'], 400);
            }
            projectflow_task_json_response(true, ['message' => 'Dependência removida.']);


        case 'activity_add':
            $taskId=(int)($input['task_id']??0);$id=(new ActivityService())->add($taskId,(string)($input['content']??''));
            if(!$id) projectflow_task_json_response(false,['message'=>'Não foi possível registrar a atividade.'],400);
            projectflow_task_json_response(true,['id'=>$id,'activities'=>(new ActivityService())->getForTask($taskId),'message'=>'Atividade registrada.']);

        case 'activity_delete':
            $taskId=(int)($input['task_id']??0);if(!(new ActivityService())->delete($taskId,(int)($input['activity_id']??0)))projectflow_task_json_response(false,['message'=>'Não foi possível remover a atividade.'],400);
            projectflow_task_json_response(true,['activities'=>(new ActivityService())->getForTask($taskId),'message'=>'Atividade removida.']);

        case 'worklog_add':
            $taskId=(int)($input['task_id']??0);$id=(new WorklogService())->addTaskLog($taskId,$input);if(!$id)projectflow_task_json_response(false,['message'=>'Informe uma duração válida e verifique suas permissões.'],400);
            projectflow_task_json_response(true,['id'=>$id,'worklogs'=>(new WorklogService())->getForTask($taskId),'message'=>'Horas registradas.']);

        case 'worklog_delete':
            $taskId=(int)($input['task_id']??0);if(!(new WorklogService())->delete((int)($input['worklog_id']??0),$taskId))projectflow_task_json_response(false,['message'=>'Não foi possível remover o lançamento.'],400);
            projectflow_task_json_response(true,['worklogs'=>(new WorklogService())->getForTask($taskId),'message'=>'Lançamento removido.']);

        case 'meeting_add':
            $taskId = (int)($input['task_id'] ?? 0);
            $meetingId = (new MeetingService())->createForTask($taskId, $input);
            if (!$meetingId) projectflow_task_json_response(false, ['message' => 'Não foi possível registrar a reunião.'], 400);
            projectflow_task_json_response(true, ['id' => $meetingId, 'meetings' => (new MeetingService())->getForTask($taskId), 'message' => 'Reunião registrada.']);

        case 'meeting_update':
            $taskId = (int)($input['task_id'] ?? 0);
            if (!(new MeetingService())->updateForTask($taskId, (int)($input['meeting_id'] ?? 0), $input)) {
                projectflow_task_json_response(false, ['message' => 'Não foi possível atualizar a reunião.'], 400);
            }
            projectflow_task_json_response(true, ['meetings' => (new MeetingService())->getForTask($taskId), 'message' => 'Reunião atualizada.']);

        case 'meeting_delete':
            $taskId = (int)($input['task_id'] ?? 0);
            if (!(new MeetingService())->deleteForTask($taskId, (int)($input['meeting_id'] ?? 0))) {
                projectflow_task_json_response(false, ['message' => 'Não foi possível remover a reunião.'], 400);
            }
            projectflow_task_json_response(true, ['meetings' => (new MeetingService())->getForTask($taskId), 'message' => 'Reunião removida.']);

        case 'asset_search':
            $taskId = (int)($input['task_id'] ?? 0);
            $items = (new AssetService())->searchForTask($taskId, (string)($input['itemtype'] ?? ''), (string)($input['q'] ?? ''), 20);
            projectflow_task_json_response(true, ['items' => $items]);

        case 'asset_add':
            $taskId=(int)($input['task_id']??0);if(!(new AssetService())->add($taskId,(string)($input['itemtype']??''),(int)($input['items_id']??0)))projectflow_task_json_response(false,['message'=>'Não foi possível vincular o ativo. Confira o item e as permissões.'],400);
            projectflow_task_json_response(true,['assets'=>(new AssetService())->getForTask($taskId),'message'=>'Ativo vinculado.']);

        case 'asset_remove':
            $taskId=(int)($input['task_id']??0);if(!(new AssetService())->remove($taskId,(int)($input['relation_id']??0)))projectflow_task_json_response(false,['message'=>'Não foi possível desvincular o ativo.'],400);
            projectflow_task_json_response(true,['assets'=>(new AssetService())->getForTask($taskId),'message'=>'Ativo desvinculado.']);

        case 'ticket_create':
            $taskId = (int)($input['task_id'] ?? 0);
            $ticketId = $service->createTicket($taskId, $input);
            if (!$ticketId) projectflow_task_json_response(false, ['message' => 'Não foi possível abrir o chamado a partir da tarefa.'], 400);
            projectflow_task_json_response(true, ['id' => $ticketId, 'task' => $service->getTask($taskId), 'message' => 'Chamado criado e vinculado à tarefa.']);

        case 'ticket_link':
            $taskId = (int)($input['task_id'] ?? 0);
            if (!$service->linkTicket($taskId, (int)($input['ticket_id'] ?? 0))) {
                projectflow_task_json_response(false, ['message' => 'Não foi possível vincular o chamado. Confira o ID e as permissões.'], 400);
            }
            projectflow_task_json_response(true, ['task' => $service->getTask($taskId), 'message' => 'Chamado vinculado à tarefa.']);

        case 'ticket_unlink':
            $taskId = (int)($input['task_id'] ?? 0);
            if (!$service->unlinkTicket($taskId, (int)($input['relation_id'] ?? 0))) {
                projectflow_task_json_response(false, ['message' => 'Não foi possível desvincular o chamado.'], 400);
            }
            projectflow_task_json_response(true, ['task' => $service->getTask($taskId), 'message' => 'Chamado desvinculado da tarefa.']);

        default:
            projectflow_task_json_response(false, ['message' => 'Ação inválida.'], 400);
    }
} catch (Throwable $e) {
    Toolbox::logError('[Project Flow] task endpoint: ' . $e->getMessage());
    projectflow_task_json_response(false, ['message' => 'Ocorreu um erro interno ao processar a solicitação.'], 500);
}
