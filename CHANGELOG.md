# Changelog

## 3.5.1 - 2026-10-01

### Revisão visual
- Portfólio: o filtro de portfólio não some mais em telas abaixo de 1400px; rótulos dos indicadores (ex.: "Minhas tarefas") quebram linha em vez de serem cortados.
- Kanban de execução rola dentro do painel em vez de alargar a página inteira.
- Resumo: bloco Governança ganhou estilo (estava sem formatação).
- Modais do plugin (Funções, Contrato, Novo template etc.): o cabeçalho não corta mais o sobretítulo; modais largos usam a largura da tela em tablets e notebooks pequenos (campos de Configurações do projeto e Nova tarefa deixam de ficar espremidos).
- Popup da tarefa: barra de ações (Responder/Salvar) não fica mais cortada no rodapé; o ícone de erro do campo de data não cobre mais o botão do calendário; nas abas roláveis (projeto, popup, passos do template) a aba ativa é trazida para a área visível.
- Reuniões: mês exibido em português (set, out...) em vez de inglês.
- Relatório semanal: botões da barra quebram linha em telas estreitas em vez de se sobreporem.
- Configurações: chaves "Finalizado"/"Pausa o projeto" não se sobrepõem; o sufixo % das regras e o botão "Testar" da IA não vazam mais do campo.
- Cronograma: o período nos indicadores quebra linha em vez de ser cortado.

### Cronograma
- Aba Cronograma redesenhada: indicadores com ícones (período, tarefas no cronograma, concluídas, atrasadas, próxima entrega); Gantt com escala automática (visão diária com dia da semana e fins de semana sombreados em projetos curtos, visão semanal nos longos), cabeçalho de meses, janela alinhada às semanas e estendida até hoje, coluna e linha "Hoje", tarefas em árvore (pai em negrito, filhas recuadas), barras com o realizado preenchido e rótulo de % e datas, atraso como faixa hachurada até hoje com selo "Nd atraso", marcos em losango e duração por tarefa; rolagem horizontal com a coluna de tarefas fixa. Abaixo, agenda por semana em lista (data, estado, %) e o painel "Sem planejamento" ao lado.

### Funções da equipe
- As funções agora são de cada projeto (e de cada template): cada um tem o seu próprio cadastro. Ao criar um projeto a partir de um template, as funções do template são copiadas e cada membro mantém a sua. Funções de builds anteriores (globais) são copiadas automaticamente para os projetos que as usavam.
- Criação de template ganhou a aba "Funções" para já definir as funções da equipe (nome e cor). No template já criado, elas são gerenciadas na aba Equipe.
- Aba Equipe redesenhada: cabeçalho com totais (membros, funções) e "Gerenciar funções"; formulário "Adicionar à equipe" com rótulos; membros agrupados por função (cor da função no grupo, no card e no avatar com iniciais); cada card mostra tipo, tarefas que a pessoa executa no projeto, horas lançadas e o selo "Responsável" do projeto.
- Aba Equipe: botão "Funções" abre o cadastro de funções (nome, cor, descrição; editar e excluir) e cada membro ganha a sua função no projeto, escolhida no próprio card ou já ao adicionar o membro. Resumo da equipe por função e faixa de cor no card.
- Projetos criados de um template herdam a função de cada membro. Novas tabelas `glpi_plugin_projectflow_roles` e `glpi_plugin_projectflow_teamroles` (criadas automaticamente).

### Subtarefas em árvore
- Na lista da Execução (e na escolha da tarefa pai), as subtarefas aparecem logo abaixo da tarefa pai, recuadas em escadinha por nível, com o ícone ↳; tarefas que têm filhas ganham um selo.

### Tarefa sempre em popup
- A tela cheia da tarefa deixou de existir: qualquer link para uma tarefa (Kanban, lista, resumo, cronograma, horas, Minhas tarefas) abre o popup. Acessar `front/task.php?id=X` direto (favorito, link antigo, notificação) leva ao projeto da tarefa com o popup aberto. Depois de criar uma tarefa, o projeto recarrega com ela aberta no popup. Removido o botão "Tela cheia" do popup.

