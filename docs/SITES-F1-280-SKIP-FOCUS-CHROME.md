# F1-280 — Foco real no conteúdo principal após Enter

A ação nativa **Pular para o conteúdo** agora aponta para `<main id="principal" tabindex="-1">` quando a opção editorial está ativada. Isso permite mover o foco programático do navegador ao conteúdo principal após Enter, sem criar uma parada adicional na sequência normal do Tab. Quando a opção está desativada, `<main id="principal">` e todos os bytes anteriores permanecem intactos.

- O auditor básico exige exatamente um elemento `main`, de uma das duas formas; a forma com tabindex só é permitida quando o link único e o estilo de foco canônico estão presentes.
- O teste de regressão PHP compara digest, estrutura, contraste do link e a manutenção da revisão privada para ambos os modos.
- Um novo laboratório Chromium gera o HTML real do pipeline PHP, verifica SHA-256 e quatro arquivos privados e usa CDP em `about:blank` para testar dois produtos em desktop 1365 px, mobile 390 px e estreito 320 px.
- O Chrome envia Tab real, exige que o primeiro item focado seja o link visível e com alvo de pelo menos 44 px, envia Enter e confirma `location.hash=#principal` e `document.activeElement.id=principal`.
- Sem scripts no HTML, formulários, hospedagem, rede externa ou capturas de dados.

**Não é certificação WCAG completa nem funcionalidade ativa para clientes.** Todos os dez gates de produção continuam BLOCKED. Sem merge/deploy.