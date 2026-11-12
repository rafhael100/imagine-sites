# F1-181 — Personalização combinada do layout da Onepage

A interface privada Laravel passa a permitir escolher **simultaneamente** a ordem das seções (F1-175/176) e a presença dos links internos de navegação (F1-178/180). Antes, alternar um dos controles voltava ao valor padrão do outro. Agora os controles preservam mutuamente a escolha.

A rota /_lab/sites/v1/layout-options existe exclusivamente em PHP CLI/testing, IP loopback, com flags SITES_SYNTHETIC_HTTP, SITES_SYNTHETIC_REVIEW_UI, SITES_SYNTHETIC_RICH_REVIEW, SITES_SYNTHETIC_ORDER_REVIEW, SITES_SYNTHETIC_ANCHOR_REVIEW e SITES_F181_COMBINED_LAYOUT. Parâmetros válidos: produto, tema, dispositivo, uma das seis ordens e menu on/off. Landing Page aceita apenas off. A interface evita gerar link inválido ao trocar Onepage on por Landing Page.

Todas as composições usam a fixture sintética v4 fixa, a identidade da empresa não vem da IA e nenhum parâmetro permite alterar dados do cliente. O renderer gera HTML hash-pinned, dossiê e revisão pendente, noindex, iframe sandbox, CSP e nenhuma permissão de publicação ou coleta. São 54 versões de conteúdo distintas: 36 Onepages com menu on/off e 18 Landing Pages sem menu; cada uma tem visualização desktop e mobile.

Testes Laravel PHP 8.3/8.4 cobrem 108 respostas, SHA-256 exato, seis ordens do DOM, preservação dos parâmetros em ambos os seletores, rejeição de opções/roles/flags inválidas, e isolamento das rotas legadas.

Não é autenticação, editor de cliente, integração CRM real, aceite, homologação ou publicação. OP01/02/04 e os dez gates operacionais permanecem bloqueados; sem merge, VPS, DNS, Cloudflare ou custos.
