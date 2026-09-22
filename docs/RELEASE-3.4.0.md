# Project Flow 3.4.0 - Tela de tarefa GLPI-native

Esta release substitui a apresentação da tarefa de projeto por um workspace inspirado diretamente no formulário de Chamado do GLPI 11.

## Mudança principal
- navegação vertical à esquerda;
- conversa/acompanhamento no centro;
- propriedades e atores à direita;
- barra inferior com **Responder**, **Adicionar execução** e **Adicionar reunião**;
- resumo no menu esquerdo com status, andamento, solicitante e data limite.

## Cache e atualização
A 3.4.0 usa novos nomes de template e assets (`task-native.html.twig`, `projectflow-3.4.css` e `projectflow-3.4.js`). Isso evita que a atualização reutilize os arquivos visuais compilados/cacheados da linha anterior.

## Atualização
1. Fazer backup da pasta do plugin e do banco.
2. Desativar o plugin sem desinstalar.
3. Substituir completamente `plugins/projectflow` pelo conteúdo desta release.
4. Em **Configurar > Plugins**, executar a atualização para 3.4.0.
5. Ativar novamente o plugin.
6. Abrir **Assistência > Minhas tarefas** e acessar uma tarefa.

Não é necessário desinstalar a versão anterior.
