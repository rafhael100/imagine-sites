# F1-208 — Revisão privada de metadados sociais ligados/desligados

A interface Laravel de revisão agora possui uma nova comparação para os metadados textuais de compartilhamento Open Graph/Twitter das Onepages e Landing Pages. A rota `/_lab/sites/v1/social-preview` aceita somente GET em PHP CLI, APP_ENV=testing e IP loopback, com as flags `SITES_SYNTHETIC_HTTP`, `SITES_SYNTHETIC_REVIEW_UI`, `SITES_SYNTHETIC_RICH_REVIEW` e `SITES_F208_SOCIAL_REVIEW`. Não está disponível em HTTP público e não recebe texto, URL, imagem, dados de cliente ou autorização.

A equipe consegue alternar entre **Desativados** e **Ativados**, preservando produto, tema e dispositivo na visualização. A fonte de conteúdo permanece a fixture v4 sintética fixa. O modo desativado usa diretamente um rascunho original, sem inventar aprovação anterior. O modo ativado simula uma revisão interna apenas no laboratório, exige a nova revisão de domínio F1-207, compara os bytes e SHA-256 da nova renderização e devolve uma prévia pendente de revisão. O painel mostra SHA anterior, SHA novo e revisão reaberta.

A prévia nova contém exclusivamente as sete tags adicionais permitidas. Nos dois modos permanecem `noindex,nofollow`, CSP, iframe sandbox, ausência de scripts/forms e estado `publishable=false`. Não há `og:url`, `og:image`, CDN, domínio público ou conexão com rede social.

O teste Laravel PHP8.3/8.4 cobre 24 visualizações (2 produtos × 3 temas × 2 opções × 2 dispositivos), seis identidades antes/depois, headers e SHA-256 exato, preservação de seletores, recusa de query arbitrária, IP externo e flags ausentes. As rotas anteriores não recebem essa configuração automaticamente.

Isso ainda é um protótipo para revisão privada. A publicação futura exige identidade autenticada, domínio e URLs verificados, AppSec, LGPD e gates OP01/02/04; nenhuma integração com CRM ou deploy foi iniciada.
