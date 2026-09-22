# Project Flow 3.3.0 - Release de produção

## Objetivo

A versão 3.3.0 aproxima a tarefa de projeto da experiência nativa de Chamado do GLPI 11 e endurece pontos de integridade/permissão identificados na revisão da linha 3.2.

## Upgrade recomendado

1. Backup do banco de dados do GLPI.
2. Backup da pasta `plugins/projectflow`.
3. Desativar o Project Flow sem desinstalar.
4. Substituir a pasta por `projectflow` desta release.
5. Acessar **Configurar > Plugins** e executar a atualização para 3.3.0.
6. Ativar o plugin.
7. Limpar cache do GLPI e do navegador se houver assets antigos.
8. Executar o checklist de `docs/OPERATIONS.md`.

## Principais fluxos a validar no ambiente

- **Assistência > Minhas tarefas** abre a fila do executor.
- A tarefa usa navegação lateral, timeline central e painel de propriedades à direita.
- **Responder** cria comentário.
- O menu da seta de **Responder** registra Execução ou Reunião.
- Execuções/Reuniões abrem em **Minhas**, com opção **Todas**.
- Alteração de reunião mantém o worklog sincronizado.
- Lembrete por grupo resolve os membros do grupo antes de enfileirar e-mail.
- Usuário sem direito financeiro não recebe contagem de custos.
- Usuário sem acesso a uma tarefa não recebe seus dados no relatório/estatísticas.

## Rollback

Desative o plugin, restaure a pasta 3.2.0 e mantenha as tabelas do Project Flow. Não use **Desinstalar** para rollback, pois a desinstalação remove as tabelas operacionais do plugin.
