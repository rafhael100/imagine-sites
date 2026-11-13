# F1-210 — Cartão social de texto conferido contra o HTML exato

A F1-206 introduziu metadados Open Graph e Twitter somente para texto; a F1-208 permitiu comparar ligados/desligados no revisor privado; a F1-209 passou a armazenar o HTML correspondente em pacote não publicável. A F1-210 acrescenta uma **visualização textual real** (título, descrição e marca) ao revisor, sem carregar a página em redes sociais, sem imagem remota, sem URL pública e sem buscar nenhum recurso externo.

A classe `SyntheticSocialCardInspectionV1` valida o SHA-256 dos bytes do artefato, reexecuta o renderizador canônico e analisa o HTML com DOMDocument em modo LIBXML_NONET. Só admite os **sete metadados sociais exatos** — og:type, og:locale, og:title, og:description, twitter:card, twitter:title e twitter:description — e valores coerentes com o briefing. Detecta duplicação, divergência, metadata inesperada ou conteúdo alterado; não tem capacidade de publicar, abrir imagens, compartilhar nem conceder aceite do cliente.

A interface Laravel F1-208 recebe o resultado tipado e mostra um cartão privado somente se a opção estiver ativada. A marcação é escapada por Blade, mantém o SHA visível e declara explicitamente que é uma simulação sem envio às redes sociais. Com a opção desativada, não existe cartão nem CSS específico. As outras rotas de revisão não herdam o cartão.

Testes PHP 8.3/8.4 executam 24 combinações de produto, tema, ordem e paleta com casos adulterados. Testes Laravel conferem 12 telas ativas, a equivalência com as tags OG e Twitter no iframe, privacidade, CSP, ausência de HTML ativo e regressões das versões sem cartão.

Escopo privado de laboratório, somente dados fictícios. Nenhum serviço de rede social, imagem, domínio real, lead, CRM, Portal operacional, deploy ou despesa. OP01/OP02/OP04 e dez gates de produção continuam bloqueados.
