<?php

namespace GlpiPlugin\Projectflow\Service;

use GlpiPlugin\Projectflow\Config;
use GLPIKey;
use Project;
use ProjectTask;
use RuntimeException;
use Throwable;

/**
 * Project report written by Google Gemini.
 *
 * Everything the report needs (project, tasks, comments, executions, meetings, hours) is
 * gathered here and sent in ONE generateContent request. The API key is stored encrypted
 * (GLPIKey) and never reaches the browser.
 */
class AiReportService
{
    public const DEFAULT_MODEL = 'gemini-3.8-flash';
    public const DEFAULT_FALLBACK_MODEL = 'gemini-3.5-flash';
    /** HTTP statuses that mean "try again": rate limit, overload, gateway errors. */
    private const RETRYABLE = [429, 500, 502, 503, 504];
    /** Waits (seconds) before the 2nd and 3rd attempts of the same model. */
    private const RETRY_DELAYS = [2, 6];
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const MAX_TEXT = 2000;
    private const MAX_TASKS = 300;
    private const MAX_ITEMS_PER_TASK = 60;

    public function __construct(
        private readonly TaskService $tasks = new TaskService(),
        private readonly ActivityService $activities = new ActivityService(),
        private readonly WorklogService $worklogs = new WorklogService(),
        private readonly MeetingService $meetings = new MeetingService(),
        private readonly ProjectService $projects = new ProjectService(),
    ) {}

    // ---------------------------------------------------------------- configuration

    public static function isEnabled(): bool
    {
        return Config::bool('ai_enabled', false) && self::getApiKey() !== '';
    }

    public static function getApiKey(): string
    {
        $stored = (string) Config::get('ai_gemini_key', '');
        if ($stored === '') return '';
        try {
            return (string) (new GLPIKey())->decrypt($stored);
        } catch (Throwable) {
            return '';
        }
    }

    public static function saveApiKey(string $key): void
    {
        $key = trim($key);
        Config::set('ai_gemini_key', $key === '' ? '' : (string) (new GLPIKey())->encrypt($key));
    }

    public static function getModel(): string
    {
        $model = trim((string) Config::get('ai_gemini_model', self::DEFAULT_MODEL));
        return preg_match('/^[A-Za-z0-9._-]{3,80}$/', $model) ? $model : self::DEFAULT_MODEL;
    }

    /** Optional second model, used when the main one stays overloaded after the retries. */
    public static function getFallbackModel(): string
    {
        $model = trim((string) Config::get('ai_gemini_fallback_model', self::DEFAULT_FALLBACK_MODEL));
        return preg_match('/^[A-Za-z0-9._-]{3,80}$/', $model) ? $model : '';
    }

    /** Check the key/model and return the models that support generateContent. */
    public function testConnection(?string $key = null, ?string $model = null): array
    {
        $key = trim((string) ($key ?: self::getApiKey()));
        if ($key === '') throw new RuntimeException('Informe a chave da API do Gemini.');
        $model = $model ?: self::getModel();
        $list = $this->request('GET', self::API_BASE . '/models?pageSize=200', $key);
        $models = [];
        foreach ((array) ($list['models'] ?? []) as $m) {
            if (in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) {
                $models[] = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            }
        }
        sort($models);
        return ['models' => $models, 'model_ok' => in_array($model, $models, true), 'model' => $model];
    }

    // ---------------------------------------------------------------- report

    /**
     * @param string $scope 'week' (period = week of $weekStart) or 'project' (whole history)
     */
    public function generate(int $projectId, string $scope, ?string $weekStart): array
    {
        if (!self::isEnabled()) throw new RuntimeException('A geração por IA não está configurada. Informe a chave do Gemini nas Configurações do Project Flow.');
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) throw new RuntimeException('Sem permissão para gerar o relatório deste projeto.');

