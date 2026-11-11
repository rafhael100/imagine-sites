# F1-216 — Prévia editorial de busca na interface Laravel privada

A inspeção F1-215 passa a ser exibida na tela de revisão conjunta de design e metadados sociais F1-212. A interface mostra título, descrição, contagem de caracteres, prévia textual limitada e avisos editoriais por tamanho, todos vinculados ao **mesmo SHA-256** do HTML incorporado em iframe sandbox e dos quatro arquivos exportáveis.

A nova seção não consulta Google, Imagine SEO Engine ou serviços externos. Não inventa URL, domínio, posição, indexação, screenshot ou imagem social. O aviso é explícito: **a prévia permanece noindex e sem URL pública**; limites de tamanho são apenas heurísticas e não antecipam o que os mecanismos de busca realmente mostrarão.

A rota existente `/_lab/sites/v1/composite-social` continua em PHP CLI/testing/loopback com quatro flags sintéticas. Antes de renderizar, confere que a inspeção SEO retorna a mesma identidade de artefato do relatório de paridade F1-213 e que todas as capacidades operacionais (publicação, indexação, escrita no SEO Engine, acesso externo, aceite do cliente) são false. O Blade escapa título e descrição, não adiciona formulários ou scripts e usa nomes fechados para observações.

A suíte Laravel F1-03 executa `SyntheticSeoSnippetReviewTest` em PHP 8.3 e 8.4, cobrindo 24 páginas (Onepage/Landing × 3 temas × cartão social on/off × desktop/mobile), identidade SHA-256 exata, contagens UTF-8, texto equivalente à meta description e title reais, CSP/noindex, parâmetros maliciosos e a ausência do bloco nas rotas legadas. Os demais testes do revisor conjunto F1-212 seguem obrigatórios.

Resultado ainda **estritamente sintético**, sem cliente, leads, coleta, CRM, Google Search Console, deploy, DNS ou custo novo. OP01/OP02/OP04 e todos os dez gates operacionais permanecem bloqueados.
