<?php

namespace GlpiPlugin\Projectflow\Service;

use GlpiPlugin\Projectflow\Config;
use GLPIKey;
use Project;
use RuntimeException;
use Throwable;

/**
 * Executive narrative for the status report, written by Google Gemini.
 *
 * The plugin computes the report (WeeklyReportService::build): status, KPIs, deliveries, work
 * in progress, risks, meetings, next steps. Only a compact fact sheet goes to the model, which
 * returns a small JSON (headline, summary, highlights, risks with action, prioritized next steps,
 * what the client must decide). Numbers on screen always come from the plugin.
 *
 * Speed: small prompt, short structured answer, low "thinking" budget and a cache keyed by the
 * facts (same data = instant answer). The API key is stored encrypted (GLPIKey).
 */
class AiReportService
{
    public const DEFAULT_MODEL = 'gemini-3.8-flash';
    public const DEFAULT_FALLBACK_MODEL = 'gemini-3.5-flash';
    private const PROMPT_VERSION = 'v2.1';
    private const RETRYABLE = [429, 500, 502, 503, 504];
    private const RETRY_DELAYS = [2, 5];
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly WeeklyReportService $reports = new WeeklyReportService(),
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

    public static function getFallbackModel(): string
    {
        $model = trim((string) Config::get('ai_gemini_fallback_model', self::DEFAULT_FALLBACK_MODEL));
        return preg_match('/^[A-Za-z0-9._-]{3,80}$/', $model) ? $model : '';
    }

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
     * @param string $scope 'week' or 'project'
     * @return array the structured report (WeeklyReportService format) with the AI narrative merged in
     */
    public function generate(int $projectId, string $scope, ?string $weekStart, bool $force = false): array
    {
        if (!self::isEnabled()) throw new RuntimeException('A geração por IA não está configurada. Informe a chave do Gemini nas Configurações do Project Flow.');
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) throw new RuntimeException('Sem permissão para gerar o relatório deste projeto.');
        @set_time_limit(120);
        $t0 = microtime(true);

        $data = $this->reports->build($projectId, $scope === 'project' ? 'project' : 'week', $weekStart);
        if ($data === null) throw new RuntimeException('Projeto não encontrado.');
        $facts = $this->facts($data);
        $model = self::getModel();
        $cacheKey = sha1(self::PROMPT_VERSION . '|' . $model . '|' . trim((string) Config::get('ai_instructions', '')) . '|' . json_encode($facts, JSON_UNESCAPED_UNICODE));

