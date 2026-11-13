# F1-340 — navegação por teclado para a revisão interna Sites

**Escopo:** página de laboratório administrativo Imagine, não sites publicados de clientes.

O shell da revisão recebe um link **real e primeiro elemento interativo da página**, `Ir para o conteúdo principal da revisão`, que aponta para `#sites-review-main`. O elemento `main` recebe `tabindex="-1"`, possibilitando salto sem incluir um foco adicional na navegação normal. O link só aparece quando recebe foco, com borda/foco de alto contraste, área de toque mínima 44px e largura segura para celular.

A implementação não cria roteamento novo, ação falsa, aprovação, formulário, HTTP público ou conteúdo de cliente. A identidade original (logomarca, grafite, laranja, espaçamentos e tipografia) permanece.

Validação: contrato F1-290 prova a existência do alvo real, unicidade do link, CSS de foco, e isolamento do stylesheet em relação ao renderer de clientes. O teste de Chromium F1-291 verifica em 1365, 820, 390 e 320px que o link é o primeiro da página, fica recolhido fora de foco, aparece ao focar, tem ≥44px e direciona ao `main` apropriado. Os testes existentes continuam checando overflow, logo local, navegação, cores e QA.

**Pendências:** as páginas de briefing, edição, aprovação e publicação reais ainda exigem revisão de teclado, rotas/roles do Portal e screenshots aprovadas (#351). O laboratório não certifica WCAG AA da aplicação completa, não habilita autenticação ou publicação. Branch protection #281, OP01–OP05 e dez gates de readiness permanecem BLOCKED.