        @set_time_limit(180);
        $payload = $this->collect($projectId, $scope === 'project' ? 'project' : 'week', $weekStart);
        $body = [
            'systemInstruction' => ['parts' => [['text' => $this->systemPrompt()]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => "Monte o relatório a partir destes dados (JSON):\n\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            ]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 8192, 'responseMimeType' => 'text/plain'],
        ];
        $model = self::getModel();
        $fallback = self::getFallbackModel();
        $attempts = [];
        try {
            $response = $this->request('POST', self::API_BASE . '/models/' . rawurlencode($model) . ':generateContent', self::getApiKey(), $body, $attempts);
        } catch (RuntimeException $e) {
            // Still overloaded after the retries: try the fallback model once (same payload).
            if (!$this->isRetryable($e) || $fallback === '' || $fallback === $model) {
                throw $this->friendly($e, $model);
            }
            try {
                $response = $this->request('POST', self::API_BASE . '/models/' . rawurlencode($fallback) . ':generateContent', self::getApiKey(), $body, $attempts);
                $model = $fallback;
            } catch (RuntimeException $e2) {
                throw $this->friendly($e2, $model . ' e ' . $fallback);
            }
        }

        if (!empty($response['promptFeedback']['blockReason'])) {
            throw new RuntimeException('O Gemini bloqueou a solicitação (' . $response['promptFeedback']['blockReason'] . ').');
        }
        $candidate = $response['candidates'][0] ?? null;
        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) $text .= (string) ($part['text'] ?? '');
        $text = trim($text);
        if ($text === '') throw new RuntimeException('O Gemini não retornou texto (' . ($candidate['finishReason'] ?? 'sem motivo informado') . ').');

        return [
            'text' => $text,
            'model' => $model,
            'finish_reason' => (string) ($candidate['finishReason'] ?? ''),
            'tokens_in' => (int) ($response['usageMetadata']['promptTokenCount'] ?? 0),
            'tokens_out' => (int) ($response['usageMetadata']['candidatesTokenCount'] ?? 0),
            'period' => $payload['periodo'],
            'attempts' => count($attempts),
            'used_fallback' => $model === $fallback && $model !== self::getModel(),
        ];
    }

