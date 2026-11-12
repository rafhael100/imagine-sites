# F1-233 — proteção das rotas `/_lab/sites/v1/*` mesmo com cache Laravel

## Falha de defesa em profundidade identificada

O `apps/sites/routes/web.php` só registra os endpoints de laboratório em processo PHP CLI, ambiente `testing` e flag `SITES_SYNTHETIC_HTTP=1`. Essa verificação impede o registro normal em produção, mas **não é uma proteção suficiente para um cache de rotas já produzido por outro processo**. Um arquivo de rotas compiladas pode ser carregado sem executar novamente a guarda localizada no início de `routes/web.php`.

## Implementação

Um novo middleware **global** `App\Http\Middleware\SyntheticLabRouteFence` é instalado no `bootstrap/app.php`. Em toda requisição cujo caminho seja `_lab/sites/v1` ou pertença ao prefixo `_lab/sites/v1/`, exige:

- `PHP_SAPI==='cli'` **no processo que está atendendo a requisição**, não somente no processo que criou as rotas;
- Laravel ainda em `testing` e flag `SITES_SYNTHETIC_HTTP=1`;
- endereço de origem HTTP efetivo e `REMOTE_ADDR` ambos em `127.0.0.1` ou `::1`.

Qualquer falha retorna **404 antes de alcançar o handler**, sem imprimir dados de requisição. Outros caminhos, como `/up`, passam pelo middleware sem restrições adicionais. **O middleware não autoriza** nenhum comando: cada endpoint mantém as suas regras de contrato, replay e autoridade fictícia/negação.

## Regressão

O teste `SyntheticLabCachedRouteFenceTest` registra as rotas com a flag ativa e, com o mesmo roteador em memória (simulando a persistência do cache), revoga a flag e muda o ambiente para `production`. O resultado passa a ser 404 em vez de permitir que o handler responda. Verifica também IP remoto/alegação de proxy, namespace completo, caminhos fora do escopo e `/up` intacto.

O safety guard `ci/f21_safety_guard.py` passa a exigir a instalação e a presença da barreira, enquanto a CI Laravel testa/linta a classe nas matrizes PHP 8.3/8.4.

**Limites:** a regressão em memória não substitui um teste HTTP FPM real, regras de proxy de produção, um auditor externo ou um teste de artefato de cache em servidor implantado. A proteção é fail-closed, mas os gates de segurança/P0 e a autorização de go-live continuam `BLOCKED`. Nenhum acesso a cliente, API operacional, VPS, Cloudflare, DNS, SSL ou segredo foi realizado.
