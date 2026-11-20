# OnePage: vínculo da aprovação ao recibo atual do comprador (candidato, NO-GO)

Data: 20/11/2026. Entrega isolada sobre Sites [#571](https://github.com/rafhael100/imagine-sites/pull/571), base exata `6f613ad6443fe0eff05c1fe17436c2db9e68395d`. Referências históricas Sites #541/#542. Não modifica a trilha distinta Landing Page #569.

## Risco e correção

O componente privado `OnepageBuyerPrivatePreviewCandidate` já exigia confirmação do comprador e aprovação independente do revisor para a **mesma HEAD imutável** (tenant, pedido, site, contrato e revisão, produto, snapshot e hashes). Mas duas testemunhas válidas para a mesma versão podiam ser desacopladas: após o comprador renovar a declaração de direitos na mesma versão, um recibo anterior do revisor ainda passaria porque a aprovação não referenciava aquela confirmação específica.

A aprovação candidata agora deve conter o campo fechado `buyerConfirmationRef`, que coincide exatamente com `confirmationRef` do comprador. Campo ausente, referência divergente, testemunho de confirmação renovado sem reaprovação ou autorização referente a outra confirmação são negados, sem revelar dados no erro. Os 11 campos da HEAD continuam obrigatórios. O revisor ainda deve ser identidade distinta do comprador, e a fonte futura deve comprovar **permissão de revisão**.

Também rejeita mudança **observada** nas testemunhas entre antes e depois da renderização, mesmo se confirmação e aprovação forem trocadas *juntas* e cada leitura isolada parecer válida. O reprocessamento idempotente sem mudança é preservado. Não há domínio obrigatório na prévia, e os quatro marcadores de autorização continuam falsos; prévia privada não é aceite final nem autorização de publicação.

## Prova limitada e integração fechada

`php tests/onepage-buyer-private-preview-offline.php` usa as fixtures existentes e acrescenta cenários específicos para confirmação anterior, referência ausente, recibo alheio, reconfirmação sem nova aprovação e rotação conjunta durante o render. Também cobre caso positivo sintético de nova confirmação com novo recibo de revisor e as regressões preexistentes (comprador correto, outro comprador da empresa, versão obsoleta/revogada, hashes, tenant, domínios não exigidos e fail-closed). CI draft-only PHP 8.3/8.4 fixado à branch e base da PR; SHA e resultado deverão ser registrados após consulta ao GitHub Actions.

**Não confundir vínculo de referências com prova cronológica, autenticação ou CAS.** O produtor oficial precisará garantir, na *mesma transação/epoch*, que a aprovação do revisor autorizado sucede a confirmação ativa do comprador e que nenhuma revogação/alteração interveio; referências devem ser únicas e duráveis. As duas leituras continuam insuficientes para estabelecer atomicidade frente a revogação posterior ao retorno.

No Portal [#976](https://github.com/rafhael100/imagine-portal/pull/976), `ApprovedOnepageBriefSnapshotSource` e `CurrentOnepageBriefApprovalWitness` continuam **interfaces sem adaptador operacional**; `FirstBriefingRelease::state` retorna `briefing_available`, não bytes imutáveis nem testemunhos humanos auditados. Portal [#1003](https://github.com/rafhael100/imagine-portal/pull/1003) e Sites [#552](https://github.com/rafhael100/imagine-sites/pull/552) comprovam apenas contrato S2S sintético, não peer mTLS de produção. Sem fonte oficial do conteúdo, identidade de sessão, direitos declarados, revisor autorizado, auditoria e revogação persistentes, **o construtor padrão nega** e nenhuma porta HTTP está ligada.

**Próxima ação:** implementar e homologar no Portal a fonte de briefing imutável, confirmação, revisão autorizada e revogação sob CAS/epoch, seguida da autenticação S2S e só então de uma prévia HTTP privada. Gate [Sites #38](https://github.com/rafhael100/imagine-sites/issues/38) e dez gates operacionais **NO-GO**. Não usar esta prova para Landing Page. Sem merges, main, deploy, VPS, Cloudflare, DNS, checkout, Asaas, credenciais, pagamento ou publicação.
