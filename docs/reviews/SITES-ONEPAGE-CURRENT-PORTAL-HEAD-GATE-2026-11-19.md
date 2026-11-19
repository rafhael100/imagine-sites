# Onepage: recusa de revisão quando o Portal informar outro head corrente

19/11/2026. Continuação em branch isolada da cadeia de PRs Sites #541–#549. Base imutável **#549** `89484529516e95584be40b85ab9b1effc7273184`; nenhuma branch predecessora foi alterada.

## Código implementado

- Nova fronteira `CurrentPortalOnepageHeadSource` define a futura leitura **independente** da última revisão do Portal para tenant/pedido/site. Não é implementada nem instalada em produção; o chamador não pode fornecer um JSON dizendo qual é a última revisão.
- `StoredOnepageReviewCurrentHeadGate::assertSelectedCurrent()` compõe um seletor estrito de 11 campos a partir dos bytes do snapshot candidato, exige correspondência **exata** com dois resultados do leitor independente, e reexecuta a prova nativa de supersessão contra o PostgreSQL 18 entre as duas leituras. Recusa revisão antiga, posterior, troca de tenant, contrato, conteúdo, versões, inserção de flags e corrida observável. A exceção única não divulga dados do cliente.
- Fixture PostgreSQL com origem de head **sintética** e opt-in exclusivo `SITES_ONEPAGE_CURRENT_PORTAL_HEAD_CI=1`. Testes positivos, idempotência da verificação, negativas e comparação integral das linhas e catálogo antes/depois.
- Workflow exclusivo para PR draft em PHP 8.3/8.4, sem deploy e com Portal candidato fixado em `89e309b497137cec4960b87f90a49d00bbf92843`.

## Limites (obrigatórios)

Este teste prova a **reação segura a uma fonte de latest head**, não a existência ou autenticidade dessa fonte. O Portal **ainda não tem adaptador oficial** para `CurrentPortalOnepageHeadSource` com sessão/tenant, contrato, aprovação autenticada, revogação e CAS transacional. Duas leituras não constituem transação distribuída nem impedem uma atualização posterior no Portal. A revisão continua apenas `pending_independent_review`. Fonte de aprovação real, persistência auditável da decisão, entrega privada autenticada, Landing Page, publicação técnica, aceite e gates de pagamento continuam pendentes.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Não mexe em main, dados reais, IAM, Asaas, Cloudflare, DNS ou VPS. Os dez gates operacionais permanecem bloqueados. Status CI deve ser verificado na HEAD antes de alegar sucesso.
