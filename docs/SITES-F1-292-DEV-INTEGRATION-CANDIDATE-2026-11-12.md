# Imagine Sites — candidato integrado F1-292 para revisão de dev

**2026-11-12 | DRAFT | sem merge/deploy**

## Histórico reproduzível

- **PR #356:** base `dev` (`71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`) com F1-287..291, política sem www, identidade Imagine Node preservada no shell interno, screenshots reais Chrome em 4 larguras; SHA `0e6d8e805200a0f6bb8f9351dd061635a28971cc`, **121/121 GitHub Actions success**. F1-240 auditou 773 arquivos/217 caminhos sensíveis e F1-241 distribuiu 6 pacotes de revisão.
- **PR #357:** adiciona F1-292, OpenAPI CreateProject v3 candidato sem downgrade de envelopes v1/v2. Contrato anterior mantido como histórico. Testes Python de drift de schema e PHP contra `SyntheticCommercialEpochSignedCommand` com epochs/HMAC/TTL/replay/tenant; CI PHP 8.3/8.4 dedicada aprovada.
- **Esta branch:** nova proposta acumulada para `dev`. O CI consolidado e os inventários F1-240/F1-241 precisam ser comprovados **no SHA exato deste candidato**, não em SHAs anteriores.

## Revisão humana que permanece obrigatória

1. Criar/provar branch protection ou rulesets para `dev` e `main`: review independente obrigatório, CI requerido, impedimento de bypass/force-push (issue #281). Não realizar merge sob proteção ausente.
2. AppSec revisar versão do envelope versus versão do recurso CreateProject, fixação de chave e época comercial, manutenção do v1 apenas como legado, autenticação real e negação de downgrade.
3. Frontend/UX validar visual da marca original e capturas de quatro larguras; contraste de telas e leitor de tela, sem forçar a identidade Imagine nos sites dos clientes.
4. Infra/DB revisar 10 gates operacionais, RLS/banco independente, mTLS/Portal, nonce durável e anti-restore, CRM contingente e privacidade, verificação de publicação em Cloudflare, Leaseweb e rollback (issues #33–#38).

## Barreira de produção

`config/operational-readiness.json` permanece `status=BLOCKED`, `non_operational=true` e 10/10 gates false; todos os HTMLs de prova têm noindex, não há API operacional, OAuth/mTLS real, aprovação de clientes, persistência real, leads ou publicação. O resultado de CI é evidência de código e laboratório, não aceite AppSec ou autorização de merge/deploy.

**NO-GO** até fechamento formal dos gates, revisão independente e homologação operacional.