        $meta = ['cached' => false, 'attempts' => 0, 'used_fallback' => false, 'tokens_in' => 0, 'tokens_out' => 0, 'model' => $model];
        $ai = $force ? null : $this->cacheGet($cacheKey);
        if ($ai !== null) {
            $meta = ['cached' => true] + $ai['_meta'] + $meta;
        } else {
            $ai = $this->ask($facts, $meta);
            $ai['_meta'] = array_intersect_key($meta, array_flip(['model', 'tokens_in', 'tokens_out', 'used_fallback']));
            $this->cachePut($cacheKey, $ai);
        }
        $data = $this->merge($data, $ai);
        $data['source'] = 'ai';
        $data['ai'] = $meta + ['ms' => (int) round((microtime(true) - $t0) * 1000)];
        $data['text'] = $this->reports->renderText($data);
        return $data;
    }

    /** Compact fact sheet: only what an executive summary needs, in Portuguese keys. */
    private function facts(array $d): array
    {
        $k = $d['kpis'];
        $status = ['no_prazo' => 'no prazo', 'atencao' => 'atenção', 'critico' => 'crítico'][$d['status']] ?? $d['status'];
        return [
            'periodo' => $d['scope'] === 'week' ? 'semana de ' . $d['period_label'] : 'projeto inteiro',
            'hoje' => date('Y-m-d'),
            'projeto' => array_filter([
                'nome' => $d['project']['name'], 'estado' => $d['project']['state'], 'status_calculado' => $status,
                'prazo' => $d['project']['deadline'], 'gerente' => $d['project']['manager'], 'objetivo' => $d['project']['objective'] ?: null,
                'risco_declarado' => $d['project']['risk_level'],
            ], static fn($v) => $v !== null && $v !== ''),
            'indicadores' => array_filter([
                'andamento' => $k['progress'] . '%', 'tarefas_concluidas' => $k['tasks_done'] . '/' . $k['tasks_total'],
                'entregas_no_periodo' => $d['scope'] === 'week' ? $k['delivered_period'] : null,
                'em_atraso' => $k['overdue'], 'pontos_de_atencao' => $k['attention'],
                'horas_periodo' => $d['scope'] === 'week' ? $k['hours_period'] . ' (exec ' . $k['hours_period_exec'] . ', reuniões ' . $k['hours_period_meet'] . ')' : null,
                'horas_acumuladas' => $k['hours_total'], 'horas_previstas' => $k['hours_budget'], 'consumo_horas' => $k['hours_budget_pct'] !== null ? $k['hours_budget_pct'] . '%' : null,
            ], static fn($v) => $v !== null),
            'entregas' => array_map(static fn($x) => array_filter(['tarefa' => $x['name'], 'concluida_em' => $x['date'], 'dias_vs_prazo' => $x['variance_days'], 'marco' => $x['milestone'] ?: null], static fn($v) => $v !== null), $d['delivered']),
            'em_andamento' => array_map(static fn($x) => array_filter([
                'tarefa' => $x['name'], 'andamento' => $x['percent'] . '%', 'estado' => $x['state'], 'prazo' => $x['deadline'], 'atrasada' => $x['late'] ?: null,
                'responsavel' => $x['owner'] ?: null, 'horas_no_periodo' => $x['hours'],
                'registros' => array_map(static fn($n) => $n['text'], $x['notes']) ?: null,
            ], static fn($v) => $v !== null), $d['progress']),
            'riscos_detectados' => array_map(static fn($r) => $r['title'] . ' — ' . $r['detail'], $d['risks']),
            'reunioes' => array_map(static fn($m) => array_filter(['data' => $m['date'], 'titulo' => $m['title'], 'resumo' => $m['summary'] ?: null, 'decisoes' => $m['decisions'] ?: null, 'pendencias' => $m['actions'] ?: null]), $d['meetings']),
            'proximas_tarefas' => array_map(static fn($n) => array_filter(['tarefa' => $n['name'], 'responsavel' => $n['owner'] ?: null, 'prazo' => $n['deadline'], 'atrasada' => $n['late'] ?: null]), $d['next_steps']),
        ];
    }

    private function systemPrompt(): string
    {
        $base = <<<TXT
Você é um PMO sênior de uma consultoria de TI. Escreva a parte narrativa de um relatório de status para diretoria e cliente, em português do Brasil.
Regras:
- Use SOMENTE os fatos recebidos. Não invente tarefas, datas, pessoas, números ou decisões. Sem dado, não comente.
- Seja executivo: frases curtas, foco em impacto no prazo/escopo/esforço e no que precisa ser feito. Nada de listar tudo que existe.
- Não repita os indicadores um a um (eles já aparecem no relatório); use números só quando sustentam uma conclusão.
- "titulo": uma frase de até 110 caracteres com a situação real do projeto.
- "resumo": 2 a 4 frases: situação, o que avançou de relevante, principal risco e o encaminhamento.
- "destaques": até 4 conquistas/avanços concretos do período (vazio se não houver).
- "riscos": até 4, os mais relevantes, cada um com impacto e uma ação recomendada objetiva (verbo no infinitivo); "severidade" alta, media ou baixa.
- "proximos_passos": até 6 ações priorizadas (tarefas próximas do prazo, atrasadas e pendências de reunião), com responsável e prazo quando existirem nos dados.
- "decisoes_cliente": o que depende de decisão, validação ou apoio do cliente, só se os dados indicarem (vazio se não houver).
- Cite tarefas pelo nome. Não use Markdown, IDs internos nem e-mails.
TXT;
        $extra = trim((string) Config::get('ai_instructions', ''));
        return $extra !== '' ? $base . "\n\nInstruções adicionais da empresa:\n" . $extra : $base;
    }

    private function schema(): array
    {
        $str = ['type' => 'STRING'];
        return [
            'type' => 'OBJECT',
            'properties' => [
                'titulo' => $str,
                'resumo' => $str,
                'destaques' => ['type' => 'ARRAY', 'items' => $str],
                'riscos' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                    'titulo' => $str, 'impacto' => $str, 'acao' => $str, 'severidade' => ['type' => 'STRING', 'enum' => ['alta', 'media', 'baixa']],
                ], 'required' => ['titulo', 'impacto', 'acao', 'severidade']]],
                'proximos_passos' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                    'acao' => $str, 'responsavel' => $str, 'prazo' => $str,
                ], 'required' => ['acao']]],
                'decisoes_cliente' => ['type' => 'ARRAY', 'items' => $str],
            ],
            'required' => ['titulo', 'resumo', 'destaques', 'riscos', 'proximos_passos', 'decisoes_cliente'],
        ];
    }

    /** One Gemini call (main model, then fallback when overloaded). Degrades features the model rejects. */
    private function ask(array $facts, array &$meta): array
    {
        $model = self::getModel();
        $fallback = self::getFallbackModel();
        $attempts = [];
        try {
            [$response, $usedModel] = [$this->callModel($model, $facts, $attempts), $model];
        } catch (RuntimeException $e) {
            if (!$this->isRetryable($e) || $fallback === '' || $fallback === $model) throw $this->friendly($e, $model);
            try {
                [$response, $usedModel] = [$this->callModel($fallback, $facts, $attempts), $fallback];
                $meta['used_fallback'] = true;
            } catch (RuntimeException $e2) {
                throw $this->friendly($e2, $model . ' e ' . $fallback);
            }
        }
        $meta['attempts'] = count($attempts);
        $meta['model'] = $usedModel;
        $meta['tokens_in'] = (int) ($response['usageMetadata']['promptTokenCount'] ?? 0);
        $meta['tokens_out'] = (int) ($response['usageMetadata']['candidatesTokenCount'] ?? 0);
        if (!empty($response['promptFeedback']['blockReason'])) {
            throw new RuntimeException('O Gemini bloqueou a solicitação (' . $response['promptFeedback']['blockReason'] . ').');
        }
        $candidate = $response['candidates'][0] ?? null;
        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (!empty($part['thought'])) continue;
            $text .= (string) ($part['text'] ?? '');
        }
        $json = $this->parseJson($text);
        if ($json === null) throw new RuntimeException('O Gemini não retornou um relatório válido (' . ($candidate['finishReason'] ?? 'sem motivo informado') . '). Tente gerar de novo.');
        return $json;
    }

    /**
     * Calls a model with structured output and a small thinking budget. Models that reject the
     * thinking config or the schema are remembered (Config) and called without them next time.
     */
    private function callModel(string $model, array $facts, array &$attempts): array
    {
        $caps = (array) json_decode((string) Config::get('ai_model_caps', '{}'), true);
        $cap = (array) ($caps[$model] ?? []);
        for ($round = 0; $round < 3; $round++) {
            $gen = ['temperature' => 0.2, 'maxOutputTokens' => 3072, 'responseMimeType' => 'application/json'];
            if (empty($cap['no_schema'])) $gen['responseSchema'] = $this->schema();
            if (empty($cap['no_thinking'])) $gen['thinkingConfig'] = ['thinkingBudget' => 512];
            $body = [
                'systemInstruction' => ['parts' => [['text' => $this->systemPrompt()]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => "Fatos do projeto (JSON):\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . (empty($cap['no_schema']) ? '' : "\n\nResponda SOMENTE com um JSON com as chaves: titulo, resumo, destaques[], riscos[{titulo,impacto,acao,severidade}], proximos_passos[{acao,responsavel,prazo}], decisoes_cliente[].")]]]],
                'generationConfig' => $gen,
            ];
            try {
                return $this->request('POST', self::API_BASE . '/models/' . rawurlencode($model) . ':generateContent', self::getApiKey(), $body, $attempts);
            } catch (RuntimeException $e) {
                if ($e->getCode() !== 400) throw $e;
                $msg = mb_strtolower($e->getMessage());
                if (empty($cap['no_thinking']) && str_contains($msg, 'think')) $cap['no_thinking'] = true;
                elseif (empty($cap['no_schema']) && (str_contains($msg, 'schema') || str_contains($msg, 'mime') || str_contains($msg, 'json'))) $cap['no_schema'] = true;
                else throw $e;
                $caps[$model] = $cap;
                Config::set('ai_model_caps', json_encode($caps));
            }
        }
        throw new RuntimeException('O modelo ' . $model . ' recusou a solicitação.');
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $m)) $json = json_decode($m[0], true);
        return is_array($json) && isset($json['resumo']) ? $json : null;
    }

    /** AI narrative over the plugin facts. Numbers and lists of record stay the plugin's. */
    private function merge(array $d, array $ai): array
    {
        $s = static fn($v, int $max = 600): string => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        if ($s($ai['titulo'] ?? '') !== '') $d['headline'] = $s($ai['titulo'], 160);
        if ($s($ai['resumo'] ?? '') !== '') $d['summary'] = $s($ai['resumo'], 1200);
        $d['highlights'] = array_values(array_filter(array_map(fn($h) => $s($h, 300), array_slice((array) ($ai['destaques'] ?? []), 0, 5))));
        $risks = [];
        foreach (array_slice((array) ($ai['riscos'] ?? []), 0, 5) as $r) {
            if (!is_array($r) || $s($r['titulo'] ?? '') === '') continue;
            $sev = in_array($r['severidade'] ?? '', ['alta', 'media', 'baixa'], true) ? $r['severidade'] : 'media';
            $risks[] = ['title' => $s($r['titulo'], 200), 'detail' => $s($r['impacto'] ?? '', 400), 'action' => $s($r['acao'] ?? '', 300), 'severity' => $sev];
        }
        if ($risks) $d['risks'] = $risks;
        $steps = [];
        foreach (array_slice((array) ($ai['proximos_passos'] ?? []), 0, 8) as $n) {
            if (!is_array($n) || $s($n['acao'] ?? '') === '') continue;
            $steps[] = ['action' => $s($n['acao'], 300), 'owner' => $s($n['responsavel'] ?? '', 120), 'deadline' => $this->dateOrText($s($n['prazo'] ?? '', 40)), 'late' => false];
        }
        if ($steps) $d['next_steps'] = $steps;
        $d['client_asks'] = array_values(array_filter(array_map(fn($h) => $s($h, 300), array_slice((array) ($ai['decisoes_cliente'] ?? []), 0, 5))));
        return $d;
    }

    private function dateOrText(string $v): ?string
    {
        if ($v === '' || in_array(mb_strtolower($v), ['-', '—', 'n/a', 'não informado', 'sem prazo'], true)) return null;
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : $v;
    }

    // ---------------------------------------------------------------- cache

    private function cacheDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/projectflow/aicache';
    }

    private function cacheGet(string $key): ?array
    {
        $file = $this->cacheDir() . '/' . $key . '.json';
        if (!is_file($file) || filemtime($file) < time() - 30 * 86400) return null;
        $json = json_decode((string) @file_get_contents($file), true);
        return is_array($json) && isset($json['_meta']) ? $json : null;
    }

    private function cachePut(string $key, array $value): void
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
        @file_put_contents($dir . '/' . $key . '.json', json_encode($value, JSON_UNESCAPED_UNICODE));
        $files = glob($dir . '/*.json') ?: [];
        if (count($files) > 300) {
            usort($files, static fn($a, $b) => filemtime($a) <=> filemtime($b));
            foreach (array_slice($files, 0, count($files) - 300) as $f) @unlink($f);
        }
    }

    // ---------------------------------------------------------------- HTTP

    private function isRetryable(RuntimeException $e): bool
    {
        return in_array($e->getCode(), self::RETRYABLE, true);
    }

    private function friendly(RuntimeException $e, string $models): RuntimeException
    {
        return match (true) {
            in_array($e->getCode(), [503, 500, 502, 504], true) => new RuntimeException("O Gemini está sobrecarregado agora ({$models}). Já tentamos novamente; aguarde um minuto e gere de novo. Detalhe: " . $e->getMessage(), $e->getCode()),
            $e->getCode() === 429 => new RuntimeException('Limite de uso da chave do Gemini atingido (cota por minuto ou por dia). Aguarde ou revise a cota no Google AI Studio. Detalhe: ' . $e->getMessage(), 429),
            default => $e,
        };
    }

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
                if (!empty($retryAfter)) $wait = max($wait, min(8, (int) $retryAfter));
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
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_ENCODING => ''];
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
