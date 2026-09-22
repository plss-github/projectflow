# Changelog

## 3.4.1 - 2026-09-22

- Corrige carregamento de CSS e JavaScript no GLPI 11 quando o plugin está instalado via `marketplace/`.
- Passa a gerar URLs internas pelo caminho canônico `/plugins/projectflow`, conforme o GLPI 11.
- Mantém compatibilidade com URLs legadas `/marketplace/projectflow/` durante a transição.
- Renomeia os assets da release para evitar cache de navegador/proxy após a atualização.

## 3.4.0

### Tela de tarefa reconstruída no padrão do formulário de Chamado do GLPI 11
- A tela individual deixa de usar o layout de cards do Project Flow e passa a usar um workspace contínuo de três colunas.
- Menu vertical fixo à esquerda, com **Tarefa, Execuções, Reuniões, Tarefas do projeto, Itens, Documentos, Chamados, Dependências e Histórico**.
- Resumo operacional no próprio menu esquerdo com **Status, Andamento, Solicitante e Data limite**.
- Cabeçalho compacto sobre a área central/direita, com status, nome e ID da tarefa.
- Área central passa a reproduzir a conversa do chamado: cartão de abertura verde e acompanhamentos em sequência cronológica.
- Painel direito passa a usar pares **rótulo + campo** na horizontal, como o formulário ITIL nativo, em vez de formulário vertical em cards.
- Atores ficam no mesmo painel lateral, separados entre solicitante e executores.
- Barra inferior fixa mantém **Responder** e o dropdown com **Adicionar execução** e **Adicionar reunião**.
- Execuções e reuniões continuam disponíveis como seções próprias e preservam seus lançamentos existentes.

### Atualização sem reaproveitamento da interface anterior
- A tarefa passa a usar o novo template `task-native.html.twig`.
- CSS e JavaScript passam a ser carregados como `projectflow-3.4.css` e `projectflow-3.4.js`.
- A troca de nomes força o GLPI/Twig e o navegador a carregar os novos artefatos, evitando que uma atualização sobre a pasta 3.3 continue exibindo o layout antigo por cache.

## 3.3.0

### Tarefa no padrão visual do chamado do GLPI 11
- Workspace individual refinado para reproduzir a navegação operacional do formulário nativo: menu à esquerda, conversa no centro, propriedades/atores à direita e barra de ação fixa.
- A área principal passa a trabalhar como acompanhamento: descrição inicial em cartão de abertura e comentários em timeline.
- Botão principal **Responder** com menu rápido para **Adicionar execução** e **Adicionar reunião**.
- Painel lateral mantém status, prioridade, andamento, tipo, projeto, solicitante, datas, descrição, ponto de atenção, observação, lembretes e executores.
- **Execuções** e **Reuniões** passam a abrir por padrão nos lançamentos do usuário atual, com alternância para visualizar todos os lançamentos permitidos.
- Totalizadores pessoais de execução e reunião aparecem na barra operacional.

### Integridade, permissões e segurança
- Relatórios semanais deixam de incluir atividades de tarefas que o usuário não pode visualizar.
- Estatísticas por projeto também respeitam a visibilidade individual das tarefas.
- Contagem de custos deixa de ser exposta a perfis sem direito de visualizar valores financeiros.
- Exclusão de comentários e apontamentos passa a respeitar autoria/perfil também na apresentação da interface.
- Purge nativo de `Project` e `ProjectTask` limpa metadados operacionais do Project Flow para evitar registros órfãos.

### Horas, reuniões, relatórios e lembretes
- Criação, edição e remoção de reuniões passam a ser transacionais com a sincronização do respectivo apontamento de horas.
- Snapshots semanais passam a usar transação no ciclo de substituição.
- Datas semanais inválidas deixam de derrubar a geração do relatório.
- Lembretes de tarefas atribuídas a grupos expandem os usuários do grupo para destinatários.
- Lembrete somente é marcado como enviado quando pelo menos uma notificação foi efetivamente enfileirada.
- Remoção de reunião tolera registros legados sem worklog sincronizado.