### Cabeçalho do projeto
- Removido o botão "Horas e custos" do topo do projeto (a aba continua).
- "Nova tarefa" virou menu: Tarefa pai ou Tarefa filha (subtarefa). A filha usa o mesmo formulário com o campo obrigatório "Tarefa pai", listando só tarefas do próprio projeto (o servidor também valida).

## 3.5.0 - 2026-09-29

### Atualização
- Ao copiar esta versão para `plugins/projectflow`, vá em Configurar > Plugins, clique em **Atualizar** e depois em **Ativar**. A atualização cria as colunas novas (`hour_rate`, `allowed_states`, `is_paused`) e grava nome, autor e licença.
- CSS/JS renomeados para `projectflow-3.5.0.css` / `projectflow-3.5.0.js`.

### Nome
- Tela de plugins do GLPI: nome "Pellissari Project", autor "Kawan Costa" e licença proprietária (uso interno Pellissari e clientes autorizados). Pasta, chave `projectflow`, tabelas e URLs continuam iguais. Os dados são gravados em `glpi_plugins` uma vez, sem precisar reinstalar.
- Licença alterada de GPL-3.0 para proprietária (`LICENSE`, `composer.json`, README).

### Planejamento do GLPI
- Tarefas passam a aparecer no Planejamento nativo do GLPI (Assistência > Planejamento) para o executor: o GLPI só mostra tarefa de projeto com início **e** fim planejados. Quando a tarefa tem só o prazo (ou só o início), a outra data é completada automaticamente a partir das horas planejadas (1h se não houver), em qualquer tela (Project Flow, Kanban ou formulário nativo).
- Correção única das tarefas já existentes com só uma das datas (executada uma vez, flag `planning_dates_repaired`).

### Contrato do projeto
- Botão "Contrato" do projeto: sem contrato vinculado vira "Vincular contrato" (abre a vinculação); com contrato vinculado abre um popup que mostra o arquivo anexado ao contrato, sem baixar: PDF (visualizador do navegador), imagens, vídeo/áudio, texto, Word .docx (mammoth) e planilhas .xlsx/.xls/.ods/.csv (SheetJS). Outros formatos: aviso e "Abrir em nova aba". Vários arquivos/contratos aparecem em abas.
- No popup: Abrir contrato no GLPI, Abrir em nova aba, Desvincular contrato (para vincular outro depois) e Fechar.
- Novo endpoint `ajax/contract_file.php`: entrega o arquivo inline só se o usuário vê o projeto, o contrato está vinculado a ele e o documento está anexado a esse contrato; conteúdo ativo (HTML/SVG/XML/JS) nunca é executado (texto puro ou `<img>`, CSP sandbox). Bibliotecas em `public/lib` (licenças em THIRD-PARTY-LICENSES.txt).

### Tela de templates
- Redesenhada: cabeçalho com explicação e totais (templates e tarefas padrão), busca, e cards com faixa de cor pela contabilização, código, tipo, descrição (objetivo), nº de tarefas e horas planejadas, contabilização/horas previstas, modo de execução e responsável. Card "Novo template" no fim da grade e menu ⋮ com Editar / Abrir no GLPI.
- "Criar projeto" no card abre o "Novo projeto" do painel já com o template aplicado (autocomplete + escolha de tarefas), em vez do formulário simples antigo.

### Estado que pausa o projeto
- Estados ganharam a opção "Pausa o projeto" (Configurações → Estados), como a de "Finalizado" (as duas são exclusivas). Enquanto o projeto estiver num estado assim: lembretes por e-mail das tarefas ficam retidos (saem quando o projeto voltar), as regras por andamento não enviam avisos, as notificações nativas do GLPI do projeto e das tarefas não são disparadas, e o projeto não conta como atrasado nem piora a saúde automática.
- Selo "Pausado" no cabeçalho do projeto e no card do painel; selo "Pausa o projeto" na lista de estados. Nova coluna `is_paused` em `glpi_plugin_projectflow_stateprogress` (criada automaticamente).