    /** Gather every piece of project information for a single prompt. */
    public function collect(int $projectId, string $scope, ?string $weekStart): array
    {
        [$start, $end] = $scope === 'week' ? $this->weekRange($weekStart) : [null, null];
        $inPeriod = static function (?string $date) use ($start, $end): bool {
            if ($start === null) return true;
            $d = substr((string) $date, 0, 10);
            return $d >= $start && $d <= $end;
        };

        $p = $this->projects->getProject($projectId) ?? [];
        $totalHours = $this->worklogs->getSummary($projectId);
        $periodHours = $start ? $this->worklogs->getSummary($projectId, $start, $end) : $totalHours;
        $budget = (int) ($p['hours_budget_minutes'] ?? 0);

        $allMeetings = $this->meetings->getForProject($projectId, 500);
        $meetingsByTask = [];
        $projectMeetings = [];
        foreach ($allMeetings as $m) {
            if (!$inPeriod($m['start_at'] ?? null)) continue;
            $row = [
                'data' => $m['start_at'], 'titulo' => $m['title'], 'duracao' => $m['duration_label'],
                'registrado_por' => $m['user_name'], 'participantes' => $this->cut($m['participants']),
                'resumo' => $this->cut($m['summary']), 'decisoes' => $this->cut($m['decisions']), 'pendencias' => $this->cut($m['actions']),
            ];
            if ((int) $m['task_id'] > 0) $meetingsByTask[(int) $m['task_id']][] = $row; else $projectMeetings[] = $row;
        }

        $taskHours = $this->worklogs->getTaskTotals($projectId);
        $tasks = [];
        foreach (array_slice($this->tasks->getProjectTasks($projectId), 0, self::MAX_TASKS) as $t) {
            $id = (int) $t['id'];
            $comments = [];
            foreach ($this->activities->getForTask($id, 500) as $a) {
                if (!$inPeriod($a['date_creation'] ?? null)) continue;
                $comments[] = ['data' => $a['date_creation'], 'autor' => $a['user_name'], 'texto' => $this->cut($a['content'])];
            }
            $executions = [];
            foreach ($this->worklogs->getForTask($id, 500) as $w) {
                if (!$inPeriod($w['work_date'] ?? null)) continue;
                $executions[] = ['data' => $w['work_date'], 'profissional' => $w['user_name'], 'duracao' => $w['duration_label'], 'descricao' => $this->cut($w['comment'])];
            }
            $modified = substr((string) ($t['date_mod'] ?? ''), 0, 10);
            $touched = $start === null || $comments || $executions || !empty($meetingsByTask[$id]) || ($modified !== '' && $inPeriod($modified));
            $h = $taskHours[$id] ?? null;
            $tasks[] = [
                'id' => $id,
                'nome' => $t['name'],
                'tarefa_pai' => $t['parent_name'] ?: null,
                'estado' => $t['state']['name'] ?? '',
                'finalizada' => !empty($t['state']['is_finished']) || (int) $t['percent_done'] >= 100,
                'andamento_percentual' => (int) $t['percent_done'],
                'prioridade' => $t['priority_name'],
                'tipo' => $t['type_name'] ?: null,
                'marco' => (bool) $t['is_milestone'],
                'inicio_planejado' => $t['plan_start_date'],
                'prazo' => $t['plan_end_date'],
                'atrasada' => (bool) $t['is_overdue'],
                'ponto_de_atencao' => $t['attention'] ? ($t['attention_note'] ?: true) : false,
                'solicitante' => $t['requester_name'],
                'executores' => array_column($t['team_summary'] ?? [], 'name'),
                'descricao' => $this->cut($t['content']),
                'horas_previstas' => $t['planned_duration_hours'] ?: null,
                'horas_total' => $h['total_label'] ?? '0h',
                'horas_execucao_total' => $h['execution_label'] ?? '0h',
                'horas_reuniao_total' => $h['meeting_label'] ?? '0h',
                'movimentada_no_periodo' => $touched,
                'ultima_alteracao' => $t['date_mod'],
                'comentarios' => array_slice($comments, 0, self::MAX_ITEMS_PER_TASK),
                'execucoes' => array_slice($executions, 0, self::MAX_ITEMS_PER_TASK),
                'reunioes' => array_slice($meetingsByTask[$id] ?? [], 0, self::MAX_ITEMS_PER_TASK),
            ];
        }

        return [
            'periodo' => $start ? ['tipo' => 'semana', 'inicio' => $start, 'fim' => $end] : ['tipo' => 'projeto_inteiro'],
            'gerado_em' => date('Y-m-d H:i'),
            'projeto' => [
                'nome' => $p['name'] ?? '', 'codigo' => $p['code'] ?? '', 'objetivo' => $this->cut($p['objective'] ?? ''),
                'descricao' => $this->cut($p['content'] ?? ''), 'estado' => $p['state']['name'] ?? '', 'andamento_percentual' => (int) ($p['percent_done'] ?? 0),
                'saude' => $p['health_effective'] ?? '', 'risco' => $p['risk_level'] ?? '', 'prioridade' => $p['priority_name'] ?? '',
                'inicio_planejado' => $p['plan_start_date'] ?? null, 'prazo' => $p['plan_end_date'] ?? null, 'atrasado' => (bool) ($p['is_overdue'] ?? false),
                'responsavel' => $p['manager_name'] ?? '', 'grupo' => $p['group_name'] ?? '', 'patrocinador' => $p['sponsor'] ?? '', 'portfolio' => $p['portfolio'] ?? '',
                'contabilizacao' => ProjectService::costModeLabel((string) ($p['cost_mode'] ?? 'hours')),
            ],
            'horas' => [
                'periodo' => ['execucao' => $periodHours['execution_label'], 'reunioes' => $periodHours['meeting_label'], 'total' => $periodHours['total_label']],
                'acumulado_projeto' => ['execucao' => $totalHours['execution_label'], 'reunioes' => $totalHours['meeting_label'], 'total' => $totalHours['total_label']],
                'previstas_projeto' => $budget > 0 ? WorklogService::formatMinutes($budget) : null,
                'saldo' => $budget > 0 ? WorklogService::formatMinutes(max(0, $budget - (int) $totalHours['total_minutes'])) : null,
                'excedente' => $budget > 0 && $totalHours['total_minutes'] > $budget ? WorklogService::formatMinutes($totalHours['total_minutes'] - $budget) : null,
            ],
            'reunioes_do_projeto' => $projectMeetings,
            'tarefas' => $tasks,
        ];
    }

