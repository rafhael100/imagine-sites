# Imagine Sites — candidato de integração F1-258 em dev (12/11/2026)

**Natureza:** candidato de revisão total, PR draft, sem merge, deploy, produção ou alteração de `dev`/`main`.

## Rastreabilidade

- Base `dev` verificada: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3` (não protegida, issue #281). `main`: `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.
- Origem: PR #299 (F1-258), `b88914709e009bd21f08466348493b9cb85692d8`, contra o `dev` atual: **1046 commits à frente, zero atrás**, antes desta documentação.
- Candidato anterior: PR #292 (F1-251). Este candidato acumula por ancestralidade e não substitui revisão independente dos commits ou do diff.
- O endpoint `compare` do GitHub limita a lista de arquivos a 300 caminhos. A evidência completa deve ser gerada em CI a partir do mesmo HEAD por `ci/f240_integration_change_inventory.py` e `ci/f241_six_integration_review_packets.py`.

## Entregas adicionais F1-252 a F1-258

| Entrega / PR | Mudança implementada | Evidência no próprio head |
| --- | --- | --- |
| F1-252 / #293 | Dockerfiles CI com lista exata de COPY, sem ADD e scripts extras | Workflow Docker e suítes sintéticas aprovados |
| F1-253 / #294 | CTA secundário local, opcional e controlado pela equipe, sem alterar páginas anteriores | 23/23 workflows aprovados |
| F1-254 / #295 | Comparador privado Laravel de CTA, com 24 visualizações e hashes | 23/23 workflows aprovados |
| F1-255 / #296 | 36 screenshots Chrome full-page e testes reais de teclado/toque/reflow | 6/6 workflows aprovados |
| F1-256 / #297 | Evidência imutável da diferença CTA-only com SHA-256 e testes negativos | 24/24 workflows aprovados |
| F1-257 / #298 | Conferência byte a byte dos quatro arquivos privados de cada revisão | 7/7 workflows aprovados |
| F1-258 / #299 | Integração da evidência de pacotes privados no comparador Laravel | Validar todos os workflows no SHA de #299 e novamente neste candidato |

## Plano de revisão independente (sem autorização automática)

1. Gerar inventário F1-240 completo da base `dev` até o SHA exato do candidato, sem usar a lista truncada de 300 arquivos.
2. Gerar seis lotes F1-241, preservar hashes e atribuir revisões independentes: motor/UX; banco/segurança; integrações; infra/CI; testes; governança.
3. Revisar especialmente `SiteBrief`, HTML/CSP/noindex, revisão de conteúdo, contratos e segurança de rotas Laravel, Docker, SQL/RLS, autenticação/revogação, CRM, backups e políticas.
4. Configurar proteções reais para `dev` e `main` (issue #281), exigir checagens obrigatórias e revisão AppSec/DBA/Portal/CRM antes de qualquer decisão sobre merge.
5. Para produção: homologar OP01–OP05 e gate #38 com autoridade real do Portal, privacidade, restauração independente, DNS/TLS/Cloudflare, captação CRM consentida e aprovação expressa do cliente.

## Bloqueios preservados

`config/operational-readiness.json` deve continuar `status=BLOCKED`, `non_operational=true`, com dez gates negados. Nenhuma aprovação de revisão interna é identidade ou aceite do cliente. Não fazer merge, deploy, publicidade, indexação, cliente/lead real, serviço pago, DNS ou uso da VPS/Cloudflare nesta fase. `AGI-11-RB01` permanece desabilitado.