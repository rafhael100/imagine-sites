# Portal → Sites: limite do briefing aprovado e replay nativo (somente laboratório)

**19/11/2026.** Continuação sem merge das [#541](https://github.com/rafhael100/imagine-sites/pull/541), [#542](https://github.com/rafhael100/imagine-sites/pull/542) e [#543](https://github.com/rafhael100/imagine-sites/pull/543), sobre a HEAD fixa `fe7f099c027e1e149e7e425add959326c6d836e4`.

## Lacuna real, não resolvida pelo indicador `briefing_available`

O Portal não tem um leitor operacional de briefing rico imutável e explicitamente aprovado pelo comprador, nem uma prova independente corrente de aprovação/revogação. A candidata [Portal #976](https://github.com/rafhael100/imagine-portal/pull/976), SHA `89e309b497137cec4960b87f90a49d00bbf92843`, fornece **interfaces e gate padrão negado**, sem adaptadores oficiais. Não confundir `sourceState=approved` do manifesto candidato com a decisão de um cliente. Este teste não inventa aprovação.

## O que é exercitado no PG18 descartável

- Usa as **classes reais e pinadas** de contrato, snapshot aprovado, `OnepageApprovedBriefExportGate`, exportador e plano do Portal; usa somente doubles sintéticos de contrato, origem e witness para o cenário positivo.
- Compara os bytes produzidos pelo gate (com duas leituras independentes) ao manifesto e corpo do Portal efetivamente persistidos em Sites. O parser e o recorder reais do Sites recebem os mesmos bytes. O replay positivo deve ser idempotente, com histórico/linhas/catálogo intocados.
- O render plan gerado do par validado deve ter bytes idênticos ao plano que o Sites já conhece e ter digest de HTML verificado pelo renderer real, mantendo as quatro flags de autorização negativas.
- Com o gate **padrão** sem fonte, ou com contrato/corpo/witness ausente, revogação entre leituras, mudança de tenant/contrato, mudança de bytes/epoch/ref de aprovação, ou `sourceVersion` desconhecida, o gate deve devolver somente `ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED`, sem exceção aninhada; a fixture compara o conjunto completo de linhas e objetos de catálogo após cada negativa. O Sites não recebe essas entradas recusadas.
- Novo marcador `PORTAL APPROVED BRIEF GATE PG18 PASS`, exigido em PHP 8.3 e 8.4. Preserva as suites legadas, incluindo 1.279 verificações registry e o marcador de nove planos adversariais da #543. Não modifica as migrations, o contrato de produto, nem o esquema de dados.

## Restrições e execução

Arquivo: `tests/fixtures/portal-approved-brief-gate/contract.php`. A inclusão na fixture de proveniência exige `GITHUB_ACTIONS=true`, `APP_ENV=testing`, o PostgreSQL18 de uso único sob SCRAM/TLS e as flags `SITES_PORTAL_APPROVED_GATE_CI=1`, `SITES_PORTAL_RENDER_PLAN_BOUNDARY_CI=1` e antecessoras. Workflow draft-only, branch filha e SHA de base exatas, checkout imutável do Portal sem token persistente, sem credenciais de cliente. O marcador só é emitido após a validação de integridade de linhas/catálogo.

**Não comprovado:** autenticidade de sessão/cliente, aceite contratual, pagamentos, transporte privado, segurança transacional pós-retorno do gate, envio/reconciliação, revisão oficial, publicação, DNS ou aceite final. A dupla leitura do gate não é CAS transacional. O próximo passo de produto depende de implementar no Portal fonte aprovada versionada/auditável e witness independente, com revisão do esquema de autorização e mecanismo de revalidação atômica antes do transporte.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Nenhuma conta real, VPS, Cloudflare, Asaas, DNS, custo de terceiros, publicação ou main foi alterada. Os dez gates operacionais seguem bloqueados.

**Evidência:** preencher somente após conferir a HEAD e logs dos jobs reais desta PR.
