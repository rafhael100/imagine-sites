# F1-207 — Mudança de metadados sociais exige nova revisão

A F1-206 criou tags textuais Open Graph/Twitter opcionais e seguras no HTML. A F1-207 acrescenta o ciclo de decisão para **ligar ou desligar** esses metadados, evitando que o editor altere o conteúdo social de uma página já revisada sem gerar nova versão.

`SyntheticSocialPreviewRevisionPlan::begin` recebe a versão antiga do workflow, briefing e bytes de HTML, código de release anterior, booleano da nova configuração, novo releaseRef e revisão esperada. Exige estado sintético `AWAITING_PORTAL_ACCEPTANCE` com digest aprovado e papel de laboratório `imagine_reviewer`. Recalcula o HTML original e verifica byte a byte antes de qualquer proposta. Uma configuração idêntica, versão antiga ou identidade de release reutilizada é rejeitada.

O `SiteBrief` novo mantém todos os textos, produto, marca, cores, tema, ordem das seções e menu da versão anterior. Só os metadados sociais mudam, gerando SHA-256 e manifesto novos. A auditoria de HTML inerte e os testes de estrutura devem passar. A versão anterior continua imutável; o workflow novo volta a DRAFTING sem digest aprovado. Nenhum método de capacidade permite publicar, aceitar pelo cliente, enviar CRM ou reaproveitar a revisão anterior.

A suíte de testes PHP8.3/8.4 cobre 12 alterações (2 produtos × 3 temas × on/off), além de recusas de no-op, role sintético inadequado, source não revisado, versão obsoleta, reutilização de release e HTML alterado. Também verifica que um novo render de laboratório produz o mesmo digest da proposta e permanece não publicável.

O próximo passo é disponibilizar a comparação no visualizador Laravel, sem criar formulário público nem autorização real. Não envolve OG URL, imagens, indexação, hospedagem, dados pessoais, CRM, deploy nem gastos. OP01/02/04 e dez gates operacionais ainda bloqueados.