### Criar projeto / template
- Template como autocomplete: ao escolher um template no "Novo projeto" (no campo ou pelo menu do botão), todas as abas são preenchidas com as definições dele (tipo, estado, prioridade, execução, responsável, grupo, datas, descrição, objetivo, portfólio, patrocinador, saúde, risco, contabilização, horas previstas, valor/hora…). Tudo continua editável antes de criar. Ação `template_info`.
- Nova aba Tarefas no "Novo projeto": lista as tarefas do template (com hierarquia, tipo, marco e horas previstas), todas marcadas por padrão, com Marcar/Desmarcar todas. As desmarcadas não são criadas no projeto; subtarefas de uma tarefa desmarcada continuam e sobem para o nível acima.
- Formulários de criação de projeto e de template reorganizados em abas numeradas, no estilo da tela de Configurações: Identificação, Planejamento, Governança, Contabilização (e Tarefas, no template). Botões Voltar/Próximo, etapas já vistas ficam marcadas, e "Criar" funciona de qualquer aba. Se faltar um campo obrigatório, a aba certa é aberta automaticamente.

### Minhas tarefas
- Indicadores da fila (Exibidas, Em atraso, Atenção, Vencem hoje, Próx. 7 dias) redesenhados no mesmo padrão do painel: cards com ícone, rótulo, número, cor por tipo e destaque do filtro ativo.

### Reunião na tarefa
- Formulário "Adicionar execução" em grade fixa (Data, Horas, Minutos, Descrição): a data não sobrepõe mais as horas.
- Lançar execução e registrar reunião agora só pela barra de ações da tarefa. As abas Execuções e Reuniões ficaram só para editar/remover: o formulário aparece ao clicar em editar e some ao salvar/cancelar.
- Novo: edição de lançamento de execução (data, duração e descrição), com as mesmas regras da remoção (o próprio autor ou administrador). Ação `worklog_update`.
- O formulário "Adicionar reunião" da tarefa ganhou os campos que faltavam (iguais aos da reunião do projeto): Participantes, Decisões e Pendências / ações; Resumo virou texto de várias linhas. Layout em grade fixa: Data e hora não sobrepõe mais Horas/Minutos.
- A lista de reuniões da tarefa mostra também Decisões e Pendências.

### Horas + custo
- Corrigido: os novos cards da aba de horas usavam a classe `pf-kpi`, a mesma dos indicadores do painel e de Minhas tarefas, e desalinhavam esses indicadores (ícone/rótulo/número centralizados em coluna). Os cards de horas passaram a usar `pf-hk-*`.
- Indicadores do painel redesenhados: ícone à esquerda, rótulo em caixa alta e número à direita, alinhados à esquerda, com faixa de cor por tipo (ativos verde, atraso vermelho, atenção laranja, favoritos dourado, minhas tarefas azul) e destaque do filtro selecionado.
- Resumo do cabeçalho do projeto (Responsável, Prazo, Progresso, Horas, Custo, Modo) em uma linha só, com colunas iguais, mesmo com o bloco de custo.
- Indicadores da aba de horas refeitos: dois grupos (Horas e Financeiro) de cards iguais, com rótulo, valor e legenda em uma linha, barra de consumo no saldo e valores em formato brasileiro (R$ 1.234,56).
- Novo modo de contabilização "Horas + custo" (além de "Horas" e "Custo financeiro"): o projeto tem horas previstas e valor por hora. Cada hora lançada (execução e reuniões) é valorizada automaticamente; o custo total = valor das horas + custos avulsos.
- Aba de horas mostra valor das horas, valor previsto (horas previstas × valor/hora), custo total, valor por tarefa e por lançamento (somente para a gestão do projeto).
- Custos avulsos: lista com data, descrição e valor, lançamento com data e remoção (também no modo Custo financeiro).
- Valor por hora configurável na criação do projeto, do template, ao criar a partir de template (vazio = do template) e nas configurações do projeto. Nova coluna `hour_rate` em `glpi_plugin_projectflow_projectmeta` (criada automaticamente).

