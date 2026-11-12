# Imagine Sites F1-287 — Política canônica sem www, sem publicação

**12/11/2026 — Desenvolvimento sintético em branch isolada, sem merge/deploy.**

## Motivação e fronteiras
Aplicar a decisão transversal do titular do projeto: **origens HTTPS sem www** para novos sites públicos e landing pages, mantendo aplicações nos subdomínios próprios. O ADR-SITES-015 fica registrado no PR #347, com execução ainda bloqueada. Hosts já indexados, especialmente o legado de `www.imaginecomunicacao.com.br`, não podem ser migrados apenas por alteração de preferência arquitetural.

## Mudanças
- `SyntheticCanonicalOriginPlanV1::propose` recebe a configuração administrada do projeto (nunca Host/Forwarded recebido do cliente HTTP), avalia a classe de host por lista fechada e devolve uma **proposta não operacional**.
- Novo `public_site` sugere `https://dominio.com.br`, registra `https://www.dominio.com.br` como candidato alternativo **sem gerar redirecionamento**.
- `campaign_subdomain` e `application` usam somente rótulos aceitos; nenhum `www` adicional.
- `legacy_preserved` retém o origin HTTPS explícito dentro do domínio de projeto, seja apex, www ou subdomínio histórico simples. Não autoriza migração ou redirect.
- Input não normalizado/ambíguo é negado: userinfo, URL arbitrária, port, path, HTTP, uppercase, unicode, IP, sufixo enganoso, host com prefixo `www` no lugar de domínio-base e tokens de injeção. A aplicação real deverá validar posse com uma autoridade de domínio externa, não com esta função.
- Todos os resultados incluem `mayPublish=false` e `mayRedirect=false`; nenhum valor deste componente aprova contrato, domínio, certificado, TLS, CRM, DNS, redirect, rollback, storage ou release.

## Testes / verificação
- Executar `php tests/f287-apex-canonical-policy.php`.
- CI dedicada PHP 8.3/8.4 e inclusão na `synthetic-php.yml` existente. Verificar o HEAD do PR; não herdar resultado de execução de branch anterior.
- Revisão de código e integração com Portal/SEO, mantendo a árvore `dev/main` intacta até proteções e revisão independente (issue #281).

## Pendências para entrega operacional
Continuam **dez gates falsos e status BLOCKED** em `config/operational-readiness.json`; não há publicação autorizada. Antes de ativar hostname real: comprovar domínio/registrador, Cloudflare/DNS externo compatível com apex, TLS dos dois hosts, SEO/canonical/GSC, redirecionamentos 301/308 em staging, proteção contra Host-header injection e autorização humana específica. Nenhum cliente real, gasto, Cloudflare, VPS, API externa, PII, credencial, deploy ou merge nesta etapa.
