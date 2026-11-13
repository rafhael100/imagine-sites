# F1-226 — Comparação forte: SEO personalizado não altera corpo, estilo ou segurança

Um título e descrição SEO próprios devem alterar apenas os campos esperados no `<head>`. Esta entrega confere a regra no nível dos bytes do HTML privado já construído, inclusive quando OG/Twitter está ligado.

`SyntheticPrivateSeoCopyDiffV1::inspect` recebe o pacote canônico de SEO automático e o pacote de SEO personalizado, acompanhados de briefings de origem esperados. Ambos passam primeiro por `SyntheticPrivateSeoSnippetReadbackV1` (manifesto, dossiê pendente, SHA-256, HTML renderer, `noindex`, título, descrição e cartão social). O comparador exige produto, marca, H1, benefícios, FAQ, serviços, etapas, tema, paleta, navegação e opção social **idênticos**; apenas `seoTitle` e `seoDescription` podem mudar, ambos da forma automática `null` para textos validados.

A comparação neutraliza **exatamente duas posições** de metadados sem cartão social (`title` e `meta description`) ou seis posições quando social está ligado (acrescenta OG e Twitter title/description). Em seguida compara o documento HTML inteiro byte a byte. Portanto, mudança em corpo, h1, estilos, links, robots, tema ou qualquer outra parte é recusada. Se a quantidade de campos SEO mudar ou o documento não for canônico, também falha. O resultado é somente informativo, nunca uma aprovação, indexação ou publicação.

A matriz de domínio usa os pares dos pacotes F1-223 em 2 produtos × 3 temas × 2 modos sociais: 12 diferenças válidas, com negação de versões invertidas, mesmas versões, `robots=index`, atributos de body alterados, H1 adulterado e troca de opção social. O teste fica permanentemente no workflow principal Sites Engine para PHP 8.3/8.4, sem criar um novo workflow pesado.

Este teste é de HTML estático fictício, não Google Search, servidor de SEO real, permissão do cliente, CRM, homologação operacional nem publicação. OP01/02/04 e os dez gates permanecem bloqueados; sem merge, VPS, DNS, Cloudflare ou despesas externas.
