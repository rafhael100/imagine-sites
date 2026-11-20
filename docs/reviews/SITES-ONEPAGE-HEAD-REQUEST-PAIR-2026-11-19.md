# Onepage current-head: pedido canônico Sites → Portal (paridade v1)

Data: 19/11/2026. Branch filha isolada da [Sites #551](https://github.com/rafhael100/imagine-sites/pull/551), HEAD `49e6f0a8da848ebf50b6f507d2d3cec6ae65dc97`. O contrato receptor é o código real da [Portal #1001](https://github.com/rafhael100/imagine-portal/pull/1001), pinado em `51cc13a3baf1ac7c95fce226378296d9a7d76022`. Nenhuma branch anterior foi modificada.

## Alteração implementável

A extração `PortalOnepageHeadRequestCandidate::encode()` define uma fonte única para os bytes JSON usados pela candidata `PortalOnepageHeadMtlsHttpSource::readCurrent()`: cinco campos canônicos ordenados, produto `onepage`, referências validadas, identidade separada e teto de 1024 bytes. Anteriormente, o encoding ficava inline no transporte e não tinha teste de paridade com o futuro receptor.

A nova fixture `tests/onepage-head-request-pair-offline.php` fornece os bytes produzidos pelo Sites diretamente a `OnepageCurrentHeadS2sRequestCandidate::decode()` do Portal. Exercita replay de bytes estáveis, produto diferente, versão de schema alterada, injeção de autoridade, chave duplicada, tenant estrangeiro e seletores malformados. A leitura de outro tenant só é distinguível pelo parser: **nenhuma dessas validações autoriza o tenant**.

## Limitações e gates

O contrato receptor do Portal ainda não está ligado a um controller HTTP, certificação de identidade S2S, source oficial transacional nem autorização de empresa/pedido/site; o transporte mTLS do Sites continua **desvinculado** e não é usado em produção. O teste é offline e o pipeline cruza SHAs imutáveis com PHP 8.3/8.4, sem invocar VPS, PostgreSQL ou endpoint de cliente. As regressões PG18 pertencem às PRs anteriores; não é comprovação operacional.

A próxima etapa indispensável é fonte oficial de briefing aprovado/versionado e revogável no Portal com identidade autorizada, transação/CAS, auditoria/replay e homologação de transporte. Só depois se deve integrar revisão formal, publicação técnica, aceite e o produto Landing Page, sem reutilizar indevidamente o schema Onepage.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Os dez gates operacionais continuam bloqueados, inclusive pagamento e publicação. Sem VPS, produção, main, DNS, Cloudflare, Asaas, segredos ou despesas.
