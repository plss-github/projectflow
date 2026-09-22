# Project Flow 3.4.0 para GLPI 11

Project Flow é uma camada operacional de gestão de projetos para **GLPI 11.x**, desenhada para reduzir a complexidade do módulo nativo sem substituir seus objetos principais. Projeto, tarefa, equipe, estados, planejamento, chamados, documentos, contratos e permissões continuam integrados ao GLPI; o plugin acrescenta a experiência de trabalho que faltava para gestores e executores.

A linha 3.x foi redesenhada a partir do fluxo real de trabalho da equipe: **projeto -> execução -> técnico -> horas -> reuniões -> relatório semanal**.

## O que existe nesta versão

### Portfólio e criação de projetos
- Dashboard responsivo em cards ou tabela.
- Busca e filtros por estado, saúde e portfólio.
- Prioridade do projeto.
- Favoritos por usuário.
- Saúde automática ou manual, risco, patrocinador, portfólio e objetivo.
- Criação de projeto do zero ou a partir de **template nativo do GLPI**.
- Estado inicial obrigatório para novos projetos.
- Modo de execução escolhido por projeto:
  - **Tarefa direta**: a tarefa é executada no próprio Project Flow, sem gerar Ticket.
  - **Tarefa + chamado**: ao criar a tarefa, o plugin também cria e vincula um Ticket.
- Modo de contabilização escolhido explicitamente por projeto:
  - **Horas**.
  - **Custo financeiro**.
- Orçamento/limite opcional de horas do projeto, com consumo e saldo.

### Central de execução
- Kanban sobre `ProjectState` e `ProjectTask` nativos.
- Toda nova tarefa nasce em um estado configurado; não é criada como “Sem estado”.
- Drag-and-drop atualiza estado e percentual da própria `ProjectTask`.
- Percentual por estado configurável.
- Estado finalizado resulta em 100%.
- Lista simples das tarefas abaixo do Kanban.
- Prioridade, prazo, executores, ponto de atenção e lembrete no card.
- Botão **Nova tarefa** somente dentro da área de execução.

### Assistência > Minhas tarefas e tela operacional
O técnico não precisa entrar no projeto para executar o trabalho. O plugin adiciona **Minhas tarefas** diretamente ao menu **Assistência** do GLPI. Essa fila exibe as tarefas de projeto atribuídas ao usuário ou aos seus grupos e permite alternar entre **Minhas tarefas** e **Todas as visíveis**.

Ao abrir uma tarefa, a tela segue a organização do formulário de chamado do GLPI: menu operacional à esquerda, processamento no centro e ficha/atores à direita. O menu interno contém **Tarefa, Execuções, Reuniões, Tarefas (subtarefas), Ativos, Documentos, Chamados, Dependências e Histórico**. A tela 3.4 usa um workspace dedicado inspirado diretamente no formulário nativo de chamados do GLPI 11: navegação vertical fixa à esquerda, conversa no centro, propriedades em linhas à direita e barra inferior **Responder** com menu rápido para comentário, execução e reunião. Os assets e o template da tarefa são versionados para evitar reaproveitamento de cache da interface anterior:

- diário de atividades;
- lançamento de horas de execução;
- estado e prioridade;
- datas planejadas;
- ponto de atenção e observação;
- lembrete no Project Flow e por e-mail;
- responsáveis/equipe;
- documentos;
- ativos relacionados;
- dependências;
- chamados vinculados;
- abertura de chamado quando necessário;
- histórico nativo do GLPI integrado na própria tela;
- reuniões ligadas à tarefa, contabilizadas separadamente das horas de execução;
- subtarefas nativas da própria `ProjectTask`;
- solicitante da tarefa separado dos executores.

### Fila diária do técnico
Dentro de **Assistência > Minhas tarefas**, o escopo padrão é a fila dedicada ao executor, baseada nas tarefas ativas nativas do GLPI para o usuário e seus grupos:

- atrasadas;
- vencendo hoje;
- próximos 7 dias;
- sem prazo;
- pontos de atenção;
- lembretes;
- estado, progresso e prioridade.

### Equipe sem cadastro duplicado
A equipe da tarefa usa `ProjectTaskTeam`. Ao incluir um usuário, grupo, fornecedor ou contato em uma tarefa, o Project Flow pode incluí-lo automaticamente em `ProjectTeam`.

### Horas
O controle principal de esforço é por tempo, não por dinheiro:

- horas de **execução** são lançadas na tarefa;
- horas de **reunião** são geradas a partir do registro da reunião;
- o projeto consolida execução, reuniões e total;
- quando existe orçamento de horas, exibe consumido, saldo ou excedente;
- no modo monetário, informações financeiras ficam separadas e são mostradas apenas a usuários gerenciais autorizados.

### Reuniões / alinhamentos
Reunião não é tarefa. Existe uma área própria para:

- registrar e editar reunião;
- data/hora;
- duração;
- participantes;
- resumo;
- decisões;
- pendências/ações;
- contabilização automática das horas como reunião.

