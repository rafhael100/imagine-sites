# OnePage R35 — vínculo histórico do aceite (laboratório, NO-GO)

Base #638 `b1171d962d560825398fcddad702a20ce8f1a4c2` com 4 workflows SUCCESS na SHA. Portal #1099 `16b225a464422570e07430e900275bfcd5813970` 6/6; Agents #490 `8d9e78b2d359ba4e1f8cc74bf4328734dda507df` 1/1; Landing Portal #1088 6/6, independente. #541/#542 históricas. #574 já demonstra troca/reversão HTTP local, #605 demonstra aceite e veto posterior no banco de ensaio; não repetidos nem promovidos a autoridade.

## Lacuna e recorte
O observador de #638 detecta mudanças entre releituras, mas não sabe quais testemunhos e handoff estavam vigentes no instante de um clique anterior. Uma rotação coerente *antes* da primeira leitura, ou troca da operação do plano com HTML idêntico, pode passar no caminho legado. O ensaio reutiliza o pedido e renderizador existentes e demonstra explicitamente essa limitação.

O novo método observeHistoricallyBound exige uma porta de leitura separada, por tenant/pedido/site/comprador/aceite, que forneça o pin histórico do clique. Compara os campos tipados do recibo (menos os indicadores de estado, já exigidos pelo leitor corrente), proofDigest e renderHandoffSha256 internos. Reconsulta antes da segunda apresentação privada; ausência de porta nega antes de qualquer fonte privada. Uma porta fornecida também torna observe estrito. Observe sem porta permanece exclusivamente para compatibilidade de laboratórios antigos; NÃO é um caminho de integração ou homologação. Nenhuma nova autoridade, alteração de shape público, encoder ou contrato de troca Portal/Sites.

A fixture guarda o pin da primeira observação sintética e não o reconstrói após a rotação. É um objeto de memória e não uma evidência de gravação, de autenticação, de clique real ou do Portal. O contrato proposto não está ligado ao runtime. Não usar o leitor legado para contornar a exigência futura.

## Dependência estrutural
Portal #976 default-deny: falta demonstrar armazenamento imutável e leitura autorizada do pin realmente gravado no clique, a mesma confirmação e decisão editorial corrente, origem oficial e transação/CAS; duas leituras não são atomicidade. A fonte histórica deve ser independente, append-only e vinculada ao pedido autenticado e não calculada dos valores atuais. A trilha Node #1093 é precedente de pin local, não fonte Laravel. Nenhuma API real sem worker exclusivo atestado. Landing Page separada. Sites #38 NO-GO; sem merge, deploy, VPS, checkout, dados reais, publicação, credenciais ou fornecedor.

CI exata será registrada na PR após consulta dos resultados, sem atribuir sucesso antecipado.
