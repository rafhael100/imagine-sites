# Imagine Sites F1-289 — Prévia de mapa SEO canônico e sitemap (não operacional)

**12/11/2026 — F1-289, sequência de F1-287/F1-288; sem arquivos públicos, sitemap servido, DNS ou publicação.**

## Entregável

- SyntheticSeoCanonicalMapV1 recebe proposta de origem HTTPS e caminhos de páginas selecionados pelo operador; não lê URL/Host de visitantes.
- Em site público novo, gera candidatos rel=canonical para https://dominio.com.br/ sem www. Em campanhas independentes, usa o host específico; em legado, conserva o endereço histórico, inclusive www, com status HOLD.
- Emite string XML similar a sitemap apenas em memória, para revisão. Nenhum arquivo é implantado, nenhuma URL é entregue a robôs e nenhuma prévia deixa de ter noindex.
- Verifica caminhos com trailing slash, raiz obrigatória, lista sem duplicatas, até 100 caminhos de no máximo 512 bytes e caracteres ASCII mínimos. Não aceita query, fragmento, caminho com percent-encoding, dot segments nem URLs externas.
- Restringe classes de aplicações autenticadas e políticas manipuladas, preservando indexable=false e mayServe=false, sem transformar o booleano sintético em autorização.

## Qualidade e dependências

- tests/f289-seo-canonical-map.php verifica host apex, campanha, legado e casos negativos de segurança.
- CI PHP 8.3/8.4 dedicada e suíte sintética geral executadas com rede/produções desabilitadas.
- A geração de sitemap público real exigirá aprovação editorial, roteiro SEO, canonical/hreflang revisados, páginas legais, origem e certificado verificados, observação de publicação e revisão independente.
- Documento ADR-015 e PR #349 continuam as referências para a política de domínios; os dez gates de config/operational-readiness.json continuam BLOCKED.

**NÃO alterar noindex das prévias nem emitir qualquer conteúdo publicável com esta entrega.**
