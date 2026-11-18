<?php

namespace GlpiPlugin\Projectflow\Service;

use Html;
use Project;
use ProjectTask;
use Glpi\DBAL\QueryExpression;
use Search;
use Session;

/**
 * Portfólio paginado com a pesquisa nativa do GLPI.
 *
 * 1. A pesquisa nativa (critérios, regras de visibilidade e entidades do GLPI) devolve os IDs
 *    dos projetos que batem, na ordem da pesquisa.
 * 2. Arquivados saem (ou ficam só eles, na tela Arquivados).
 * 3. Um índice leve (uma consulta por tabela, sem carregar cada tarefa) calcula estado,
 *    atraso e saúde de todos os projetos encontrados: alimenta os indicadores e os atalhos
 *    (Ativos, Em atraso, Em atenção, Favoritos).
 * 4. Só a página atual (tamanho = "Resultados por página" do GLPI) é montada por completo.
 */
class PortfolioService
{
    private const SESSION_KEY = 'projectflow_portfolio_search';
    public const QUICK = ['all', 'active', 'overdue', 'attention', 'favorites'];

    public function __construct(
        private readonly ProjectService $projects = new ProjectService(),
        private readonly ReferenceService $references = new ReferenceService(),
        private readonly MetaService $meta = new MetaService(),
    ) {}

    /**
     * @param array $get query string of the portfolio page
     * @param bool $archived true = "Arquivados" screen
     */
    public function page(array $get, bool $archived, string $target): array
    {
        $mode = $archived ? 'archived' : 'active';
        $params = $this->searchParams($get, $mode);
        $quick = in_array((string) ($get['quick'] ?? ''), self::QUICK, true) ? (string) $get['quick'] : 'all';

        // 1) native search: every matching id, in the search order
        $ids = [];
        if (Project::canView()) {
            $search = $params;
            $search['start'] = 0;
            $search['list_limit'] = 1000000;
            $data = Search::getDatas(Project::class, $search, [1]);
            foreach ($data['data']['rows'] ?? [] as $row) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
            $ids = array_values(array_unique(array_filter($ids)));
        }

        // 2) archived split
        $archivedIds = ArchiveService::archivedIds();
        $ids = array_values(array_filter($ids, static fn(int $id): bool => isset($archivedIds[$id]) === $archived));

        // 3) light index -> indicators and shortcuts
        $index = $this->lightIndex($ids);
        $stats = ['total' => count($ids), 'active' => 0, 'overdue' => 0, 'attention' => 0, 'favorites' => 0, 'my_tasks' => 0];
        foreach ($index as $p) {
            if (!$p['finished']) $stats['active']++;
            if ($p['overdue']) $stats['overdue']++;
            if (in_array($p['health'], ['attention', 'critical'], true)) $stats['attention']++;
            if ($p['favorite']) $stats['favorites']++;
        }
        if (!$archived && ProjectTask::canView()) {
            $stats['my_tasks'] = count(ProjectTask::getActiveProjectTaskIDsForUser([(int) Session::getLoginUserID()]));
        }
        if ($quick !== 'all') {
            $ids = array_values(array_filter($ids, static function (int $id) use ($index, $quick): bool {
                $p = $index[$id] ?? null;
                if (!$p) return false;
                return match ($quick) {
                    'active' => !$p['finished'],
                    'overdue' => $p['overdue'],
                    'attention' => in_array($p['health'], ['attention', 'critical'], true),
                    'favorites' => $p['favorite'],
                    default => true,
                };
            }));
        }

        // 4) current page
        $total = count($ids);
        $limit = max(1, (int) ($_SESSION['glpilist_limit'] ?? 15));
        $start = max(0, (int) ($get['start'] ?? 0));
        if ($start >= $total) $start = $total > 0 ? (int) (floor(($total - 1) / $limit) * $limit) : 0;
        $pageIds = array_slice($ids, $start, $limit);
        $projects = $this->projects->getProjectsByIds($pageIds);
        $archivedInfo = [];
        if ($archived) {
            foreach ($pageIds as $id) $archivedInfo[$id] = ArchiveService::info($id);
        }
        foreach ($projects as &$p) {
            $p['is_archived'] = $archived;
            $p['archived'] = $archivedInfo[$p['id']] ?? null;
        }
        unset($p);

        return [
            'projects' => $projects,
            'stats' => $stats,
            'quick' => $quick,
            'total' => $total,
            'start' => $start,
            'limit' => $limit,
            'search_html' => $this->searchForm($params, $target, $quick),
            'pager_html' => $this->pager($start, $total, $target, $params, $quick),
            'limit_html' => '',
            'criteria_count' => $this->criteriaCount($params),
            'sort' => $this->sortInfo($target, $params, $quick),
            'has_criteria' => $this->hasCriteria($params),
            'quick_urls' => $this->quickUrls($target, $params),
        ];
    }

