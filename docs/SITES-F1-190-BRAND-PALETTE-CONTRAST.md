# F1-190 — Cores de marca opcionais e contraste mínimo do site

O motor estático de Onepage/Landing Page suporta uma personalização adicional: duas cores para o gradiente da abertura (hero), uma cor para o fundo do botão CTA e uma para o texto do botão. O restante do tema permanece controlado pelo preset Classic/Modern/Warm da Imagine. Essa escolha é feita fora do conteúdo de IA e ainda não permite envio de cores por clientes reais.

A classe de valor imutável SyntheticBrandPaletteV1::fromHex aceita **apenas quatro tokens hex RGB exatos #rrggbb**; recusa nomes CSS, valores com alfa, gradientes/URL, scripts, quebras de linha e qualquer declaração arbitrária. Todos os tokens são normalizados em minúsculas. O cálculo sRGB de luminância aplica um limite de contraste 4,5:1 ao texto branco sobre **ambas as pontas** do gradiente e ao par fundo/texto do CTA. O CSS inserido pelo StaticSiteRenderer é uma gramática fixa de três seletores, com os valores previamente validados. A cor da pequena chamada no hero passa a branco opaco para preservar legibilidade mesmo quando o tema Modern/Warm definia outra cor.

SiteBrief ganha brandPalette tipado e opcional, com valor padrão null: sem esse campo, as saídas HTML existentes são preservadas byte a byte. A opção é recebida por SyntheticStructuredDraftAssembly como seleção de layout separada; schemas de cópia por IA v1–v4 não aceitam brandPalette nem CSS. Mudanças de tema, ordem das seções e editor de texto preservam a identidade da paleta. O fluxo de revisão comum recusa alteração silenciosa das cores como parte de um patch editorial.

A suíte principal PHP 8.3/8.4 inclui casos de cores oceano, ameixa e floresta para ambos produtos e três presets (18 artefatos), contrastes, digests determinísticos, análise de HTML inerte, pacote não publicável, regressões de revisão e entradas maliciosas ou de baixo contraste. São cálculos WCAG AA para esses elementos específicos, não auditoria WCAG integral ou homologação de identidade visual.

Sem editor público, API, domínio, dados reais, uploads, recursos externos, credenciais, CRM, deploy, alteração de main ou custos. OP01/02/04 e 10/10 gates de produção seguem bloqueados.
