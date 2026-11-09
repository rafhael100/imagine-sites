# SITES-F1-06 — Renderização estática e manifesto verificável (laboratório)

**Objetivo:** iniciar o motor determinístico de Onepages e Landing Pages Imagine a partir de briefing estruturado, ainda sem IA, conectores, publicação, dados de clientes ou aprovação real.

## Escopo implementado
- `SiteBrief`: aceita apenas conteúdo de texto estruturado, com limites e produtos `onepage`/`landing_page`.
- `StaticSiteRenderer`: HTML estático com layout responsivo, texto escapado, título/description, HTML semântico, CTA interno e componentes distintos conforme produto.
- `ReleaseArtifact`: conteúdo HTML com digest SHA-256 que identifica os **bytes exatos** da release.
- `ReleasePreviewPolicy`: comparação de digest do manifesto versus conteúdo entregue à prévia; **não** autoriza publicação.
- Prévia sintética marcada `noindex, nofollow`, CSP restritiva, sem scripts externos, sem formulários/analytics, sem URLs externas, sem importação de recursos de terceiros.

## Testes
`php tests/f6-run.php` verifica determinismo, integridade SHA-256, diferenças entre versões, escape de tags/atributos maliciosos, ausência de captação e links externos, acessibilidade básica, limites e produto.

## Segurança e limitações
Os exemplos fictícios **não** são sites de clientes nem demonstram produção por IA. Não há geração visual dinâmica por modelo, análise SEO real, assets, otimização de imagens, integração CRM, API autenticada, multiempresa real neste renderizador, hospedagem ou publish. O conteúdo ainda não foi avaliado com navegador, ferramentas de acessibilidade ou segurança de produção. Não usar `noindex` na futura publicação real por padrão; a política SEO será configurável depois da homologação.

A validação do digest, isoladamente, não substitui o aceite **expresso da versão exata** registrado pelo Portal, nem os requisitos de integridade do origin/artefato observado após Cloudflare deploy. `AGI-11-RB01` continua desabilitado e os gates P0/RF-PEND permanecem abertos.

Próximo passo seguro: conectar o gerador a um adaptador Laravel **somente de teste**, mantendo todos os endpoints HTTP de publicação fechados, com storage local descartável e sem efeitos externos.
