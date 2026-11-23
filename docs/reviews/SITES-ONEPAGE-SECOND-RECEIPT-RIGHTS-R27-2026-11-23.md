# OnePage R27 — impedir segundo recibo depois de revogação concorrente

**Escopo:** ensaio sintético CI-only, filho da candidata Sites #630 na SHA fixa `3120d91d9becbd629622f0eda5adfba93c84b9fb`. Não é integração operacional, aceite jurídico, chamada OpenAI ou publicação.

**Primeira lacuna testável:** o observador `OnepageExactFinalAcceptanceReadCandidate::observe` lê a apresentação privada inicial, depois revalida o release e consulta novamente o recibo confidencial, *antes* de conferir de novo a declaração de direitos e aprovação editorial. Se uma fonte revogar direitos/aprovação ou alterar bytes do briefing durante a primeira apresentação, a resposta termina negada, mas o segundo recibo já foi consultado indevidamente.

**Critério test-first:** a mesma fixture fictícia de pedido/briefing/release/HTML R03/R26, com closures lendo o estado atualizado por referência. Para três mudanças durante a primeira leitura da apresentação (direitos, aprovação, bytes), exigir DENY uniforme, zero emissão e exatamente **1 recibo privado, 1 apresentação**. Controle positivo exige 2 releituras e nunca autoriza publicação. Revogação durante segundo recibo impede a segunda leitura de HTML (cobertura afetada, sem atribuir autoria anterior).

**Correção de recorte mínimo:** revalidar a prévia privada e testemunhos atuais *antes* do segundo recibo, preservando a verificação já exigida depois desse recibo e antes da segunda apresentação. Não mudar contratos `sites.ai-copy.v1`, parser, release, actor, CAS, PSP ou negócio.

**Gates:** sem writer oficial Laravel/PostgreSQL Portal #976, identidade de sessão, testemunho transacional, permissão operacional ou worker/credencial/egress exclusiva OpenAI, a integração permanece fechada. Sites #38 = NO-GO.

Os resultados, HEAD e URLs CI somente serão registrados após as execuções, sem inferência de sucesso.
