# OnePage R12 — recusar recibo com histórico de auditoria corrompido (NO-GO)

## Preflight 22/11/2026 (antes da escrita)

- Base Sites #615 R11: `88ffd133b43ba095da3b3384604c9e37d765a3b5`, **4/4 workflows SUCCESS na SHA exata** (45032477006, 45032477009, 45032476999, 45032477033). Consulta de PRs/branches encontrou #615 como último sucessor desta trilha; nenhuma R12 OnePage. Branch nova e isolada. Sites #605 (PostgreSQL), Sites #580/Portal #1088 (Landing) e Agents #490 preservados.
- Fontes oficiais continuam ausentes: Portal #976 oferece `ApprovedOnepageBriefSnapshotSource` e `CurrentOnepageBriefApprovalWitness` **default-deny**, sem leitor operacional; #980/#948 usam pedido e sessão sintéticos em laboratório. Portal #1003 e Sites #552 fornecem contrato, não autorização. Não criar writer nem deduzir aprovação pelo briefing D0.
- Sites #541/#542: precedentes históricos, não fontes de autoridade ou razão para repetir paridade.

## Defeito concreto encontrado em #615

Sob lock exclusivo, a R11 lia o arquivo JSONL e percorria cada linha com `json_decode`, mas **ignorava uma linha inválida**, tratando-a como ref inexistente. Uma linha anterior truncada/corrompida podia coexistir com um novo aceite HTTP 201, comprometendo o histórico de auditoria da própria fixture.

A R12 valida **todas** as linhas históricas antes de qualquer novo append: JSON canônico com exatamente seis campos sintéticos e tipos esperados, sha hexadecimal, epoch/ref consistentes e referências únicas. Linha inválida, duplicada ou ambígua produz HTTP 503, sem novo recibo. Essa validação fica sob o lock R11 e não altera os contratos de produção.

## Nova prova

O Chromium, em origem PHP loopback e sessão fictícia, observa a release E; insere uma linha JSONL sintética inválida no histórico A/B/C; tenta o clique E e exige **503 e arquivo sem mutação adicional**. Restaura os bytes originais e então executa o caso R11 de 12 POSTs simultâneos na mesma release: expectativa 1x201, 11x200 idempotentes, um único recibo E. O workflow novo confere arquivos exatos, base/HEAD fixos, PHP, Node e os dois marcadores R11/R12. Os checks efetivos dependem da execução GitHub Actions **na HEAD da PR**; este documento não é prova de CI verde.

## Gates mantidos

Este é um defeito **da fixture de laboratório**, não uma correção de writer operacional. Não prova durabilidade pós-crash/fsync, autenticação Portal, origem oficial imutável de briefing, declaração de direitos, revisão autorizada, revogação transacional, aceite final real ou publicação. OpenAI real depende de worker OnePage atestado com identidade/modelo/egress/credencial específica; sem chamada. Landing/CRM/Vendas independentes. Nenhum merge/deploy/VPS/DNS/Cloudflare/Asaas/checkout/segredo. Sites #38 e todos os gates operacionais seguem **NO-GO**.

**Próxima ação material:** obter do Portal, sem inventar fonte, o produtor oficial revisado de snapshot/bytes + confirmação de direitos do comprador e aprovação/revogação da mesma revisão; acoplar em ambiente privado e distinto da fixture. Não abrir outra regressão sintética só por cadência.
