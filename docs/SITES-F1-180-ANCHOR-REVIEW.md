# F1-180 — Comparar menu interno ligado/desligado no revisor Laravel

A F1-178 adicionou à Onepage um menu opcional apenas para seções presentes. A F1-179 validou o menu no Chromium. A F1-180 disponibiliza uma forma de a equipe Imagine **comparar visualmente as duas opções**, sem conceder edição pública ou poder de publicação.

A nova rota /_lab/sites/v1/anchor-navigation aceita GET somente em CLI/testing/loopback e com as quatro flags HTTP sintético, UI sintética, revisão rica e SITES_SYNTHETIC_ANCHOR_REVIEW=1. Produto, tema, dispositivo e opção on/off estão em enums estritos; nenhuma propriedade arbitrária, texto, lead ou dado pessoal é recebida.

Para Onepage, é possível alternar o menu Ativada/Desativada, preservando o dispositivo e o tema. A Landing Page aceita somente o modo desativado, e a navegação do revisor ajusta o parâmetro ao alternar de produto, sem conduzir a um estado impossível. O contrato v4 fixo, origem e marca permanecem fora da IA, e cada escolha produz release/hash SHA-256 próprios com revisão interna e aceite do Portal pendentes.

A matriz Laravel PHP 8.3/8.4 testa combinações de produto/tema/dispositivo, hashes exatos dentro do iframe sandbox, ausência de scripts e formulários, headers CSP/noindex/no-store, bloqueio de parâmetros inválidos e IP externo e compatibilidade com as rotas anteriores. Não há publicação, cliente, coleta, CRM, deploy nem custos. OP01/02/04 e dez gates operacionais continuam bloqueados.
