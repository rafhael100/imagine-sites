# P0 — Política canônica Imagine isolada para revisão independente

Este PR é um subconjunto de código **pequeno e independente** dos candidatos maiores de Imagine Sites. Preserva a decisão de URL principal pública `https://dominio.com.br` sem `www`, com migração de `www` somente como **proposta offline**, nunca configuração automática de DNS ou redirect.

## Contratos

- `SyntheticCanonicalOriginPlanV1`: classes `public_site`, `campaign_subdomain`, `application` e `legacy_preserved`, com separação entre clientes, apps e campanhas; nega subdomínios reservados e sufixos públicos comuns na origem pública. **Não** consulta DNS, PSL completa ou dados de titularidade.
- `SyntheticSeoCanonicalMapV1`: gera mapa SEO de rascunho sem indexar, exigindo `operatorConfiguredOrigin` independente do array de proposta; `indexable=false`, `mayServe=false`.
- `SyntheticCanonicalRedirectReviewV1`: avalia hipoteticamente redirecionamento `www`→apex, exigindo `pinnedDestinationOrigin` independente; `mayExecute=false`, jamais envia resposta HTTP.
- Testes de contrato f287, f288, f289 e f325 PHP 8.3/8.4 verificam casos positivos, bloqueios de domínio forjado, URLs externas, pin incorreto, campanhas isoladas e preservação de site legado sem migração não autorizada.

## Por que separado

O candidato integrado Sites inclui mais de mil commits e muitos arquivos; este subconjunto altera dez arquivos de código/testes/workflows mais este documento, **diretamente contra a branch `dev`**. Ele reduz o escopo para avaliação independente da política de domínio, e não depende dos bancos, Portal, CRM, Laravel ou Clouds para ser testado.

**Limites:** não é uma mudança de domínio ou site real. Nenhum apontamento DNS, redirecionamento HTTP, publicação, aceite cliente, migration de domínio ou modificação de `www` ocorreu. A configuração fixa do projeto em integração futura terá de ser autenticada por autoridade do Portal, e dono/reviewer de produção devem aprovar. A issue #281 de proteção de branches e os dez gates `BLOCKED` continuam pendentes. Nenhum merge ou deploy automático será realizado.
