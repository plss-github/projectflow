# Arquitetura do Project Flow 3

## Objetivo

Entregar uma interface completa para gestão e execução de projetos sem criar um segundo cadastro de projetos/tarefas. O GLPI continua como motor para identidade, entidades, perfis, projetos, tarefas, equipes, planejamento e relacionamentos nativos.

## Fluxo principal

```text
Portfólio
  -> Projeto
     -> Central de execução / Kanban
     -> Tarefa
        -> atividades
        -> horas
        -> executores
        -> documentos
        -> ativos
        -> chamados opcionais
     -> Reuniões
     -> Horas consolidadas
     -> Relatório semanal
```

O técnico pode pular a navegação do projeto e entrar diretamente por o botão **Minhas tarefas** do portfólio.

## Fonte de verdade nativa

| Domínio | Objeto / relação |
|---|---|
| Projeto | `Project` |
| Tarefa | `ProjectTask` |
| Estado | `ProjectState` |
| Equipe do projeto | `ProjectTeam` |
| Equipe da tarefa | `ProjectTaskTeam` |
| Dependências | `ProjectTaskLink` |
| Ticket da tarefa | `ProjectTask_Ticket` |
| ITSM do projeto | `Itil_Project` |
| Documento | `Document` + `Document_Item` |
| Contrato | `Contract` + `Contract_Item` |
| Financeiro opcional | `ProjectCost` |
| Auditoria nativa | `Log` |

## Dados próprios

| Tabela | Finalidade |
|---|---|
| `glpi_plugin_projectflow_configs` | padrões e comportamento global |
| `glpi_plugin_projectflow_projectmeta` | saúde, risco, portfólio, patrocinador, modos e orçamento de horas |
| `glpi_plugin_projectflow_taskmeta` | prioridade, atenção e lembretes |
| `glpi_plugin_projectflow_stateprogress` | percentual por estado do Kanban |
| `glpi_plugin_projectflow_favorites` | favoritos por usuário |
| `glpi_plugin_projectflow_activities` | diário operacional da tarefa |
| `glpi_plugin_projectflow_worklogs` | horas detalhadas de execução/reunião |
| `glpi_plugin_projectflow_meetings` | alinhamentos e decisões |
| `glpi_plugin_projectflow_taskassets` | vínculos operacionais tarefa x ativo |
| `glpi_plugin_projectflow_weeklyreports` | snapshots de relatório semanal |

Nenhuma dessas tabelas substitui `Project` ou `ProjectTask`.

## Estado obrigatório

```text
criar projeto/tarefa
  -> estado informado?
     sim -> valida ProjectState
     não -> usa estado inicial configurado
  -> sem estado válido -> operação falha
```

Templates clonados passam por uma correção pós-clone para preencher tarefas que vierem sem estado, usando `ProjectTask::update()`.

## Kanban e progresso

```text
mover card
  -> TaskService::move()
     -> ProjectTask::update()
        projectstates_id = coluna
        percent_done = percentual do estado
```

Estados finalizados sempre resultam em 100%.

## Modos de execução

### Direto

A tarefa é a unidade de execução. Ela possui diário, horas, equipe, ativos, documentos, dependências e vínculos ITSM opcionais.

### Tarefa + chamado

Na criação da tarefa:

```text
ProjectTask::add()
  -> Ticket::add()
  -> ProjectTask_Ticket
  -> Itil_Project
```

Se o usuário não possuir direito de criar Ticket, a criação no modo `ticket` é recusada. Se a criação automática do Ticket falhar, o plugin tenta remover a tarefa recém-criada para evitar estado inconsistente.

## Equipe

```text
adicionar executor na tarefa
  -> ProjectTaskTeam
  -> opcionalmente garante o mesmo ator em ProjectTeam
```

Remover da tarefa não remove automaticamente do projeto porque o ator pode participar de outras tarefas.

## Horas

- execução: lançamento manual na tarefa;
- reunião: sincronizada 1:1 com a reunião;
- agregação: soma dos worklogs do projeto;
- orçamento: metadado em minutos no projeto;
- financeiro: separado e opcional via `ProjectCost`.

## Reuniões

Reuniões não usam `ProjectTask`. O registro próprio armazena contexto de alinhamento e cria/atualiza um worklog do tipo `meeting` com a mesma duração.

## Relatório semanal

O gerador usa somente dados do ambiente:

- `ProjectTask` e suas atualizações;
- atividades do Project Flow;
- worklogs;
- reuniões;
- pontos de atenção;
- orçamento de horas.

Não existe dependência de IA na versão 3.0.

## Lembretes

- canal Project Flow: badge/banner quando a data de lembrete vence;
- canal e-mail: cron `Automation::taskreminders` enfileira `QueuedNotification` para usuários da equipe da tarefa.

## Camadas

- `front/`: entradas de página;
- `src/Application/`: view-model;
- `src/Service/`: domínio e integrações;
- `ajax/`: comandos POST/JSON;
- `templates/`: Twig;
- `public/js/`: comportamento;
- `public/css/`: interface.

`src/Controller/` não é utilizado nesta implementação para não colidir com o mecanismo de controllers Symfony do GLPI 11.

## Princípios

1. Não escrever diretamente em `glpi_projects` ou `glpi_projecttasks`.
2. Não duplicar cadastro de projeto/tarefa.
3. Fluxo diário do técnico deve caber em `Projetos -> Minhas tarefas -> Tarefa`.
4. Fluxo gerencial deve caber no workspace do projeto.
5. Reunião é alinhamento; tarefa é execução.
6. Horas são o indicador operacional principal.
7. Financeiro é opcional e restrito.
8. Recursos nativos do GLPI permanecem utilizáveis se o plugin for desativado.


## Integração com o menu do GLPI

**Ferramentas > Projetos** abre o portfólio do Project Flow pelo hook `redefine_menus`, e `/front/project.php` sem parâmetros é redirecionado. A lista nativa fica no botão **Lista nativa**. O comportamento é controlado pela opção `replace_native_projects_menu`. Não há entrada em Assistência: a fila **Minhas tarefas** abre pelo portfólio e mostra as `ProjectTask` ativas do usuário e de seus grupos. A tela individual usa a mesma `ProjectTask` do Kanban e do Planejamento.
