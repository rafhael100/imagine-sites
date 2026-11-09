# SITES-F1-11 — Envelope HMAC de CreateProject, verificação e replay fail-closed

**Projeto:** Imagine Sites independente, Portal autoridade comercial e de contrato. **Natureza:** código puro de laboratório, sem HTTP, sem chaves reais nem autorização produtiva.

F1-11 adiciona codificador/verificador de envelope candidato `CreateProject v1` com campos assinados e ordenados: `schemaVersion, issuer, audience, issuedAt, expiresAt, nonce, payload, mac`. O payload contém somente os campos mínimos de `SiteProjectCommand` e não inclui lead, briefing ou dado pessoal.

Mecanismo sintético: HMAC-SHA256, comparação em tempo constante, validade máxima de 120 segundos, emissor `imagine-portal`, audiência `imagine-sites`, nonce e checagem tipada/canônica do payload. O teste usa chave fictícia publicada na própria suíte, que **NÃO deve ser usada em ambiente real**. Um pedido assinado mas não elegível deve continuar sendo negado após a verificação, via fontes oficiais de contrato e grants.

**Segurança por padrão:** o verificador recebe `DenyingReplayStore` quando nenhuma fonte de nonce é configurada, o que sempre recusa a mensagem. A suíte de testes injeta store em memória apenas para simular recebimento único. **Uma store em memória não prova proteção contra replay entre processos/servidores.** Em produção, a emissão e verificação exigem truststore/rotação de chaves, isolamento, transporte seguro, revogação, clock/skew, atomicidade durável e mapeamento rigoroso de issuer/tenant/ação; não usar MAC como atalho para pagamento, aceite ou aprovação.

**Não existe:** endpoint HTTP, autenticação de usuário Portal, integração financeira, replay DB, API de emissão produtiva, fila ou network. P0-DOC03/06, RF-PEND-01/RF-PEND-04, RV-CT e homologação ainda abertos. AGI-11-RB01 desabilitado. Sem merges/deploys automáticos.

**Próximo passo:** testes de paridade no Portal para esse envelope e persistência anti-replay em laboratório PostgreSQL, preservando negação padrão e revisão de autenticação no backend antes de integrar.
