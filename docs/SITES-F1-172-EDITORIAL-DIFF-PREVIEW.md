# F1-172 — Visualização antes/depois de uma edição editorial no Laravel

A F1-171 criou a base de domínio para alterações parciais em conteúdo de sites sem reusar a revisão anterior. Nesta entrega, a equipe Imagine pode ver o efeito de um changeset fictício numa **nova página de revisão privada**, sem abrir um formulário público nem uma interface de edição de dados reais.

A rota /_lab/sites/v1/editorial-review é registrada apenas em PHP CLI com APP_ENV=testing, exigindo as flags SITES_SYNTHETIC_HTTP, SITES_SYNTHETIC_REVIEW_UI, SITES_SYNTHETIC_RICH_REVIEW e SITES_SYNTHETIC_EDITORIAL_REVIEW. Só aceita produto, dispositivo e tema em enums fechadas e IP loopback. Não recebe texto, cliente, upload, sessão ou credencial, nem permite qualquer alteração HTTP.

A rota monta a página v4 fictícia do arquivo de demonstração F1-170, simula uma aprovação interna com o fluxo de laboratório e aplica uma alteração pontual nos campos FAQ e processSteps usando SyntheticEditorialPatchV1. O contrato exige o digest SHA-256 e a revisão anteriores, e o plano volta ao estado DRAFTING sem aprovação; depois gera um novo rascunho privado com os mesmos bytes e digest do patch. Nenhuma aprovação do cliente é emitida.

A interface Laravel já existente apresenta **hash original, novo hash e campos alterados**, mantém a prévia isolada em iframe sandbox, o dossiê e o aviso de revisão pendente. Os modos anteriores não são alterados. A matriz PHP 8.3/8.4 verifica 2 produtos × 3 paletas × 2 dispositivos, hashes exatos, HTML inerte, cabeçalhos noindex/no-store/CSP, inexistência de botões de publicação e parâmetros/flags inválidas.

Este é um protótipo de fluxo editorial somente com dados sintéticos e sem autenticador; não constitui editor de produção nem libera coleta, CRM, aceite, VPS ou deploy. OP01/OP02/OP04 e 10/10 gates operacionais permanecem bloqueados.
