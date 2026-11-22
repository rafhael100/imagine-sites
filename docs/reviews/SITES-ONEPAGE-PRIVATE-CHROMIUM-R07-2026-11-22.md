# OnePage R07 — mesmo HTML/SHA em nova geração de release (somente laboratório, NO-GO)

**Preflight em 22/11/2026:** PR Sites #610, HEAD `9e44b92112fb149acf98b57af9da41b1eb8b558a`, draft, com 4/4 workflows `success` para a SHA exata (R06 Chromium run 45007158330, F1-21 45007158245, F1-03 45007158274 e Sites Engine 45007158255). Sites #609 é a base histórica R05; #605 é um escritor independente de persistência PG18 e sua branch ficou intacta. Portal #1087 Landing e Agents #490 não foram alterados. #541/#542 são referências históricas, não fontes de autoridade.

## Lacuna nova e recorte implementado

R06 comprovou browser Chromium em loopback com versões A/B de **SHA distintos**. Esse verde não demonstra que uma **nova geração/release usando bytes HTML idênticos** obriga nova apresentação e novo clique. Neste ensaio, a versão C usa os mesmos bytes e SHA da B, mas novo `release` e `epoch=3`. A aceitação anterior da B deve ser negada mesmo sem troca de digest.

Reaproveitamos o servidor PHP, página fictícia, `LocalSiteRendering`, browser CDP e recibos locais existentes, sem alterar um único componente de autorização do produto ou criar writer oficial.

Prova nova exigida no mesmo processo/mesmo pedido de laboratório:
1. Clique explícito B já aceito no Chromium e recibo B relido.
2. Troca **somente de metadados de release/epoch** para C, mantendo `preview.html` byte-idêntico.
3. Sem novo GET da revisão, clique com observação B obsoleta retorna HTTP 409; leitura independente do `receipts.jsonl` confirma ainda **2 recibos**, sem novo efeito.
4. Depois de novo GET/iframe (mesmo SHA HTML), clique C retorna 201 com referência única; replay C retorna 200 idempotente.
5. Releitura separada em PHP comprova 3 recibos com `epoch` 1/2/3, SHA B === SHA C, releases distintas e sem quarto efeito; outro comprador continua com HTTP 403 e zero bytes.

A versão C não é revisão oficial de briefing; nenhuma identidade real é inferida da fixture. Aceite final e autorização de publicação permanecem decisões separadas.

## Escopo / execução

Branch `test/onepage-chromium-same-sha-new-epoch-r07-2026-11-22` filha da R06, quatro arquivos exatos: scripts PHP/Node do ensaio Chromium existente, workflow R07 com pino de base SHA e esta nota. A CI deve validar a SHA final por checkout não autenticado persistente, diff exato, sintaxe PHP/Node, Chromium efêmero e marcadores fechados. A instalação de Chromium e a saída de logs sintéticos não usam credenciais. **Não contar a R06 como nova prova R07**; ver resultado do job da HEAD final.

## Limites e gates

O acesso é cookie de laboratório e o recibo é arquivo descartável, não sessão corporativa Portal, clique operacional com autoridade jurídica nem PG18 oficial. O HTML é servido em 127.0.0.1; o navegador é Chromium de CI, sem URL externa. O contrato real do briefing, confirmação de direitos do comprador, revisão autorizada e revogação seguem pendentes no Portal #976; #1003/#552 são fronteiras de contrato, não autenticação. Worker AI Agents #490 continua simulado: sem rota/identidade/egress e credencial exclusivas comprovadas, **sem chamada OpenAI real**. Sem merge, deployment, VPS, DNS, Cloudflare, checkout, Asaas, publicação, gasto ou mudança de produção. Landing Page não herda essa evidência. **Sites #38 continua NO-GO.**

A marcação `success` só se aplica depois da conclusão do workflow específico da SHA final. Se falhar, preservar vermelho, investigar a primeira falha técnica e corrigi-la sem remover guardas.