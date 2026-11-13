# F1-295 — Diagnóstico de emissor v3 sem autorização operacional

**2026-11-12 | DRAFT / NO-GO**

O gate F1-294 anteriormente verificava somente a presença dos arquivos do emissor e da classe emitente no commit atual do Portal. Um par de stubs vazios era classificado como implementação candidata não revisada.

F1-295 introduz verificações adicionais **somente de forma sintática**:
- Arquivo do signer presente, regular e não symlink.
- Fonte contém classe `SyntheticSitesCommercialEpochSignedEnvelopeSigner`, campo `commercialEpoch` e invocação `hash_hmac(`.
- Arquivo issuer contém o método `issueCommercialEpochSigned(`.
- A ausência de qualquer item retorna `BLOCKED_CURRENT_V3_NOT_ESTABLISHED`; todos presentes retornam `BLOCKED_UNREVIEWED_CURRENT_V3_IMPLEMENTATION`.

Esses marcadores de fonte não verificam semântica, identidade, procedência, equivalência criptográfica, permissões ou autoridade comercial. Mesmo com todos os marcadores o resultado `releaseAuthorized=false`, `sitesMayConnectToPortal=false` e `sitesMayCreateRealProject=false` é invariável. Testes cobrem ausência, arquivos incompletos, stubs e formato candidato.

A matriz GitHub Actions existente `sites-f1-294-portal-current-v3.yml` reexecuta o autocheck desta mudança e o checkout read-only de ambos os SHAs públicos fixos. Não acrescenta rede de produção, segredos ou endpoints.

**Bloqueios mantidos:** issue #33 OP01, branch protection/revisão independente #281, dez gates em `config/operational-readiness.json` com valor falso. Não fazer merge em dev/main ou deploy sem revisão/homologação completa.
