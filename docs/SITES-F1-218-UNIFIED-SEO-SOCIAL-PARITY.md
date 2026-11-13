# F1-218 — Uma única paridade verificável para visual, cartão social e prévia SEO

F1-213 já garantia igualdade entre HTML mostrado no revisor Laravel e os quatro arquivos exportados. F1-217 acrescentou readback textual de SEO a partir desses arquivos. Esta entrega transforma os dois controles em **uma verificação única**, impedindo que a tela use metadados produzidos por uma leitura diferente do arquivo privado conferido.

`SyntheticVisualReviewExportParityV1::inspect` passa a chamar também `SyntheticPrivateSeoSnippetReadbackV1::inspect` sobre o mesmo `index.html`, manifesto, resumo e dossiê. Exige que SHA-256 e releaseRef coincidam com o resultado visual e com o cartão social OG/Twitter, caso ativado. A nova propriedade `seoSnippet` do relatório contém o título, descrição, contagem UTF-8 e observações editoriais, ainda **noindex e não publicáveis**.

A rota privada `/_lab/sites/v1/composite-social` deixa de gerar uma segunda inspeção SEO independente e usa somente `$parity->seoSnippet`. Ela ainda recusa qualquer relatório com permissão de indexação, publicação, escrita no Imagine SEO Engine, requisição externa ou aceite oficial, mantendo proteção de CLI/testing/loopback/flags e iframe sandbox.

O teste PHP F1-218 percorre 24 variantes de Onepage/Landing, tema, paleta e cartão social on/off. Confere paridade dos mesmos bytes, SEO sempre presente mesmo quando não há cartão social, ausência de URL/canonical público e mutações maliciosas em HTML, title, manifesto e aceite de cliente. O teste foi incorporado ao Sites Engine PHP8.3/8.4 de PR e main; Laravel F1-03 verifica a tela e a identidade de HTML.

O sistema ainda **não pesquisa palavras-chave, cria indexação, consulta o Google, gera imagens, conecta ao Imagine SEO Engine, publica sites ou envia leads ao CRM**. Todos os dados são inventados e permanecem privados e sem permissão operacional. OP01/02/04 e dez gates operacionais de produção continuam bloqueados; sem merge, infraestrutura real, credencial ou gastos.