### Sprints e cronograma
- Cronograma simplificado em sprints semanais.
- Tarefas agrupadas pela semana das datas planejadas.
- Tarefas continuam usando `ProjectTask` nativo e suas datas/equipe, preservando a integração com o **Planejamento do GLPI**.

### Relatório semanal
A aba **Relatório semanal** consolida uma semana selecionada e gera texto pronto para revisão/cópia, usando:

- tarefas concluídas;
- tarefas atualizadas;
- atividades registradas;
- pontos de atenção;
- próximos passos;
- reuniões;
- horas da semana;
- consumo acumulado do orçamento de horas, quando configurado.

Versões do relatório podem ser salvas como snapshots no Project Flow. A versão 3.3 não depende de IA externa; uma futura integração com cloud/IA pode substituir ou complementar o gerador determinístico sem alterar o modelo de dados.

### Contrato x documentos
- **Contrato**: vínculo com um `Contract` já existente no GLPI.
- **Documentos**: arquivos normais do projeto via `Document` + `Document_Item`.
- Criação de um novo contrato diretamente no Project Flow ficou deliberadamente fora desta versão.

### Pontos de atenção e lembretes
- Tarefa pode ser marcada como ponto de atenção independentemente da prioridade.
- Pontos de atenção aparecem no projeto, portfólio e relatório semanal.
- Lembretes aparecem no Project Flow.
- Se habilitado, o cron `taskreminders` enfileira e-mails para os executores da tarefa.

## Objetos nativos utilizados

| Domínio | Fonte de verdade |
|---|---|
| Projeto | `Project` |
| Tarefa | `ProjectTask` |
| Equipe do projeto | `ProjectTeam` |
| Equipe da tarefa | `ProjectTaskTeam` |
| Estado | `ProjectState` |
| Planejamento | `ProjectTask` / Planning nativo |
| Dependências | `ProjectTaskLink` |
| Ticket x tarefa | `ProjectTask_Ticket` |
| ITSM x projeto | `Itil_Project` |
| Documento | `Document` + `Document_Item` |
| Contrato | `Contract` + `Contract_Item` |
| Financeiro opcional | `ProjectCost` |

O plugin **não faz INSERT/UPDATE direto em `glpi_projects` ou `glpi_projecttasks`**. Alterações nesses registros passam pelas classes do GLPI.

## Dados próprios do Project Flow

Somente o que não possui uma estrutura nativa adequada é persistido em tabelas do plugin:

- configurações globais;
- saúde/risco/portfólio/patrocinador/modos/orçamento de horas do projeto;
- prioridade, atenção e lembretes da tarefa;
- percentual por estado do Kanban;
- favoritos;
- diário de atividades;
- lançamentos detalhados de horas;
- reuniões;
- ativos vinculados à tarefa;
- snapshots de relatório semanal.

Desativar o plugin não exclui os projetos/tarefas nativos. **Desinstalar** remove as tabelas próprias do Project Flow.

## Instalação

1. Faça backup do banco e da pasta de plugins.
2. Extraia o ZIP em `GLPI_ROOT/plugins/`.
3. Confirme que existe `GLPI_ROOT/plugins/projectflow/setup.php`.
4. Acesse **Configurar -> Plugins**.
5. Instale/atualize **Project Flow**.
6. Ative o plugin.
7. Acesse **Ferramentas -> Project Flow**.
8. Em **Configurações**, revise obrigatoriamente:
   - estado inicial de projeto;
   - estado inicial de tarefa;
   - percentual de cada estado do Kanban;
   - modo de execução padrão;
   - modo de controle padrão;
   - lembretes por e-mail.

## Atualizando uma versão anterior

1. Faça backup do banco e de `plugins/projectflow`.
2. Desative o plugin, mas **não desinstale**.
3. Substitua integralmente a pasta antiga pela nova pasta `projectflow`.
4. Volte a **Configurar -> Plugins** e execute a atualização.
5. Ative o plugin.
6. Limpe o cache do GLPI/navegador caso ainda apareçam assets antigos.
7. Revise as configurações do Project Flow.

A instalação/atualização cria apenas estruturas/campos próprios ausentes e preserva os objetos nativos existentes.

## Compatibilidade declarada

- GLPI 11.0.x, com foco de homologação em 11.0.9.
- PHP 8.2+.
- Não há compromisso de compatibilidade com GLPI 10 nesta linha.

## Fora do escopo desta versão

- banco de horas de **sustentação**;
- criação de contrato novo pelo Project Flow;
- geração de relatório por IA/cloud;
- suporte a GLPI 10.

## Homologação

O pacote inclui validações estáticas, mas precisa ser homologado em uma instância GLPI 11 representativa antes de produção, principalmente por causa de perfis, entidades, estados, SMTP/cron e plugins de terceiros.

Veja:

- `docs/ARCHITECTURE.md`
- `docs/OPERATIONS.md`
- `docs/VALIDATION.md`

## Licença

GPL-3.0-or-later.
