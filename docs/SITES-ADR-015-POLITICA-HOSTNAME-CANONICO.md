# ADR-SITES-015 — Política de hostname canônico para sites, landing pages e aplicações Imagine

**Decisão interna:** PADRÃO SEM WWW (apex-first), definido pelo titular do projeto em 12/11/2026.
**Status de execução:** DOCUMENTAL; migrações e publicação ainda NÃO AUTORIZADAS.
**Alcance:** Imagine Sites, Imagine Comercial, Portal, CRM, SEO Engine, Performance AI, Universidade, AI Agents e novos clientes.
**Importante:** este documento não modifica DNS, cria custos, publica páginas, ativa redirecionamentos ou aprova a migração de domínios existentes.

## Decisão de arquitetura

1. **Novo site público com domínio próprio e sem histórico indexado:** origem canônica sugerida `https://dominio.com.br/` (apex, sem `www`). O alias `https://www.dominio.com.br/` deve ser avaliado para redirecionamento permanente 301/308 **somente depois** de domínio verificado, TLS, plano de rotas, aprovação e teste em staging.
2. **Landing page dentro do site:** usar `https://dominio.com.br/campanha/` se fizer parte da mesma propriedade. Não criar `www` obrigatório.
3. **Landing page/campanha independente:** preferir `https://campanha.dominio.com.br/` ou `https://lp.dominio.com.br/`, sem `www.campanha`. Indexação/canonical exigem decisão editorial/SEO por projeto; não são consequências automáticas do tipo de página.
4. **Aplicações autenticadas e APIs:** `https://portal.dominio.com.br/`, `https://crm.dominio.com.br/`, `https://api.dominio.com.br/` e ambientes técnicos próprios, sem `www`; cookies host-only, autenticação e autorização por recurso.
5. **Sites legados de clientes:** host histórico (com ou sem `www`) é preservado até plano formal de migração SEO com evidências e aceite específico. A preferência global por apex **não** é autorização para fazer redirects ou trocar canonical em sites já indexados.
6. **Imagine Comercial legado:** a propriedade `www.imaginecomunicacao.com.br` possui histórico indexado; a candidata Astro foi preparada sem `www`. Não alterar host público agora. Estudar migração futura para `https://imaginecomunicacao.com.br/` por URL (incluindo os 27 caminhos legados), GSC, equivalência de conteúdo, staging e 301/308 homologado. Manter em HOLD até aprovação específica.
7. **Limitação técnica:** caso apex não possa ser provisionado com o DNS/Cloudflare for SaaS de terceiros sem recursos pagos, NÃO criar gastos nem alterar silenciosamente o canônico para `www`. Documentar incompatibilidade e solicitar solução técnica ou exceção explícita por projeto.

## Fundamentação e segurança

- O Google não privilegia `www` ou apex por si só: consistência de canonical, links, sitemap, redirects e estabilidade editorial importa mais.
- Apex não é um CNAME DNS ordinário; Cloudflare DNS oferece flattening em zonas que controla, mas Cloudflare for SaaS com DNS externo/apex pode exigir capacidades especiais. A política comercial não presume suporte universal ou plano Enterprise.
- Aplicações e websites têm origens separadas; host por si só não é prova de posse, autenticação, tenant ou consentimento. Cookies de sessão devem ser host-only por padrão, sem `Domain` compartilhado amplo.
- Nunca confiar no cabeçalho Host/Forwarded enviado pelo visitante para inferir canônico ou montar redirect sem validação por allowlist própria de domínio verificado.

## Modelo de configuração futura, por projeto

Registrar `canonicalOrigin` (URL HTTPS exata), `alternateOrigins` verificadas, `hostClass` (`public_site`, `campaign_subdomain`, `application`, `legacy_preserved`) e `approvalState`. A política sugere apex para novos sites públicos, mas NÃO emite redirect e NÃO aprova publicação. Host legado e exceções deliberadas precisam de aprovação específica e trilha imutável. O campo de hostname jamais será fonte de permissão para publicar, atender leads ou liberar cliente.

## Gates obrigatórios antes de publicar ou migrar

- Comprovar propriedade/controle de ambos os hosts, DNS suportado e certificados TLS válidos; não pressupor custo zero se o provedor exigir apex proxy especial.
- Conferir host de origem real e redirects com trajetória finita, preservação de caminho, query string somente quando segura, sem open redirect, troca de tenant, loops, cadeia ou downgrade HTTP.
- Conferir `rel=canonical`, sitemap, URLs OG e links internos; páginas legais e URLs sem correspondência ficam em HOLD e exigem revisão individual.
- Validar HTTPS, HSTS, cookies host-only, cache, segurança de headers e hostnames desconhecidos em staging sem cliente real.
- Capturar baseline de SEO/GSC e plano de rollback manual. Execução de DNS, 301/308, Cloudflare, prod, `main` e dados pessoais requer autorização operacional e evidência separadas.

## Casos de teste obrigatórios

1. Novo `dominio.com.br` sugere apex HTTPS, `www.dominio.com.br` como alias apenas candidato.
2. `www` legado aprovado permanece canônico e não muda sem ato específico de migração.
3. Landing page de subdomínio, Portal, CRM e API não recebem prefixo `www`.
4. Host inválido, credenciais no URL, porta pública inesperada, HTTP como canonical, Unicode confusável, IP literal, nomes com sufixo malicioso e múltiplos redirects são recusados.
5. Fluxos e testes sintéticos jamais alteram DNS, ativam proxies, publicam conteúdo, criam conta paga ou tornam `operational-readiness` verdadeiro.

## Referências técnicas

- Google Search Central: https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls
- Cloudflare Workers Custom Domains: https://developers.cloudflare.com/workers/configuration/routing/custom-domains/
- Cloudflare for SaaS: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/
- Cloudflare DNS CNAME flattening: https://developers.cloudflare.com/dns/cname-flattening/set-up-cname-flattening/
- MDN Set-Cookie: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie

**Status final:** política aprovada como padrão editorial/arquitetural; NÃO IMPLEMENTADA em DNS, app ou publicação. Gates operacionais continuam BLOCKED.
