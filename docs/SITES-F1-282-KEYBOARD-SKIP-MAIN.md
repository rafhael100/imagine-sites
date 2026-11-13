# F1-282 — Link de teclado “Pular para o conteúdo”

Foi adicionada uma opção editorial para melhorar a navegação por teclado nas Onepages e Landing Pages do Imagine Sites. Quando ativada, a primeira âncora da página leva à região principal `#principal`, permitindo ignorar a navegação de cabeçalho.

- O texto e o alvo são fixos, sem entrada de URL, CSS ou HTML pelo agente de IA ou usuário final.
- A âncora permanece fora da tela até receber foco pelo teclado; então se torna visível, com contorno de foco e altura mínima de 44 px.
- A opção funciona nos dois produtos e nos três temas existentes, inclusive com paletas validadas; a configuração desativada preserva os bytes e SHA das versões anteriores.
- O auditor básico permite apenas a âncora canônica `href="#principal"`; a verificação DOM exige alvo existente e nome acessível.
- Mudanças de texto, tema, layout, SEO e apresentação que geram novo release mantêm essa escolha; a edição comum de copy não pode ativá-la ou desativá-la sem fluxo editorial próprio.
- Testes cobrem 24 combinações de produto, estilo e paleta e recusam link redirecionado, nome vazio e opção controlada por conteúdo não confiável.

Somente HTML sintético privado e `noindex`, sem formulário, script, cliente real ou publicação. A homologação WCAG completa depende de avaliação independente; dez gates operacionais permanecem `BLOCKED`.