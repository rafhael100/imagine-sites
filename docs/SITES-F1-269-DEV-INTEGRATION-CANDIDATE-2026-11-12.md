# Imagine Sites — candidato integrado F1-269 para revisão de `dev`

**Estado DRAFT, sem merge, deploy ou autorização operacional. Data: 12/11/2026.**

- Base imutável `dev`: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. A origem PR #314 acumulava **1.114 commits ahead, 0 behind** em comparação com `dev` antes desta documentação.
- F1-266 / PR #310: Chrome CDP com bootstrap mais robusto e repetição estrita. 6/6 CI PASS.
- F1-267 / PR #311: verificações de segurança para variantes de deploy em GitHub Actions, permissões e infraestrutura. Workflow dedicado PASS.
- Candidato F1-267 / PR #312: **100/100 workflows PASS**, inventário total 672 caminhos alterados, 189 sensíveis, seis lotes técnicos gerados.
- F1-268 / PR #313: Ed25519 Base64URL canônico e expiração com limite exclusivo. 5/5 CI PASS.
- F1-269 / PR #314: nonce de uso único em **instância local apenas**, assinaturas, contagem/limite e testes de replay; workflow dedicado PASS. Não é persistência durável ou autorização.

## Revalidação obrigatória nesta branch

1. Executar novamente o inventário F1-240 completo do HEAD exato deste candidato; não reutilizar 672/189 do #312.
2. Gerar novamente seis pacotes F1-241 com seus hashes e apresentar os caminhos sensíveis para revisão AppSec, DBA, integração Portal/CRM, infraestrutura, testes e governança.
3. Executar 100% das matrizes de PHP/Laravel/PG18, Chromium, Docker, GitHub security e Node22, inclusive assinatura e replay, sem usar resultados antigos.
4. Preservar `config/operational-readiness.json` `status=BLOCKED` e seus 10 gates falsos, com relatório de CI auditável.
5. Configurar branch protection por administrador autorizado em `dev`/`main` (issue #281) e coletar aprovações humanas independentes antes de considerar merge.

## Fronteira rígida

Sem contato/lead real, PII, CRM de produção, URLs públicas, DNS/TLS, Cloudflare deploy, VPS, chaves privadas operacionais, gasto ou mudança nas branches `dev`/`main`. Assinaturas e nonce de laboratório não comprovam identidade, consentimento legal, revogação, T0 durável, publicabilidade ou efetivação do release.

Para produção, nonce exige ledger autenticado e persistente com atomicidade comprovada. O Worker exportado continua `fetch()` permanentemente HTTP 503.