### Execução do projeto
- Botão "Novo projeto" do painel virou um menu único: em qualquer ponto do botão abrem as opções Projeto em branco, os templates disponíveis, Criar template (abre direto o formulário na tela de templates) e Gerenciar templates.
- Estados permitidos por tarefa: cada tarefa pode limitar para quais estados pode ir (na criação da tarefa, no painel da tarefa e em cada tarefa do template). A troca de estado da tarefa só oferece os estados marcados, o Kanban bloqueia soltar o card numa coluna não liberada (colunas esmaecidas durante o arraste) e o servidor recusa estados fora da lista. O estado atual da tarefa sempre fica liberado. Todos marcados = sem restrição.
- O executor da tarefa (usuário da equipe da tarefa, direto ou via grupo) nunca altera os estados permitidos: no painel da tarefa ele vê a lista só para leitura e o servidor recusa a alteração (inclusive na criação, quando ele se coloca como executor). Quem define é quem pode editar o projeto; o responsável pelo projeto pode mesmo sendo executor.
- Projetos criados a partir de um template (ou templates copiados) agora herdam os dados do plugin de cada tarefa: estados permitidos, prioridade, solicitante e ponto de atenção.
- Nova coluna `allowed_states` em `glpi_plugin_projectflow_taskmeta` (criada automaticamente, sem reinstalar).
- A aba Execução ganhou alternância Kanban / Lista (a escolha fica lembrada no navegador); a lista deixou de aparecer sempre abaixo do quadro.
- A busca filtra tanto os cards quanto as linhas da lista.
- Removido o botão "Nova tarefa" duplicado da barra da execução (permanece o do cabeçalho).

### Campos mais compactos
- Revisão de todos os campos de texto e seleção do plugin: altura única de 34 px (30 px nos pequenos), texto de 13 px, rótulos menores e espaçamento vertical reduzido. Isso vale para portfólio, projeto, tarefa (inclusive o popup), templates, Minhas tarefas, Configurações e todos os modais.
- Textareas com altura mínima menor e redimensionáveis. Filtros, buscas, campos de cor e campos numéricos ganharam largura máxima, e nas Configurações os campos param de ocupar a linha inteira.
- Ajustes feitos na revisão tela a tela: o topo dos modais longos não fica mais cortado; as linhas de tarefa do template cabem no modal, sem rolagem lateral; os botões de editar e excluir ficam lado a lado nas listas de Estados e Tipos; a filtragem de Minhas tarefas e do portfólio fica em uma linha que quebra de forma organizada; o campo do relatório semanal mantém o tamanho de documento; e a aba de IA ficou alinhada.
- Campos numéricos (%, horas, minutos, limites, ID de chamado) ficam estreitos, com 110 a 140 px, número alinhado à direita e algarismos de largura fixa, em vez de ocupar a coluna inteira. Os grupos com sufixo ("%", "dias") encolhem junto.
- Cada campo tem a largura do conteúdo que recebe: seleções de lista (estado, prioridade, tipo, modo, responsável, grupo, entidade) até 240 px; listas com nomes longos (tarefa, projeto, template, contrato) até 360 px; datas 200 px; código 160 px; portfólio, patrocinador e modelo de IA até 240 px; cor 56 px. Só nome, título, descrição, observação e busca ocupam a coluna inteira.
- Formulários de lançamento (execução, reunião, dependência, chamado): os campos deixam de ter largura mínima fixa, horas e minutos têm 80 px e a descrição preenche o resto. As mensagens de lista vazia ocupam a linha toda, em vez de quebrar palavra por palavra.
- Botões e grupos de campo ("%", "dias") alinhados à mesma altura dos campos.

### Configurações em abas
- O cabeçalho grande da tela de Configurações deu lugar a um menu de abas clicáveis: **Geral**, **Estados**, **Tipos**, **Regras por andamento** e **Inteligência artificial**. A aba escolhida fica no endereço (`#states`, `#ai`...) e é lembrada na próxima visita.
- A barra "Salvar configurações" só aparece nas abas Geral e Inteligência artificial. As outras abas salvam cada item na hora.
- Saiu o bloco "Percentual por estado" (e o exemplo de fluxo), que duplicava o campo "% no Kanban" da aba Estados.

### Tela da tarefa
- O botão **Salvar** do painel direito fica abaixo de **Atores** e continua fixo no rodapé do painel durante a rolagem. Ele é associado ao formulário pelo atributo `form`.

### Minhas tarefas: concluídas
- Corrige "Incluir concluídas" em **Minhas tarefas**, que não mostrava nenhuma tarefa concluída. A fila usava `ProjectTask::getActiveProjectTaskIDsForUser()`, que só devolve tarefas abertas. Agora o plugin identifica as tarefas do usuário pela equipe da tarefa: o próprio usuário ou um dos seus grupos.
- O botão virou **Mostrar concluídas** / **Ocultar concluídas**, com texto e destaque quando está ativo.

