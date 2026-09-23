# Operação, atualização e homologação - Project Flow 3.4.3

## Atualização a partir de 0.x / 1.x / 2.x

1. Faça backup do banco.
2. Faça backup de `plugins/projectflow`.
3. Desative o Project Flow, mas **não desinstale**.
4. Substitua integralmente a pasta antiga pela versão 3.4.3 (o pacote já traz a pasta raiz `projectflow/`).
5. Em **Configurar -> Plugins**, execute a atualização.
6. Ative o plugin.
7. Limpe cache do GLPI/navegador se necessário.
8. Abra **Project Flow -> Configurações**.

### Tela antiga aparecendo fora do modo debug

O GLPI compila os templates Twig e, em produção, não relê os arquivos. O modo debug usa um cache compilado separado. Desde a 3.4.3 o Project Flow renderiza pelo conteúdo do template, então trocar os arquivos já produz a tela nova. Se outro plugin tiver o mesmo sintoma, limpe `files/_cache/*/templates` no servidor (`php bin/console cache:clear`, executado com o usuário do servidor web).

## Configuração obrigatória antes do teste

1. Defina **Estado inicial de projetos**.
2. Defina **Estado inicial de tarefas**.
3. Revise **Percentual por estado** do Kanban.
4. Escolha o modo padrão:
   - Tarefa direta; ou
   - Tarefa + chamado.
5. Escolha o controle padrão:
   - Horas; ou
   - Custo financeiro (horas continuam registradas separadamente).
6. Se usar e-mail de lembrete, valide SMTP e cron do GLPI.

Exemplo de progresso:

| Estado | Percentual |
|---|---:|
| Nova | 0% |
| Planejada | 10% |
| Em execução | 50% |
| Validação | 85% |
| Concluída | 100% |

## Checklist de homologação

### Projeto e templates
- Criar projeto em branco.
- Criar por template.
- Confirmar que projeto nunca nasce sem estado.
- Confirmar estado das tarefas clonadas.
- Alterar prioridade, modo de execução e modo de controle.
- Configurar orçamento de horas e validar saldo/consumo.

### Kanban
- Criar tarefa e confirmar estado inicial.
- Arrastar por todas as colunas.
- Conferir `percent_done` após cada movimento.
- Validar 100% no estado finalizado.
- Confirmar ponto de atenção no card e na saúde do projeto.

### Tarefa direta
- Atribuir usuário/grupo.
- Confirmar inclusão automática na equipe do projeto.
- Abrir a tarefa e responder um comentário pelo botão **Responder**.
- Usar a seta do **Responder** para lançar uma **Execução**.
- Usar a seta do **Responder** para lançar uma **Reunião**.
- Conferir os filtros **Minhas/Todas** em Execuções e Reuniões.
- Anexar documento.
- Buscar e vincular ativo por nome/ID.
- Criar dependência.
- Configurar ponto de atenção e lembrete.
- Verificar a tarefa em **Projetos > Minhas tarefas**, inclusive quando o projeto é antigo (fora dos projetos modificados recentemente).
- Confirmar que o lançamento de horas de uma reunião não pode ser removido pela seção Execuções (só removendo a reunião).

### Modo tarefa + chamado
- Criar projeto no modo `ticket`.
- Criar tarefa com tipo e categoria do Ticket.
- Confirmar criação automática do Ticket.
- Confirmar relação em `ProjectTask_Ticket`.
- Confirmar relação em `Itil_Project`.
- Alterar o Ticket nativamente e verificar o status refletido no Project Flow.

### Horas
- Lançar horas em tarefas distintas.
- Criar reunião e confirmar o lançamento do tipo reunião.
- Editar duração da reunião e confirmar sincronização.
- Remover reunião e confirmar remoção das horas correspondentes.
- Validar total de execução, reunião e geral.
- Validar saldo/excedente quando existir orçamento de horas.

### Reuniões
- Criar, editar e remover.
- Preencher participantes, resumo, decisões e pendências.
- Confirmar que nenhuma `ProjectTask` foi criada para a reunião.

### Relatório semanal
- Registrar atividades em mais de uma tarefa.
- Finalizar ao menos uma tarefa na semana.
- Criar ponto de atenção.
- Registrar reunião e horas.
- Selecionar a semana e gerar relatório.
- Conferir entregas, atualizações, próximos passos, atenção, reuniões e horas.
- Copiar o texto.
- Editar o texto e salvar snapshot.

### Contratos e documentos
- Vincular contrato existente ao projeto.
- Abrir o contrato pelo Project Flow.
- Anexar documento ao projeto.
- Anexar documento à tarefa.
- Desvincular sem apagar arquivo compartilhado.

### Lembretes
- Programar lembrete em uma tarefa para poucos minutos à frente.
- Confirmar badge/banner no Project Flow.
- Executar o cron `taskreminders`.
- Confirmar fila/e-mail quando o canal de e-mail estiver habilitado.
- Tarefa atribuída só a grupo com um membro inativo: o inativo não recebe e-mail.
- Lembrete sem destinatário com e-mail: é retentado a cada execução e abandonado após 24 tentativas, sem bloquear lembretes novos (ver log do GLPI).

### Tela operacional da tarefa
- Conferir menu lateral: Tarefa, Execuções, Reuniões, Tarefas, Ativos, Documentos, Chamados, Dependências e Histórico.
- Conferir no contexto lateral Status, Andamento, Solicitado por, Data limite e Projeto.
- Conferir painel direito com propriedades editáveis e executores.
- Validar que **Responder** cria comentário e permanece no acompanhamento.
- Validar que Execução e Reunião registradas pelo menu rápido aparecem em suas respectivas seções.
- Validar comportamento responsivo em largura de notebook e desktop.

### Permissões
- Perfil somente leitura.
- Gestor do projeto.
- Executor com direito `UPDATEMY`/equivalente.
- Entidades pai/filhas e recursividade.
- Usuário sem direito de criar Ticket em projeto `ticket`.
- Usuário sem direito de documento.
- Usuário não gerencial em projeto monetário: não deve enxergar financeiro.

## Rollback

Se houver problema, desative o plugin e restaure a pasta anterior. Não desinstale durante rollback se quiser preservar atividades, horas, reuniões, lembretes, favoritos, metadados e relatórios semanais do Project Flow.

Projetos, tarefas, equipes, tickets, documentos, contratos e custos nativos permanecem no GLPI.
