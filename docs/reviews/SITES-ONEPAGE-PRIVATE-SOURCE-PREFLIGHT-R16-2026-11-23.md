# OnePage R16 — impedir leitura privada antes da elegibilidade do comprador (somente laboratório)

## Lacuna concreta e recorte

A candidata `src/Production/OnepageBuyerPrivatePreviewCandidate.php`, herdada do Sites #619 (`cbf7826cb11df72a5046753cb5ebef4859058eb8`), consultava `readExact()` (briefing privado) **antes** de consultar o head autorizado, o comprovante de direitos do comprador e a aprovação editorial. Uma tentativa por outro comprador **da mesma empresa** era negada ao final, mas a fonte privada já sofria uma leitura.

R16 antecipa o head corrente e as testemunhas **já existentes** (direitos do comprador e revisor distinto), sem novos contratos, identidade, privilégios ou parser. Rejeita pedido/tenant/site/versão divergentes, ausência de direitos e aprovação revogada **antes** de `readExact`. Após a leitura, confere que a seleção obtida dos bytes reais é **exatamente** o head previamente verificado. Reconsulta briefing, head e duas testemunhas depois de renderizar, negando mudanças observadas. Não é um bloqueio transacional/CAS; origem e identidade continuam responsabilidade de adaptadores oficiais ainda não instalados.

## Provas novas isoladas

`tests/onepage-private-source-preflight-r16.php` reaproveita a fixture OnePage e o renderizador anteriores; conta chamadas às quatro fontes para demonstrar que:

- outro comprador da mesma empresa nega com **0 leituras privadas e 0 consultas ao revisor**;
- outro tenant/pedido/site/versão nega com **0 leituras privadas e 0 testemunhas humanas**;
- declaração de direitos ausente, confirmação ausente, revogação ou aprovação ligada a outra confirmação negam com **0 leituras privadas**;
- o caminho positivo usa **2 leituras por porta**, gera os mesmos bytes HTML e SHA e mantém `clientFinalAccepted=false`, `publicationAuthorized=false`, `canPublish=false`.

O workflow específico fixa branch/base/SHA, roda PHP 8.3 e 8.4, o teste R16 e a regressão de aceite final afetada, sem dados pessoais, segredos, PSP, worker, rede externa ou publicação. **Não** reapresenta as regressões Chromium R05–R15 como avanço novo.

## Limites e próxima dependência

O head, a identidade de comprador, a confirmação de direitos, a revisão e a revogação são fontes sintéticas injetadas no teste; **nenhum serviço operacional foi conectado**. A leitura prévia do head também necessita ser autorizada no produtor real; o código não verifica login nativo. Sem fonte oficial Laravel/PostgreSQL atestada para os mesmos bytes/revisão no Portal #976, manter a integração fechada. Portal #1003/Sites #552 são precedentes de contrato, não autoridade. O worker OnePage da central ainda não apresenta atestação conjunta de identidade, rota, modelo, saída autorizada e credencial específica — nenhuma chamada API OpenAI nesta entrega.

Landing Page permanece separada; etapas 14/15 e VEN não recebem evidência. Não habilitar checkout, Asaas, Cloudflare, DNS, VPS, deploy, merge, publicação nem rollback automático. **Sites #38 NO-GO**.