### Andamento pelo estado
- O percentual da tarefa deixa de ser editável: ele sempre segue o percentual configurado para o estado (estado finalizado = 100%). A regra vale no Project Flow, no Kanban, nas ações em massa e no formulário nativo do GLPI (hooks `pre_item_add`/`pre_item_update` de `ProjectTask`). O progresso automático nativo da tarefa fica desligado.
- Na tela da tarefa, o campo **Andamento** do painel direito virou uma barra somente leitura, com o aviso "Definido pelo estado". O bloco de resumo do canto inferior esquerdo (status, andamento, solicitante e data limite) foi removido, porque duplicava os campos do painel direito.
- Em Configurações, a opção "Progresso automático pelo Kanban" passa a ser fixa: o andamento sempre acompanha o estado.

### Minhas tarefas
- Clicar em uma tarefa (linha, ID, nome ou seta) abre a tela de execução em um **popup** grande, sem sair da fila. Ctrl/Cmd+clique ou o botão do meio continuam abrindo em nova aba, e o popup tem o botão **Tela cheia**.
- A tela da tarefa ganhou o modo `?embed=1` (sem menu do GLPI). Dentro do popup, outras tarefas abrem no próprio popup e os links de projeto, chamados e ativos abrem na janela principal.
- Ao fechar o popup depois de alguma alteração, a fila é recarregada para refletir estado, andamento e prazos.

### Relatório com IA (Gemini)
- Nova seção **Inteligência artificial** nas Configurações, com ativação, chave da API do Gemini (criptografada com GLPIKey e nunca devolvida ao navegador), modelo (padrão `gemini-3.8-flash`), botão **Testar**, que valida a chave e lista os modelos disponíveis, e instruções adicionais da empresa.
- A aba **Relatório semanal** ganha o botão **Gerar com IA**, com escopo "semana selecionada" ou "projeto inteiro". Em **uma única requisição** `generateContent` vão o projeto, as horas (período, acumulado, previsto e saldo), as reuniões do projeto e todas as tarefas, com comentários, execuções, reuniões, prazos, pontos de atenção e executores.
- O texto volta para a mesma área do relatório, com modelo e tokens usados, e pode ser editado e salvo como versão. O gerador sem IA continua disponível.
- O prompt proíbe inventar dados e segue a estrutura do relatório do Project Flow. As chamadas usam o proxy configurado no GLPI.
- Resiliência: quando o Gemini responde 429/500/502/503/504, a chamada é refeita até 3 vezes (espera de 2s e 6s, respeitando `Retry-After`). Se o modelo continuar sobrecarregado, o **modelo reserva** configurado é usado uma vez (padrão `gemini-3.5-flash`). As mensagens de erro aparecem em português.
- Só quem pode editar o projeto gera com IA, para controlar o custo.

### Documentos
- Um documento anexado a uma tarefa passa a ser anexado também ao projeto dela. É o mesmo documento nativo, com um vínculo `Document_Item` a mais e sem cópia do arquivo. Vale para o Project Flow e para o formulário nativo do GLPI, pelo hook `item_add`.
- Em **Arquivos do projeto**, cada documento mostra a origem ("Tarefa: #12 Nome", com link, ou "Anexado no projeto") e ganhou botão de download.
- Documentos anexados a tarefas antes desta versão também aparecem no projeto, identificados pela tarefa de origem.
- Desvincular um documento do projeto não o remove da tarefa de origem.

### Horas por tarefa
- O botão **Nova tarefa** também aparece no cabeçalho do projeto, em qualquer aba. Antes ele só existia dentro da aba Execução.
- A aba **Horas** ganhou a tabela "Horas dedicadas em cada tarefa", com execução, reuniões, total, previsto e barra de consumo (vermelha acima de 100%), além do total das tarefas no rodapé. O total do projeto continua incluindo as reuniões sem tarefa.
- Os cards do Kanban e a lista de tarefas mostram as horas gastas e, quando houver previsão, horas gastas/previstas.
- As horas da tarefa (execução e reuniões) passam a ser copiadas para a `effective_duration` nativa da `ProjectTask` a cada lançamento, edição ou remoção, e aparecem também nas telas nativas do GLPI.

## 3.4.3 - 2026-09-23

