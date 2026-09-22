# Validação do pacote 3.4.0

## Validações executadas no build

- `php -l` em todos os arquivos PHP: **OK**.
- `node --check public/js/projectflow-3.4.js`: **OK**.
- parse do `composer.json`: **OK**.
- balanço de delimitadores e blocos Twig dos sete templates: **OK**.
- verificação de templates usados pelos front controllers: **OK**.
- verificação de endpoints AJAX usados pelos controllers: **OK**.
- ausência do namespace/pasta reservada `src/Controller`: **OK**.
- varredura contra escrita SQL direta em `glpi_projects` e `glpi_projecttasks`: **OK**.
- totais de horas usam todos os lançamentos persistidos, sem o limite aplicado às listas de interface: **OK**.
- remoção de custo financeiro exige projeto monetário e acesso gerencial, assim como a inclusão: **OK**.
- desinstalação remove o cron registrado pelo plugin: **OK**.
- tela de tarefa 3.3 com **Responder**, **Execuções** e **Reuniões**: estrutura Twig/JS conferida.
- filtros pessoais **Minhas/Todas** usam o usuário logado e mantêm totalizadores separados: **OK**.
- purge de `Project`/`ProjectTask` registrado no hook oficial `ITEM_PURGE`: **OK**.
- reuniões e snapshots semanais protegidos por transação: **OK**.
- lembretes por grupo expandem membros e só confirmam envio após enfileiramento: **OK**.
- atividades/estatísticas de tarefas ocultas não entram em agregações do plugin: **OK**.
- teste de integridade do ZIP: executado na etapa final do release.

## Compatibilidade conferida contra GLPI 11.0.9

A implementação foi cruzada com as classes do core 11.0.9 usadas pelo plugin, especialmente `Project`, `ProjectTask`, `ProjectTeam`, `ProjectTaskTeam`, `ProjectTaskLink`, `ProjectTask_Ticket`, `Itil_Project`, `Document`, `Document_Item`, `Contract_Item` e `ProjectCost`. A arquitetura preserva o uso das classes nativas para as mutações dos objetos principais.

## Fluxos implementados

- templates nativos;
- estado obrigatório de projeto/tarefa;
- modo tarefa direta;
- modo tarefa + chamado;
- Kanban com percentual por estado;
- equipe da tarefa -> equipe do projeto;
- prioridade e ponto de atenção;
- diário de atividades;
- horas de execução;
- reuniões e horas de reunião;
- orçamento/saldo de horas;
- cronograma semanal;
- Assistência > Minhas tarefas (Minhas tarefas / Todas visíveis);
- tela individual da tarefa no layout operacional de Chamado;
- menu interno da tarefa com Execuções, Reuniões, Subtarefas, Ativos, Documentos, Chamados, Dependências e Histórico;
- lembrete visual e e-mail;
- documentos de projeto/tarefa;
- ativos por busca;
- contrato existente;
- relatório semanal e snapshots;
- financeiro opcional com visibilidade gerencial.

## Limitação da validação local

O ambiente de build não contém a mesma instância GLPI 11.0.9 do ambiente de homologação, com banco, estados, perfis, entidades, SMTP, cron e plugins de terceiros. Portanto, a validação estática **não substitui** o teste E2E descrito em `docs/OPERATIONS.md`.