    private function systemPrompt(): string
    {
        $base = <<<TXT
Você é o PMO de uma consultoria de TI e escreve relatórios de acompanhamento de projeto para gestores e clientes, em português do Brasil.
Use SOMENTE os dados recebidos. Não invente tarefas, números, datas, pessoas ou decisões. Se uma informação não existir, diga que não há registro.
Quando o período for uma semana, trate como "no período" apenas o que tem data dentro dele; o restante é contexto.
Formato: texto simples (sem Markdown, sem tabelas, sem asteriscos), títulos em MAIÚSCULAS, itens com "• ". Seja objetivo.
Estrutura:
RELATÓRIO DE ACOMPANHAMENTO - <nome do projeto>
Período: <período> · Andamento geral: <x>%
RESUMO EXECUTIVO (3 a 5 frases: situação, avanço, principal risco)
ENTREGAS CONCLUÍDAS
EM ANDAMENTO (o que avançou, com base nos comentários e execuções)
REUNIÕES E DECISÕES (decisões e pendências das reuniões)
PONTOS DE ATENÇÃO E RISCOS (atrasos, pontos de atenção, estouro de horas, bloqueios citados)
HORAS (execução, reuniões e total do período; acumulado, previsto e saldo/excedente quando houver)
PRÓXIMOS PASSOS (tarefas em aberto por prazo, pendências das reuniões)
Cite as tarefas pelo nome. Não exponha IDs internos nem e-mails.
TXT;
        $extra = trim((string) Config::get('ai_instructions', ''));
        return $extra !== '' ? $base . "\n\nInstruções adicionais da empresa:\n" . $extra : $base;
    }

    // ---------------------------------------------------------------- helpers

    private function cut(mixed $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        return mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT) . '…' : $text;
    }

    private function weekRange(?string $value): array
    {
        try {
            $d = trim((string) $value) !== '' ? new \DateTimeImmutable((string) $value) : new \DateTimeImmutable('today');
        } catch (Throwable) {
            $d = new \DateTimeImmutable('today');
        }
        $monday = $d->modify('monday this week');
        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
    }

    private function isRetryable(RuntimeException $e): bool
    {
        return in_array($e->getCode(), self::RETRYABLE, true);
    }

    private function friendly(RuntimeException $e, string $models): RuntimeException
    {
        return match (true) {
            in_array($e->getCode(), [503, 500, 502, 504], true) => new RuntimeException("O Gemini está sobrecarregado agora ({$models}). Já tentamos novamente algumas vezes; aguarde um ou dois minutos e gere de novo. Detalhe: " . $e->getMessage(), $e->getCode()),
            $e->getCode() === 429 => new RuntimeException('Limite de uso da chave do Gemini atingido (cota por minuto ou por dia). Aguarde ou revise a cota/faturamento no Google AI Studio. Detalhe: ' . $e->getMessage(), 429),
            default => $e,
        };
    }

    /**
     * HTTP call with GLPI's proxy settings. The key goes in a header, never in the URL.
     * Retryable statuses are retried with a short backoff (Retry-After is honored, capped).
     * The exception code carries the last HTTP status.
     */
    private function request(string $method, string $url, string $key, ?array $body = null, array &$attempts = []): array
    {
        $delays = self::RETRY_DELAYS;
        while (true) {
            try {
                $attempts[] = $url;
                return $this->requestOnce($method, $url, $key, $body, $retryAfter);
            } catch (RuntimeException $e) {
                if (!$this->isRetryable($e) || $delays === []) throw $e;
                $wait = array_shift($delays);
                if (!empty($retryAfter)) $wait = max($wait, min(10, (int) $retryAfter));
                sleep($wait);
            }
        }
    }

    private function requestOnce(string $method, string $url, string $key, ?array $body, ?int &$retryAfter = null): array
    {
        $retryAfter = null;
        global $CFG_GLPI;
        if (!function_exists('curl_init')) throw new RuntimeException('A extensão PHP curl não está disponível no servidor.');
        $ch = curl_init($url);
        $headers = ['x-goog-api-key: ' . $key, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 150,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        if (!empty($CFG_GLPI['proxy_name'])) {
            $opts[CURLOPT_PROXY] = $CFG_GLPI['proxy_name'];
            $opts[CURLOPT_PROXYPORT] = (int) ($CFG_GLPI['proxy_port'] ?? 0);
            if (!empty($CFG_GLPI['proxy_user'])) {
                $pass = '';
                try { $pass = (string) (new GLPIKey())->decrypt((string) ($CFG_GLPI['proxy_passwd'] ?? '')); } catch (Throwable) {}
                $opts[CURLOPT_PROXYUSERPWD] = $CFG_GLPI['proxy_user'] . ':' . $pass;
            }
        }
        $opts[CURLOPT_HEADERFUNCTION] = static function ($curl, string $line) use (&$retryAfter): int {
            if (stripos($line, 'Retry-After:') === 0) $retryAfter = (int) trim(substr($line, 12));
            return strlen($line);
        };
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Não foi possível conectar ao Gemini: ' . $error);
        $json = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($json)) {
            $msg = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
            throw new RuntimeException('Erro do Gemini (HTTP ' . $status . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 300) : '.'), $status);
        }
        return $json;
    }
}
