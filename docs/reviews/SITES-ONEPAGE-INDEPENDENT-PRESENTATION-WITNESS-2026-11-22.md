# OnePage — testemunha independente da apresentação exata (R02, CI-only)

Data: 22/11/2026. Continuação isolada de Sites #604, HEAD base `bb9ad1bad415845cfc47db818c7fdc6d0067da9f`. A PR #605 (revogação terminal SQL) pertence a outra linha de trabalho e não é modificada. Sites #541/#542 e Portal #976/#1003 são referências prévias, não fontes de autoridade operacional.

## Lacuna nova identificada

O leitor de aceite final de #604 exigia `presentationRef` não vazio no recibo, mas apenas verificava os campos que o **próprio recibo** copiava do HEAD. Não havia leitura independente mostrando que a apresentação vinculada àquele identificador correspondia ao HTML do release/época/revisão que o comprador teria visto. Um recibo que apontasse para apresentação não observada, de outros bytes ou outra época ainda poderia passar, desde que seus campos copiados coincidissem com o HEAD.

## Alteração pequena e verificável

`OnepageExactFinalAcceptanceReadCandidate` agora possui quarta porta de leitura `currentPresentation`, obrigatória e **negada por padrão**. Essa porta deve ter origem independente da solicitação e do recibo de aceite; recebe tenant, pedido, site, comprador e a referência da apresentação exata e exige envelope de chaves estritas com: titularidade, `releaseRef`, `artifactEpoch`, `htmlSha256`, `briefRevision`, `sourceVersion`, `presentationRef` e evidência `observed===true`. Ler um bool do próprio cliente ou fabricar esse registro no harness **não configura fonte autorizada**. O leitor compara a testemunha com o HEAD revisado, executa o renderizador/revisão já existente e relê HEAD, recibo e apresentação; divergências observadas são negadas. Não há nova permissão, URL operacional, writer ou regra de publicação.

A fixture independente é sintética, não navegador real. Acrescenta negativas de apresentação ausente, nunca observada, pertencente a outro comprador, SHA/época/release/revisão divergentes, referência diferente, autoridade extra e revogação observada entre as duas leituras. Conserva o ensaio de mudança efetiva do HTML A→B e exige outra apresentação e outro clique fictício para B. A CI do draft executa testes PHP 8.3 e 8.4 com `contents: read` e SHA de base exata.

## Limitações explícitas

- Não houve browser Chrome/Chromium, autenticação operacional, apresentação real, escrita de recibo legal, aprovação oficial de briefing nem leitura independente do Portal. A injeção de closure em laboratório não substitui essas fontes.
- Mesmo duas leituras não fornecem transação ou CAS nem impedem revogação invisível na janela posterior. Não é prova de publicação ou entrega.
- Precisamos da fonte oficial de apresentação autenticada do comprador e da fonte de revisão/autorizações do Portal, vinculadas à mesma revisão/digest. O adaptador de produção permanece ausente e fecha por padrão.
- Sem endpoint real OpenAI, credencial, gateway/egress exclusivo atestado, checkout, CRM/Vendas, publicação, domínio, VPN/VPS, Cloudflare, deploy ou merge. OnePage e Landing permanecem apartadas.

**Resultado permitido: laboratório / PR draft / NO-GO.** Gate Sites #38 e pagamentos/publicação seguem bloqueados.
