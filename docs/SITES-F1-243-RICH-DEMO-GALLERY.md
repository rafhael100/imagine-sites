# F1-243 — galeria de demonstrações enriquecidas com recursos atuais do motor

A galeria inicial F1-238 exibia apenas título, benefícios e contato **inerte**, mesmo quando o motor estático do Imagine Sites já suporta conteúdo estruturado, serviços, FAQ, processo, navegação de seções, SEO e metadados de prévia social. F1-243 aplica esses recursos de forma funcional, sem nenhum acesso operacional.

## Implementação

O gerador privado `ci/f238_generate_demo_gallery.php` continua produzindo as mesmas seis combinações de produto/visual (Onepage e Landing Page × Classic, Modern e Warm), mas agora usa o contrato **v4 de copy sintética** já versionado em `fixtures/sites/f1-170-process-copy.synthetic.json` e a marca fictícia **Aurora Exemplo**. Não gera ou solicita conteúdo a fornecedor de IA.

Cada HTML inclui:
- apresentação do serviço e benefícios com CTA **ancorado localmente** (nenhum formulário);
- **duas soluções em destaque**, **três etapas de produção** e **duas perguntas frequentes**;
- navegação adicional para seções existentes nas Onepages; Landing Pages preservam CTA local simplificado;
- `<title>`, meta description, sete metadados estáticos OG/Twitter, `noindex,nofollow` e CSP de prévia.

O HTML permanece gerado integralmente pelo `StaticSiteRenderer`, usando `SiteBrief` validado, sem aceitar HTML/CSS arbitários. Todas as seções passam por `InertPreviewAudit` e `SyntheticPreviewQaReport`. O `manifest.json` de seis arquivos registra seções, navegação e metadados esperados, além de SHA-256 dos bytes realmente produzidos.

## Teste funcional independente

`ci/f243_verify_rich_demo.php` abre **cada um dos seis HTMLs usando DOMDocument** e confere contagem/ordem estrutural das seções, serviços, etapas e FAQ; metadados sociais e `noindex`; navegação por produto; ausência de `<script>`, `<form>`, `iframe`, inputs ou qualquer destino externo; checksums e permissões no manifesto.

Os workflows [F1-238](../.github/workflows/sites-f1-238-demo-gallery-artifact.yml) e [F1-239](../.github/workflows/sites-f1-239-demo-screenshots.yml) rodam esses testes e geram novos artefatos temporários de **galeria HTML** e **12 screenshots reais do Chromium** nos formatos desktop/mobile, cada um retido por sete dias.

**Não constitui:** hospedagem, site de cliente, aceite do Portal, publicação Cloudflare, integração CRM, configuração de domínio, IA paga, certificação de acessibilidade ou autorização operacional. Todas as páginas continuam privadas, fictícias e `publishable=false`. Os dez gates de produção permanecem `BLOCKED`.
