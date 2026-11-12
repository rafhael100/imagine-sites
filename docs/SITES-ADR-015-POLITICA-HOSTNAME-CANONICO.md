# ADR-SITES-015 — Política de hostname canônico para sites, landing pages e aplicações Imagine

**Status:** PROPOSTA DE PADRONIZAÇÃO TRANSVERSAL — aguardando aceite de arquitetura; somente documental.
**Data:** 2026-11-12
**Alcance:** Imagine Sites, Imagine Comercial, Portal, CRM, SEO Engine, Performance AI, Universidade, AI Agents e futuros sites de clientes.
**Importante:** este documento não aprova migração, modifica DNS, altera ambientes, cria custos ou autoriza publicação.

## Decisão recomendada

1. **Site público com domínio próprio, novo e sem histórico canônico:** usar `https://www.dominio.com.br/` como **host canônico do conteúdo**; o domínio apex `https://dominio.com.br/` será host alternativo para redirecionamento **301 ou 308** a partir de homologação, preservando caminho e parâmetros que forem seguros e apropriados. HTTP deve chegar ao destino HTTPS canônico com o menor número de saltos possível.
2. **Landing page no mesmo site e com finalidade SEO:** priorizar `https://www.dominio.com.br/campanha/` (ou caminho publicável equivalente) quando o cliente deseja concentrar o conteúdo no site atual.
3. **Landing page independente por campanha/subdomínio:** usar `https://campanha.dominio.com.br/` ou `https://lp.dominio.com.br/`, **sem** `www.campanha`; se indexável, ela terá canonical próprio aprovado. Se apenas captação/anúncios e não houver objetivo de indexação, decidir indexabilidade por página/cliente, não por convenção de host.
4. **Aplicações e interfaces autenticadas:** `https://portal.dominio.com.br/`, `https://crm.dominio.com.br/`, `https://api.dominio.com.br/`, `https://dev.dominio.com.br/`; **sem** prefixo `www`. Cada host tem políticas próprias de TLS, acesso, headers, cookies e autorização.
5. **Sites já publicados / clientes com SEO consolidado:** manter o host canônico existente (com ou sem `www`) **até** migração específica formalmente aprovada; nunca forçar a convenção nova causando mudança SEO silenciosa.
6. **Imagine Comercial (legado):** a propriedade indexada usa `https://www.imaginecomunicacao.com.br/` como homepage canônica; a origem Astro candidata foi construída sem `www`. Proposta é adotar o legado com `www` como host final para evitar mudança adicional, **mas somente depois de revisão de canonical, mapa de 27 caminhos legados, métricas/inspeção GSC, staging e homologação do cutover**.

## Justificativa técnica

- **SEO:** o Google não concede vantagem intrínseca ao prefixo; é essencial eleger um único canônico, emitir sinais consistentes e usar redirecionamentos permanentes quando apropriado, evitando duplicação e cadeias.
- **DNS/escala:** `www` é subdomínio normal e pode apontar por CNAME ao provedor SaaS. Isso facilita provisionamento para clientes que usam DNS externos. Apex é suportado pela Cloudflare (CNAME flattening) quando a zona usa esse DNS, mas **Cloudflare for SaaS com apex em DNS de terceiros pode requerer recursos adicionais/Enterprise**, a validar caso a caso; não presumir custo zero nem capacidade global.
- **Plataformas:** subdomínios distintos separam origem e facilitam aplicações independentes. `www` não é uma exigência para APIs, CRM e Portal.
- **Sessões:** usar cookies host-only (sem `Domain` amplo) por padrão; `www` sozinho **não garante isolamento de cookies, segurança ou tenants**. IAM, MFA, CORS e validação de host continuam exigidos.

## Critérios de implementação na plataforma Sites

### Regra de configuração, por projeto

Registrar explicitamente `canonicalOrigin`, `alternateOrigins`, `hostClass` (`public_site`, `campaign_subdomain`, `application` ou `legacy_preserved`) e `approvalState` na camada de domínio; não derivar autorização a partir da string do host. A criação de um novo site público pode **sugerir** `www`, mas a ativação depende de verificação do domínio, TLS, consentimento/aceite, política de DNS e processo de release.

### Gates antes de permitir tráfego externo

- Provar controle do domínio e validade do certificado para host principal e alternativo;
- Verificar a rota de origem e o redirecionamento com caminho preservado; impedir loop, cadeia, host externo ou abertura involuntária de app;
- Garantir `canonical`, sitemap, metadados Open Graph, dados estruturados e links internos consistentes; validar também `hreflang` e idiomas se existirem;
- Revisar todas as rotas antigas relevantes individualmente e manter páginas jurídicas, multilíngues e produtos sem equivalência em HOLD;
- Testar `www`/apex/HTTP/HTTPS e rollback em staging isolado; registrar aprovação de SEO, conteúdo, segurança e direção;
- Não executar migração DNS, Cloudflare, 301/308, main, dados reais ou deploy como efeito de aprovação deste ADR.

### Testes futuros obrigatórios

- Site novo: canonical `www`, apex alternativo e redirect somente após flag externa de publicação aprovada;
- Site legado `apex`: conservar canonical histórico sem alteração implícita;
- Campanha independente e portal/app: sem `www` adicional;
- Host desconhecido/inválido, mistura HTTP/HTTPS, header `Host` forjado, múltiplos redirects, parâmetros sensíveis e dados cruzados: falhar fechado;
- Nenhuma alteração de DNS, API Cloudflare, links reais ou publicação causada por testes.

## Fontes e observações

- Google Search Central — consolidação de conteúdo duplicado e migração permanente: https://developers.google.com/search/docs/fundamentals/get-started
- Cloudflare Workers — Custom Domains e redirects www/apex: https://developers.cloudflare.com/workers/configuration/routing/custom-domains/
- Cloudflare for SaaS — Custom Hostnames e Apex Proxying: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/
- Cloudflare DNS — CNAME flattening: https://developers.cloudflare.com/dns/cname-flattening/set-up-cname-flattening/
- MDN — cookies host-only: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Set-Cookie

**Status final deste ADR: NÃO IMPLEMENTADO.** Abrir entregas separadas por projeto e ambiente após aceite explícito, garantindo isolamento e rollback.