    /** Search params of the portfolio, remembered per screen (active / archived) in the session. */
    private function searchParams(array $get, string $mode): array
    {
        $key = self::SESSION_KEY . '_' . $mode;
        if (isset($get['reset'])) {
            unset($_SESSION[$key]);
            $get = [];
        }
        $incoming = array_intersect_key($get, array_flip(['criteria', 'metacriteria', 'sort', 'order']));
        if ($incoming !== []) {
            $_SESSION[$key] = $incoming;
        } else {
            $incoming = $_SESSION[$key] ?? [];
        }
        $params = Search::manageParams(Project::class, $incoming, false);
        if (empty($incoming['sort'])) {
            // Most recently changed first, as the portfolio always did.
            $params['sort'] = [19];
            $params['order'] = ['DESC'];
        }
        $params['is_deleted'] = 0;
        return $params;
    }

    /** Sort choices of the "Ordenado por" menu (search option id => label). */
    public const SORTS = [19 => 'Última atualização', 121 => 'Data de criação', 1 => 'Nome', 4 => 'Código', 12 => 'Estado', 3 => 'Prioridade', 5 => 'Progresso', 8 => 'Prazo', 7 => 'Início planejado'];

    private function sortInfo(string $target, array $params, string $quick): array
    {
        $field = (int) (($params['sort'] ?? [19])[0] ?? 19);
        $order = strtoupper((string) (($params['order'] ?? ['DESC'])[0] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $label = self::SORTS[$field] ?? (Search::getOptions(Project::class)[$field]['name'] ?? 'Última atualização');
        $url = function (int $f, string $o) use ($target, $params, $quick): string {
            $p = $params;
            $p['sort'] = [$f];
            $p['order'] = [$o];
            $qs = $this->query($p, $quick);
            return $target . (str_contains($target, '?') ? '&' : '?') . $qs;
        };
        $options = [];
        foreach (self::SORTS as $f => $name) {
            $defaultOrder = in_array($f, [19, 121, 3, 5], true) ? 'DESC' : 'ASC';
            $options[] = ['label' => $name, 'active' => $f === $field, 'url' => $url($f, $f === $field ? $order : $defaultOrder)];
        }
        return [
            'label' => $label,
            'order' => $order,
            'is_default' => $field === 19 && $order === 'DESC',
            'toggle_url' => $url($field, $order === 'ASC' ? 'DESC' : 'ASC'),
            'options' => $options,
        ];
    }

    private function criteriaCount(array $params): int
    {
        $n = 0;
        $walk = function (array $criteria) use (&$walk, &$n): void {
            foreach ($criteria as $c) {
                if (isset($c['criteria']) && is_array($c['criteria'])) $walk($c['criteria']);
                elseif (trim((string) ($c['value'] ?? '')) !== '') $n++;
            }
        };
        $walk($params['criteria'] ?? []);
        return $n + count($params['metacriteria'] ?? []);
    }

    private function hasCriteria(array $params): bool
    {
        foreach ($params['criteria'] ?? [] as $c) {
            if (isset($c['criteria']) || trim((string) ($c['value'] ?? '')) !== '') return true;
        }
        return !empty($params['metacriteria']);
    }

    /**
     * id => finished / overdue / health / favorite for every id, with three queries in total.
     * @param int[] $ids
     * @return array<int,array{finished:bool,overdue:bool,health:string,favorite:bool}>
     */
    private function lightIndex(array $ids): array
    {
        global $DB;
        if (!$ids) return [];
        $states = $this->references->getStateMap();
        $defaultState = \GlpiPlugin\Projectflow\Config::int('default_project_state_id', 0);
        $finishedStates = array_keys(array_filter($states, static fn($s) => !empty($s['is_finished'])));
        $favorites = $this->meta->getFavoriteIds();
        $index = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $metas = $this->meta->getForProjects($chunk);
            // overdue / attention tasks per project, without loading each task
            $taskStats = [];
            $overdueExpr = new QueryExpression(
                'SUM(CASE WHEN ' . $DB->quoteName('plan_end_date') . ' IS NOT NULL AND ' . $DB->quoteName('plan_end_date') . ' < ' . $DB->quoteValue(date('Y-m-d H:i:s'))
                . ' AND ' . $DB->quoteName('percent_done') . ' < 100'
                . ($finishedStates ? ' AND ' . $DB->quoteName('projectstates_id') . ' NOT IN (' . implode(',', array_map('intval', $finishedStates)) . ')' : '')
                . ' THEN 1 ELSE 0 END) AS ' . $DB->quoteName('overdue')
            );
            foreach ($DB->request([
                'SELECT' => ['projects_id', $overdueExpr],
                'FROM' => ProjectTask::getTable(),
                'WHERE' => ['projects_id' => $chunk, 'is_deleted' => 0, 'is_template' => 0],
                'GROUPBY' => ['projects_id'],
            ]) as $r) {
                $taskStats[(int) $r['projects_id']]['overdue'] = (int) $r['overdue'];
            }
            if ($DB->tableExists('glpi_plugin_projectflow_taskmeta')) {
                foreach ($DB->request([
                    'SELECT' => ['glpi_projecttasks.projects_id', new QueryExpression('COUNT(*) AS ' . $DB->quoteName('cnt'))],
                    'FROM' => 'glpi_plugin_projectflow_taskmeta',
                    'INNER JOIN' => ['glpi_projecttasks' => ['ON' => ['glpi_plugin_projectflow_taskmeta' => 'projecttasks_id', 'glpi_projecttasks' => 'id']]],
                    'WHERE' => ['glpi_projecttasks.projects_id' => $chunk, 'glpi_projecttasks.is_deleted' => 0, 'glpi_plugin_projectflow_taskmeta.attention' => 1],
                    'GROUPBY' => ['glpi_projecttasks.projects_id'],
                ]) as $r) {
                    $taskStats[(int) $r['projects_id']]['attention'] = (int) $r['cnt'];
                }
            }
            foreach ($DB->request([
                'SELECT' => ['id', 'projectstates_id', 'percent_done', 'plan_end_date'],
                'FROM' => Project::getTable(),
                'WHERE' => ['id' => $chunk],
            ]) as $r) {
                $id = (int) $r['id'];
                $stateId = (int) $r['projectstates_id'] ?: $defaultState;
                $h = ProjectService::healthOf($states[$stateId] ?? [], max(0, min(100, (int) $r['percent_done'])), $r['plan_end_date'] ?: null, $metas[$id] ?? [], $taskStats[$id] ?? []);
                $index[$id] = ['finished' => $h['finished'], 'overdue' => $h['overdue'], 'health' => $h['health'], 'favorite' => isset($favorites[$id])];
            }
        }
        return $index;
    }

    private function query(array $params, string $quick, array $extra = []): string
    {
        $q = array_intersect_key($params, array_flip(['criteria', 'metacriteria', 'sort', 'order']));
        if ($quick !== 'all') $q['quick'] = $quick;
        return http_build_query($q + $extra);
    }

    private function quickUrls(string $target, array $params): array
    {
        $urls = [];
        foreach (self::QUICK as $quick) {
            $qs = $this->query($params, $quick);
            $urls[$quick] = $target . ($qs !== '' ? (str_contains($target, '?') ? '&' : '?') . $qs : '');
        }
        return $urls;
    }

    private function searchForm(array $params, string $target, string $quick): string
    {
        ob_start();
        // GLPI's criteria rows read their values from $_SESSION['glpisearch'][itemtype]: expose the
        // portfolio criteria there while the form is rendered, then put the native list's back.
        $saved = $_SESSION['glpisearch'][Project::class] ?? null;
        $_SESSION['glpisearch'][Project::class] = ['criteria' => $params['criteria'] ?? [], 'metacriteria' => $params['metacriteria'] ?? []];
        try {
        Search::showGenericSearch(Project::class, $params + [
            'target' => $target,
            'showbookmark' => false,
            'showmassiveactions' => false,
            'addhidden' => $quick !== 'all' ? ['quick' => $quick] : [],
        ]);
        } finally {
            if ($saved === null) unset($_SESSION['glpisearch'][Project::class]); else $_SESSION['glpisearch'][Project::class] = $saved;
        }
        return (string) ob_get_clean();
    }

    /** Native GLPI pager ("N linhas / página", "Exibindo X a Y de Z linhas", page links). */
    private function pager(int $start, int $total, string $target, array $params, string $quick): string
    {
        if ($total <= 0) return '';
        $qs = $this->query($params, $quick);
        return \Glpi\Application\View\TemplateRenderer::getInstance()->render('components/pager.html.twig', [
            'href' => $target . ($qs !== '' ? (str_contains($target, '?') ? '&' : '?') . $qs : ''),
            'count' => $total,
            'start' => $start,
            'limit' => max(1, (int) ($_SESSION['glpilist_limit'] ?? 15)),
        ]);
    }
}
