# F1-120 — Seleção explícita de versão e recusa de downgrade Portal→Sites

## Problema

No laboratório F1-118, a ausência de `SITES_F118_REQUIRE_EPOCH_SIGNED=1` selecionava automaticamente o envelope antigo HMAC v1. Embora disponível apenas no modo CLI/testing, o comportamento silencioso é incompatível com a promoção segura do protocolo v2 e dificulta testes negativos de implantação.

## Mudança

`LabSignedProjectIntake::admitSigned` exige agora **exatamente uma** opção de execução após validar que está em CLI/testing, antes de consultar chave/epoch, verificar a assinatura ou consumir o nonce:

- `SITES_F118_REQUIRE_EPOCH_SIGNED=1`: exige o envelope HMAC v2 com `keyEpoch` autenticado pela MAC; o envelope v1 é recusado.
- `SITES_F118_ALLOW_LEGACY_V1_LAB=1`: permite a v1 somente no laboratório existente, sem habilitar o fluxo operacional.
- Nenhuma opção ou ambas habilitadas: **DENY**, com `lab_signed_project_envelope_mode_denied`; nenhum nonce é consumido.

A v1 permanece apenas nos testes antigos e exige consentimento explícito do próprio cenário de laboratório. O fluxo v2 **não** faz fallback para v1 se assinatura, época ou comando forem inválidos.

## Testes

`tests/f94-pg-authorized-project-admission.php` e `tests/f111-pg-durable-signed-intake.php` passam a habilitar explicitamente o modo v1 nos cenários herdados, e suspendem temporariamente essa permissão nos cenários v2.

Novas regressões verificam que **modo ausente** e **opções contraditórias** negam a solicitação sem escrever dados ou consumir nonce. Em seguida, a mesma mensagem (nonce ainda disponível) é admitida somente após ativar o modo legado de teste, respeitando idempotência e os controles PostgreSQL18 já existentes.

Os workflows F1-94 exercitam ambas as bases PostgreSQL 18 efêmeras em PHP 8.3 e 8.4. Não há migração nem novos endpoints.

## Limites

Isto reforça a seleção local de versão, **não** cria IAM Portal operacional, protocolo oficial homologado, mTLS/OAuth, vault, validação de assinatura de produção, aceitação do cliente ou autorização para publicação. Nenhum outro ambiente além do laboratório CLI/testing é habilitado. A existência de uma flag para v1 não implica que legado esteja liberado para produção — o desenvolvimento operacional deve exigir v2 ou superior com identidade/autorização real.

Sem merge em dev/main, deploy, VPS/Cloudflare, dados pessoais ou novas despesas. **10/10 gates de produção permanecem BLOCKED.**
