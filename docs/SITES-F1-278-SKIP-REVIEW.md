# F1-278 — Revisão editorial do link Pular para o conteúdo

O Imagine Sites ganhou uma operação controlada que modifica a visibilidade do link de teclado sem herdar qualquer revisão ou aprovação antiga. A opção não é dado de IA nem do cliente; é uma decisão editorial Imagine.

- Exige estado `awaiting_portal_acceptance`, papel de revisor interno sintético, versão exata e opção diferente da anterior.
- Confere digest, HTML byte a byte e QA estrutural da versão de origem antes de gerar nova revisão com referência de release distinta.
- Preserva todas as escolhas existentes de produto, tema, blocos, paleta, FAQ, SEO e CTA; altera somente `skipToContentLink`.
- A nova saída precisa mudar o digest, passar revisão de HTML inerte e empacotamento determinístico.
- O workflow retornado está em `drafting` e possui `artifactDigest=null`; nenhum método permite publicação, aceite do cliente ou gravação no CRM.
- Os testes cobrem doze revisões (dois produtos, três estilos, ativar/desativar) e cenários de no-op, papel errado, versão antiga, release igual e adulteração de origem.

Esta operação é sintética e não autentica um funcionário ou o Portal. Dez gates de produção permanecem `BLOCKED`.