## 3.2.0

### Integração com Assistência
- **Minhas tarefas** passa a ser um submenu nativo da seção **Assistência** do GLPI.
- A fila padrão mostra tarefas de projeto atribuídas ao usuário ou aos seus grupos, sem exigir navegação pelo projeto.
- A entrada operacional deixa de ocupar o menu gerencial do Project Flow.

### Tarefa no padrão operacional de Chamado
- Tela individual redesenhada em três áreas: menu da tarefa à esquerda, processamento no centro e ficha/atores à direita.
- Menu interno: **Tarefa, Horas, Reuniões, Tarefas, Ativos, Documentos, Chamados, Dependências e Histórico**.
- Contexto permanente no menu lateral com projeto, data limite e solicitante.
- Painel direito com estado, prioridade, projeto, solicitante, início, data limite, descrição, ponto de atenção, lembretes e executores.
- Processamento separado do histórico estrutural, no mesmo padrão mental de acompanhamento de chamado.

### Horas, reuniões e subtarefas
- Horas de execução ganham seção própria dentro da tarefa.
- Reuniões podem ser cadastradas diretamente no contexto da tarefa e continuam contabilizadas separadamente como horas de reunião.
- Subtarefas podem ser criadas e abertas diretamente pela seção **Tarefas**, preservando a hierarquia nativa de `ProjectTask`.

### Solicitante
- Metadado de solicitante passa a ser persistido na tarefa.
- Tarefas antigas sem solicitante explícito usam o criador nativo como fallback.
- Criação de tarefa pelo projeto passa a permitir definir **Solicitado por** separadamente do executor.

## 3.1.0

### Central de Tarefas de Projeto
- Nova entrada de menu **Tarefas de Projeto**, independente da navegação pelo projeto.
- Tela de fila operacional inspirada na listagem de chamados do GLPI, com escopo **Minhas tarefas** e **Todas as tarefas**.
- Filtros por projeto, estado, prioridade e busca textual, além de atalhos para atraso, atenção, hoje e próximos 7 dias.
- Abertura direta da tarefa sem passar pelo Kanban ou workspace do projeto.

### Tarefa em formato de atendimento
- Tela da tarefa redesenhada no padrão mental de atendimento do GLPI.
- Abas: Processamento, Horas, Documentos, Ativos, Chamados, Dependências e Histórico.
- Histórico nativo de `ProjectTask` passa a ser exibido quando o perfil possui direito de Log.
- Informações de estado, prioridade, prazo e executores ficam em painel lateral permanente.

### Criação e contabilização
- Escolha de contabilização ganhou destaque próprio na criação do projeto.
- Opções explícitas: **Contabilizar horas** ou **Custo financeiro**.
- Campo de horas previstas/contratadas aparece apenas quando o modo por horas estiver selecionado.

### Cabeçalho do projeto
- Acesso nativo “GLPI” removido do cabeçalho operacional.
- Favorito ocupa a posição final de ação no cabeçalho.
- Configurações ficam somente no ícone de engrenagem.
- Novo atalho **Contrato**, vinculado a contratos nativos do GLPI.
- Atalho de contabilização muda entre **Horas** e **Financeiro** conforme o modo do projeto.


## 3.0.0

### Redesenho do fluxo
- Project Flow passa a separar claramente a experiência de **gestor** e **executor**.
- Central de execução/Kanban permanece como visão gerencial.
- Nova tela operacional individual para tarefas.
- `Minhas tarefas` passa a ser a fila diária do técnico.
- Botão de nova tarefa removido do cabeçalho geral e mantido dentro da execução.
- Cabeçalho do projeto simplificado para favorito, configurações e acesso nativo opcional.

### Estados obrigatórios e Kanban
- Novos projetos sempre recebem estado inicial configurado.
- Novas tarefas sempre recebem estado inicial configurado.
- Tarefas clonadas de template sem estado recebem o estado inicial configurado quando possível.
- Drag-and-drop continua atualizando `projectstates_id` e `percent_done` na `ProjectTask` nativa.
- Percentual por estado configurável e estado finalizado em 100%.

