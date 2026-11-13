# F1-51 — Presets visuais para Onepage e Landing Page

O motor Sites já produz HTML estático determinístico e isolado, mas a prévia tinha apenas um estilo gráfico. Esta etapa adiciona **três presets conservadores** a `SiteBrief`, sem permitir CSS arbitrário do usuário ou execução de APIs externas:

- `classic`: renderização original **byte por byte idêntica** para briefings antigos, preservando sua identidade SHA-256.
- `modern`: paleta azul escuro, botões em formato pílula, cartões suaves, layout responsivo em grids.
- `warm`: paleta vinho/terra, fundo creme, cartões leves e grid responsivo.

O último argumento de `SiteBrief` é opcional e só aceita esses três valores. `StaticSiteRenderer` acrescenta um trecho CSS *hardcoded* somente para modern/warm, mantendo o modo classic inalterado. Nunca incorpora estilos fornecidos por cliente/IA, fontes externas, JavaScript, imagens ou URLs remotas. Os seis produtos/estilos geram SHA-256 determinístico próprio.

A suíte `tests/f51-style-presets.php` exercita ambas as páginas em todos os presets, manifestos `publishable=false`, HTML inerte, ausência de dados/URLs, CTA interno, responsividade, determinismo, compatibilidade de briefings antigos e rejeição de CSS/script malicioso.

Não representa personalização self-service, criação real por IA, coleta de leads, CMS, domínio ativo, SEO público ou produção. A escolha de tema pelo Portal só ocorrerá após contratos e controle de revisão por cliente. O rendering ainda exige testes visuais humanos para todas as paletas.

`main`, `dev`, VPS, Cloudflare, CRM, contratos e readiness continuam inalterados. PR draft dependente de F1-50 (#71); gates P0/RF-PEND/RV-CT/AppSec/LGPD abertos.
