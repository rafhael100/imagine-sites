# OnePage R22 — revalidar briefing antes da segunda apresentação privada

## Preflight e fronteira

Em 23/11/2026, foram consultadas PRs abertas, branches e runs de Sites, Portal e AI Agents antes de escrever. Base isolada: [Sites #625](https://github.com/rafhael100/imagine-sites/pull/625) `8759cbe1099ff3d35d94b6692ec1686352d1ab7a`, quatro workflows SUCCESS na SHA. Portal [#1097](https://github.com/rafhael100/imagine-portal/pull/1097) `b7a4510f544010f72e83ca27e0c7dafa2c388b27`, seis workflows SUCCESS; Agents #490 só prova Responses simulado. Não havia branch sucessora R22 OnePage no Sites; esta é filha de #625, sem escrita em outro ramo. Sites #541/#542 históricos, #552 e Portal #1003/#976 permanecem contratos e gates existentes, não fonte oficial nova. Landing Page intocada.

## Falha reproduzida e correção mínima

O gate `OnepageExactFinalAcceptanceReadCandidate::observe()` já verificava a revisão do briefing e os direitos antes de ler pela primeira vez a observação de HTML privado (#625). Porém, durante a **primeira** leitura `currentPresentation`, a aprovação da revisão ou a declaração de direitos podiam ser revogadas. Sem nova verificação da prévia, o gate ainda consultava **uma segunda vez** os bytes privados e retornava como aceitos se release e clique persistissem iguais.

Teste primeiro: `tests/onepage-presentation-second-read-r22.php`, com o **mesmo** pedido sintético, fonte e renderizador já admitidos em #604. O teste substitui apenas a testemunha corrente durante a primeira leitura da apresentação. Na SHA `8f0ca9388c845011bb49a1fcbdc6eb100d8f1153`, o [run 45145272714](https://github.com/rafhael100/imagine-sites/actions/runs/45145272714) falhou em PHP 8.3/8.4 na negativa uniforme: o caminho antigo considerava o aceite válido após revogação. Isso não é nova falha de parser, output buffer ou transport.

Correção em `21f0d9d46695e82c03ff9a1d5f6921959a5737b4`: entre a leitura independente inicial e a segunda, reexecutar o **mesmo** renderizador de prévia com os leitores atuais da confirmação de direitos e aprovação editorial, exigindo equivalência estrita do resultado. Se revogado ou alterado, negar antes da segunda consulta a `servedHtmlBytes`. O caso positivo continua a requerer duas leituras independentes e a mesma revisão/SHA, sem nunca transformar aceite em publicação. Teste inclui retirada dos direitos, revogação do revisor, negativa sem detalhes e controle positivo. Reexecutar só regressão `onepage-final-acceptance-artifact-drift-offline.php` diretamente afetada; não reexecutar suites antigas verdes.

## Limites e próxima dependência

Esta é **somente** uma defesa contra corridas observadas em fixtures; releituras não formam snapshot atômico, CAS ou lock transacional. `PortalSiteContractSource`, bytes imutáveis e assinaturas/autenticação corrente de comprador e revisor no Portal #976 não têm produtor operacional comprovado. Não declarar browser autenticado, aceite real, geração IA real nem publicação. Ainda faltam worker exclusivo OnePage, identidade, modelo/rota, egress e credencial já provisionada/atestada; não usar dados reais, chave do Faísca ou CI público para segredos. O contrato `sites.ai-copy.v1` permanece estrito, sem alterar renderização.

Sem VPS, originais/clone, checkout, PSP, Asaas, CRM/VEN, Landing, n8n, Cloudflare, DNS, deploy, main, merges ou permissões. A release continua **Sites #38 NO-GO**, com formulário desabilitado sem destino oficial. Após CI final, comentar links e status pela SHA exata.