### Dois modos de execução
- `direct`: tarefa executada diretamente no Project Flow, sem Ticket obrigatório.
- `ticket`: criação de tarefa exige permissão de Ticket e cria/vincula um Ticket automaticamente.
- Criação automática garante `ProjectTask_Ticket` e `Itil_Project` mesmo se um hook nativo não materializar as relações.
- Tipo e categoria do Ticket podem ser informados na criação da tarefa no modo `ticket`.

### Diário de execução
- Nova timeline de atividades por tarefa.
- Registro datado e associado ao usuário.
- Atividades alimentam o relatório semanal.

### Horas
- Novo livro de horas detalhado do Project Flow.
- Lançamentos de execução associados à tarefa.
- Horas de reunião sincronizadas automaticamente a partir das reuniões.
- Visão consolidada separando execução e reuniões.
- Novo orçamento/limite opcional de horas por projeto, com saldo, consumo percentual e excedente.
- Financeiro passa a ser opcional e separado do fluxo principal de horas.

### Reuniões
- Nova entidade operacional própria de reuniões/alinhamentos; reunião não é tarefa.
- Cadastro, edição e remoção de reuniões.
- Participantes, resumo, decisões e pendências.
- Duração da reunião alimenta automaticamente as horas do projeto.

### Sprints e cronograma
- Cronograma simplificado por sprints semanais.
- Datas e equipes continuam no `ProjectTask` nativo, preservando integração com o Planejamento do GLPI.

### Relatório semanal
- Nova aba com seleção de semana.
- Geração determinística a partir de tarefas, atividades, atenção, reuniões e horas.
- Inclusão de próximos passos.
- Inclusão de consumo/saldo do orçamento de horas quando configurado.
- Copiar relatório e salvar snapshots por semana.

### Tarefas
- Prioridade própria sem duplicar `ProjectTask`.
- Ponto de atenção separado de prioridade.
- Lembrete no Project Flow e opção de e-mail.
- Cron horário `taskreminders` para e-mail.
- Equipe da tarefa sincronizável com a equipe do projeto.
- Documentos da tarefa.
- Dependências nativas.
- Vínculos com ativos.
- Busca de ativos por nome ou ID, em vez de exigir digitação manual do ID.

### Contratos e documentos
- Contrato do cliente separado dos documentos gerais.
- Vínculo de `Contract` existente via `Contract_Item`.
- Documentos seguem nativos via `Document` + `Document_Item`.

### Portfólio
- Pontos de atenção das tarefas passam a influenciar a saúde automática do projeto.
- Quantidade de pontos de atenção visível nos cards.
- Mantidos favoritos, prioridade, saúde, risco, patrocinador, portfólio e templates.

### Correções e robustez
- Totais de horas do projeto passam a considerar todos os lançamentos, sem herdar o limite de paginação/listagem da interface.
- Inclusão e remoção de custo financeiro usam a mesma regra de acesso gerencial e somente operam em projetos no modo monetário.
- Desinstalação remove também a tarefa cron registrada pelo Project Flow.
- Corrigido bloco indevido de metadados de tarefa dentro da normalização de Tickets.
- Reforçado o vínculo explícito Ticket x tarefa no modo automático.
- Lembrete visual respeita o canal `Project Flow` configurado na tarefa.
- Reuniões agora podem ser editadas sem recriação.
- Mantida a regra de nenhuma escrita SQL direta em `glpi_projects`/`glpi_projecttasks`.
- Compatibilidade declarada exclusivamente com GLPI 11.x e PHP 8.2+.

## 2.0.0
- Workspace operacional, Kanban com progresso, prioridade de tarefas, equipe, tickets, documentos, custos, dependências, histórico e portfólio responsivo.

## 1.0.0
- Primeira versão do workspace visual sobre projetos nativos do GLPI.

## 0.1.0
- Protótipo inicial.