### Correções
- **Minhas tarefas / Todas as visíveis**: o limite de linhas passa a ser aplicado depois dos filtros (minhas, finalizadas, permissão) e a fila não depende mais do limite de 1000 projetos do portfólio. Tarefas de projetos antigos voltam a aparecer.
- **Cron `taskreminders`**: nova coluna `reminder_attempts`. Lembretes que falham descem na fila e são abandonados (com log) após 24 tentativas, sem bloquear lembretes novos. Usuários inativos ou excluídos não são mais notificados.
- **Horas**: a exclusão de lançamento exige a tarefa e aceita só lançamentos de execução. As horas de reunião só saem junto com a reunião, preservando a sincronia 1:1.
- **Busca de ativos**: filtro de entidade, lixeira e template aplicado no SQL, com paginação até preencher o limite. Sem consulta extra por item.
- **Desvincular ativo** retorna falha quando o vínculo não existe.
- **Saúde automática**: projeto finalizado não é mais marcado como crítico por tarefas atrasadas.
- **Solicitante da tarefa** validado também na criação (antes só na edição).

### Desempenho
- Cache por requisição de estados e tipos de tarefa, e de nomes de usuário na normalização das tarefas.
- A tela da tarefa carrega só o contexto do projeto (nome, link, entidade), não mais o workspace completo, e lista as tarefas do projeto uma única vez.
- O relatório semanal é gerado sob demanda, ao abrir a aba ou clicar em **Gerar**, e não a cada abertura do projeto.
- As estatísticas de tarefas do projeto não são mais calculadas duas vezes.

### Menu
- **Ferramentas > Projetos** passa a abrir o portfólio do Project Flow, e a entrada separada "Project Flow" no menu Ferramentas deixa de existir. As telas do plugin aparecem com o breadcrumb Ferramentas > Projetos.
- Acessar `/front/project.php` sem parâmetros redireciona para o Project Flow. A lista nativa, com busca avançada e ações em massa, continua disponível pelo botão **Lista nativa** do portfólio.
- A entrada **Assistência > Minhas tarefas** foi removida. As tarefas passam a ser acessadas só pelo botão **Minhas tarefas** do portfólio de projetos, e as telas de tarefa aparecem sob Ferramentas > Projetos.
- Nova opção em Configurações, "Abrir o Project Flow em Ferramentas > Projetos", ligada por padrão. Desligada, o comportamento anterior volta.

### Portfólio
- O cabeçalho grande do portfólio (título, descrição e quatro botões) foi substituído por uma barra de ações compacta: **Minhas tarefas**, **Lista nativa** e **Novo projeto**.
- A barra de ações ganhou fundo, espaçamento e altura uniforme. Os assets passam a levar um carimbo de conteúdo na URL (`?h=`), então o navegador pega CSS/JS novos sem Ctrl+F5, mesmo dentro da mesma versão.
- **Novo projeto** virou um botão dividido. O menu tem "Projeto em branco", a lista de templates nativos (cada um abre a criação com o template já selecionado) e "Gerenciar templates".

### Templates
- A tela **Gerenciar templates** agora cria templates. **Novo template** tem os mesmos campos da criação de projeto (identificação, planejamento e governança, contabilização) e pode partir do zero, copiar um projeto existente (tarefas, equipe e relações) ou duplicar outro template.
- Os modais de criação (projeto e template) passam a rolar por dentro, com cabeçalho e rodapé fixos. Antes, formulários longos ficavam cortados sem barra de rolagem.
- As tarefas do template podem ser cadastradas no próprio formulário: nome, tipo, horas previstas, executor padrão, marco e descrição, com quantas linhas forem necessárias (Enter adiciona outra).
- Tarefas guardadas em um template de projeto do tipo "Tarefa + chamado" não abrem chamado. Os chamados só são criados em projetos reais.
- `ProjectService::create()` passa a atender projetos e templates (`createTemplate()` delega para ele). Ao copiar uma origem, os metadados do Project Flow dela (modos, horas previstas, portfólio...) são herdados quando o campo fica em branco.
- Depois de criado, o template abre no workspace do Project Flow para receber tarefas, com o selo "Template" e o caminho de volta para Templates.
- Os cards de template mostram a quantidade de tarefas e as ações **Usar**, **Editar** e o formulário nativo.

