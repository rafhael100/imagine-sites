# OnePage R21 — autorizar briefing antes de ler apresentação HTML privada

## Evidência test-first (somente laboratório)

Base imutável [Sites #624](https://github.com/rafhael100/imagine-sites/pull/624) `a7d7b416e21f605de6ac971f7ff853372c584400` (4/4 workflows SUCCESS na SHA) e [Portal #1097](https://github.com/rafhael100/imagine-portal/pull/1097) `b7a4510f544010f72e83ca27e0c7dafa2c388b27` (6/6 SUCCESS). Nenhum sucessor OnePage R21 identificado no preflight. Branch nova isolada. Sites #541/#542, #552, Portal #1003/#976 e Agents #490 são precedentes preservados, não autoridade operacional.

A rotina `OnepageExactFinalAcceptanceReadCandidate::observe()` pedia `currentPresentation(...)` (que retorna `servedHtmlBytes`) **antes de** `preview->render()` validar a confirmação corrente do comprador, os direitos e a aprovação/revogação editorial da revisão exata. A negativa final não elimina o efeito de ter consultado desnecessariamente a fonte privada. Não é o defeito R20 de `echo` dentro de uma porta.

Regressão nova `tests/onepage-acceptance-presentation-preflight-r21.php` usa a única fixture de pedido fictício e HTML já admitida em #604 sem reexecutar o conjunto antigo durante a fase test-first. Na SHA `51e754b10dbda3b078aeafce76e4b3a37e7dd487`, [CI vermelha 45137099062](https://github.com/rafhael100/imagine-sites/actions/runs/45137099062) em PHP 8.3 e 8.4, ambas falhando especificamente porque a aprovação do briefing estava revogada e a porta de apresentação privada **já havia sido consultada**.

## Mudança e prova

A fonte independente de apresentação (incluindo bytes HTML) só é lida **depois** da validação da prévia corrente e da correspondência com o release. A releitura posterior de release, recibo e apresentação continua exigida; não se cria aceite final, publicação, autenticação de navegador, permissão ou saída HTTP.

O novo teste cobre revisão revogada, bytes do briefing com digest divergente (ambos exigem **zero** consultas à apresentação), além do positivo que exige duas leituras e `clientFinalAccepted=true` sem `canPublish` ou `publicationAuthorized`. A correção `bcebff5a773e825d8cb5f78bf1db22cdea7efdbf` passou em [CI específica 45137204129](https://github.com/rafhael100/imagine-sites/actions/runs/45137204129), PHP 8.3/8.4. A CI da HEAD documental subsequente deve ser consultada separadamente; o workflow também reexecuta a regressão de aceite afetada por essa alteração.

## Limites preservados

A fonte oficial Laravel/PostgreSQL de revisão imutável do briefing, bytes, confirmação autenticada dos direitos e aprovação/revogação independente do revisor ainda **não está demonstrada** no contrato Portal #976. A inspeção de duas leituras não é CAS/lock transacional. As fixtures não são oficiais; domínio só na publicação, e prévia privada, aceite do cliente e autorização de publicação seguem separados.

Sem chamada OpenAI real (faltam identidade, modelo, rota privada, egress e credencial segregada atestados), sem novo adaptador de fornecedor, sem Landing Page, CRM/VEN, Asaas, checkout, VPS, main, merge, deploy, DNS, Cloudflare ou publicação. **Sites #38 permanece NO-GO.**
