# Imagine Sites — candidato integral F1-294 para revisão independente

**2026-11-12 | DRAFT / NO-GO | base dev fixa 71b8ffe51139d0e70ed32591b07f295fb7bf5fe3**

## Avanços desta cadeia

- PR #358 (F1-292): OpenAPI CreateProject v3 candidato sem fallback v1/v2, testes de HMAC/epochs/TTL/replay; **122/122 workflows GitHub Actions aprovados**, auditoria completa 780 arquivos e 220 sensíveis.
- PR #359 (F1-293): parser `SyntheticCommercialEpochSignedCommand::verifyCanonicalJson` exige bytes JSON canônicos **antes** da verificação HMAC e reserva nonce, negando duplicatas, BOM, escapes equivalentes, formato não canônico, alterações de tenant e downgrade. **12/12 workflows aprovados** no seu SHA.
- PR #360 (F1-294): compara duas revisões reais de código público do Portal: histórico `8d1e20bc5c39dc4106b549c5e979351b5c766615` (emissor v3 candidato) e fast-track `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8` (emissor histórico ausente no caminho esperado). Checkout read-only, SHA e blob do signer fixados. Mesmo quando o código aparece, retorna **NO-GO sem revisão independente**. Workflow específico aprovado.
- Esta consolidação propõe somente revisão de código em `dev`; não altera `dev` ou `main`. Todos os workflows e os seis pacotes F1-240/F1-241 devem rodar no SHA **desta** branch, independentemente do histórico.

## P0 e operação

1. Exigir configurações GitHub `dev/main` de proteção e review independente (issue #281). Nenhum merge unilateral, force-push, publicação ou autoaprovação.
2. Portal: selecionar commit atual candidato que de fato exponha emissor v3; validar compatibilidade cross-repo usando dois SHAs aprovados. Ausência da classe não se resolve com o teste histórico. O GitHub Action de F1-294 é diagnóstico de **ausência de autoridade**, não prova de prontidão.
3. AppSec: identidade forte S2S e chave do cofre, epoch revogável fora de restore, nonce durável, RBAC por recurso/tenant, idempotência e trilha sem PII; homologação HTTPS com certificados/credenciais exclusivamente de teste.
4. Infra e cliente: provisionamento controlado Leaseweb/Cloudflare, base PostgreSQL separada e backups/restore testados, aceite de cliente oficial Portal, verificação real de DNS/digest/publicação, CRM captação dupla com T0 imutável, LGPD e autorização final.
5. O arquivo `config/operational-readiness.json` continua `status=BLOCKED`, `non_operational=true`, dez flags falsas. Mesmo 100% dos jobs de CI aprovados **não liberam** produção.

## Provas esperadas no PR consolidado

- F1-240: inventário completo e contagem exata contra `dev` SHA fixo.
- F1-241: seis pacotes de revisão cobrindo todos os caminhos e classificando os sensíveis.
- PHP 8.3/8.4, Laravel, PostgreSQL 18, Chrome, contratos v3 e negativa de fonte Portal atual sem homologação.
- Preservar identidade original Node Imagine nos menus e shell Sites, nunca nos templates personalizados de clientes. Reversões e rollout operacional dependem de aprovação específica.

**Nenhuma API, conta, DNS, cliente, segredo, serviço, integração operacional, pagamento, merge ou deploy foi acionado nesta consolidação.**