### Configurações
- **Estados de projetos e tarefas:** criar, editar e excluir estados (nome, cor, finalizado, % no Kanban e comentário). Projetos e tarefas compartilham a lista de estados, como no GLPI. Um estado só pode ser excluído quando nenhum projeto ou tarefa o usa, quando não é o estado inicial configurado e quando não é destino de uma regra.
- **Tipos de projeto** e **tipos de tarefa:** criar, editar e excluir pela própria tela, usando os dropdowns nativos `ProjectType` e `ProjectTaskType`. A exclusão é bloqueada enquanto o tipo estiver em uso.
- **Regras por andamento** (nova tabela `glpi_plugin_projectflow_progressrules`): cada regra tem uma faixa de percentual, um estado de destino opcional e uma notificação opcional por e-mail (solicitante, gestor do projeto, executores da tarefa e/ou um grupo), além de ordem e ativação.
  - Ao mudar o andamento, a primeira regra ativa que contém o novo percentual define o estado.
  - Quando o estado é alterado junto com o andamento (por exemplo, pelo Kanban), a regra não troca o estado, só notifica.
  - A notificação é enviada ao entrar na faixa e não vai para quem fez a alteração.
  - As regras valem para qualquer tela (Project Flow, Kanban ou formulário nativo), pelo hook `item_update` de `ProjectTask`.
- A tabela de regras é criada também ao abrir as Configurações, então funciona mesmo quando os arquivos são trocados sem rodar a atualização do plugin.
- Corrige o erro "A ação que você requisitou não é permitida" ao salvar as Configurações. No GLPI 11, o kernel já valida o token CSRF de todo POST e o descarta; a segunda checagem em `front/config.php` sempre falhava.
- Depois de salvar, as Configurações voltam para a própria página. Antes caíam na página inicial do GLPI, porque no GLPI 11 `PHP_SELF` é `/index.php`.
- Nova rota `ajax/config.php`, que exige o direito de configuração e o token CSRF.

### Tarefa
- Corrige Início, Data limite e Lembrete aparecendo vazios no painel da tarefa, embora estivessem gravados. O formato `'Y-m-d\TH:i'` do Twig virava o fuso horário (`T`), e o campo `datetime-local` recusava o valor. Ao salvar a tarefa, as datas eram apagadas.

### Cache de templates
- Corrige telas antigas aparecendo fora do modo debug depois de trocar os arquivos do plugin sem reinstalar. O GLPI compila os templates Twig pelo caminho do arquivo e, em produção, não relê o arquivo. Se o cache compilado não puder ser apagado pelo servidor web (arquivos criados por outro usuário no container), a tela velha fica para sempre.
- As páginas do Project Flow passam a renderizar pelo conteúdo do template (`plugin_projectflow_display()`). O cache compilado passa a ser identificado pelo conteúdo, então qualquer alteração gera compilação nova, e templates inalterados continuam usando o cache normal do Twig.

### Release e limpeza
- O workflow gera `projectflow-<versão>.tar.gz` com a pasta raiz `projectflow/`, valida a sintaxe PHP/JS e só publica quando a versão do `setup.php` ainda não tem tag.
- Removidos arquivos sem uso: `templates/task.html.twig`, `templates/mytasks.html.twig`, `MyTasksController` e os CSS/JS de versões anteriores. `front/mytasks.php` segue como redirecionamento para links antigos.
- Removida a opção `weekly_sprint_start`, que nunca foi usada (a linha é limpa na atualização).
- Assets renomeados para `projectflow-3.4.3.css/js`. Documentação atualizada.

## 3.4.2 - 2026-09-23

- Remove inclusões manuais de `inc/includes.php` dos endpoints `front/` e `ajax/`, pois o GLPI 11 inicializa esses scripts pelo entrypoint central.
- Corrige os warnings de `include('../../../inc/includes.php')` quando o plugin está instalado via Marketplace.
- Corrige erro Twig `Unknown "namespace" function` na página do projeto; próximas entregas agora são preparadas pelo controller.
- Assets CSS/JS passam a ser registrados pela própria página imediatamente antes de `Html::header()`, sem varrer a URI de todas as páginas do GLPI.
- Novos assets versionados `projectflow-3.4.2.css` e `projectflow-3.4.2.js`.

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
