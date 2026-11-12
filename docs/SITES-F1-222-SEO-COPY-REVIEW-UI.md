# F1-222 — Comparar SEO automático e SEO personalizado na revisão Laravel

O recurso de cópia SEO independente F1-220 e o plano de revisão F1-221 agora têm uma demonstração visual controlada no revisor Laravel. A nova rota `/_lab/sites/v1/seo-copy-review` oferece modos **Automático** (título e descrição derivados do conteúdo da página) e **Personalizado** (dois textos editoriais fictícios fixados no código), com compartilhamento OG/Twitter on/off. Tema, produto e dispositivo podem ser alternados sem perder a escolha.

Em modo Personalizado, o servidor parte de uma versão sintética internamente revisada, executa `SyntheticSeoCopyRevisionPlan::begin`, exige novo SHA-256 e workflow DRAFTING sem aprovação, e só então renderiza o candidato novamente para revisão privada. **Não é uma continuação persistida do workflow, nem aceite verdadeiro de alguém.** A versão resultante mantém H1/hero e marca intactos e muda apenas os metadados de head e cartão social opcional.

A prévia de busca é gerada exclusivamente pelo readback de quatro arquivos canônicos em memória, usando `SyntheticPrivateReviewPackageV1` e `SyntheticPrivateSeoSnippetReadbackV1`. Mostra título, descrição, contagens, SHA-256 e alertas editoriais, sem URL, imagem externa ou consulta Google. Iframe sandbox e todas as rotas antigas continuam protegidos.

O endpoint só responde em PHP CLI/testes com IP loopback e flags `SITES_SYNTHETIC_HTTP`, `SITES_SYNTHETIC_REVIEW_UI`, `SITES_SYNTHETIC_RICH_REVIEW`, `SITES_F222_SEO_COPY_REVIEW`. Os parâmetros aceitos são enums fechados de produto, tema, dispositivo, modo SEO e cartão social. Nenhum texto fornecido via query, formulário, upload ou cliente é processado.

A suíte Laravel PHP 8.3/8.4 inspeciona 48 páginas, igualdade do SHA com iframe, noindex, sem formulário/script, duas opções de SEO, cartão social on/off, navegação e negação de parâmetros/flags/IP inválidos. OP01/02/04 e dez gates de produção seguem bloqueados; nenhuma publicação, indexação, Google, Imagine SEO Engine/CRM, cliente, merge ou deploy.
