# OnePage R09 — testemunha HTTP isolada por sessão (laboratório, NO-GO)

## Preflight e escopo
- Base imutável: Sites #612, HEAD `d73d9cb897bee4e88730f3726ea42b711b9eb428`, quatro workflows concluídos com sucesso nesta SHA (run Chromium 45016205579).
- PRs Sites #541/#542 históricas; #605 é escritora independente de persistência SQL, NÃO alterada. Portal #976/#1003 permanecem contratos sem fonte oficial e sem IAM/endpoint; Portal #1087 pertence à Landing, independente; AI Agents #490 é simulação.
- Branch filha isolada R09, sem alterações ao motor, ao Portal, ao agente, à produção, à VPS ou aos gates da Sites #38.

## Defeito concreto
A fixture HTTP/Chromium da #612 escrevia o único arquivo `presentation.json`. A apresentação recente de uma sessão fictícia do mesmo comprador sobrescrevia a apresentação de outra sessão legítima. Assim, mesmo após as duas terem apresentado a mesma release C, a repetição idempotente da sessão anterior retornaria 409 depois de uma visita da outra sessão. Isso não é isolamento de testemunhas.

## Correção mínima e regressão nova
- Persistir testemunhas separadas por **dois rótulos sintéticos explicitamente enumerados**, sem converter cookie ou dado do comprador em caminho de arquivo. Ausência de sessão válida continua 403; falha de gravação devolve 503, não prova de aceite.
- Reter negação de uma nova sessão que ainda NÃO fez GET e exigir a primeira apresentação; depois aceitar replay idempotente, sem quarto recibo.
- **Nova intercalação R09:** sessão 2 faz GET de C e aceite idempotente, sessão 1 volta a fazer GET de C e, sem novo GET na sessão 2, esta relê seu próprio testemunho e recebe 200 idempotente. A versão C mantém mesmo SHA que B, mas geração nova e três recibos sintéticos totais.
- Verificação em Chromium real, origem HTTP exclusiva em `127.0.0.1`, PHP/Node e ciclo de vida descartável. O workflow R09 limita o diff a exatamente quatro arquivos e fixa a base SHA. A execução CI é a única prova de sucesso; este documento não a presume.

## Limites e próximo gate
Cookies de fixture, testemunhas e recibos são sintéticos. Não constituem login Portal, apresentação juridicamente válida, consentimento dos direitos de uso, fonte transacional e imutável do briefing, aprovação independente do revisor, aceite oficial ou publicação. Os fontes Portal #976 estão em default-deny. A próxima integração de maior valor requer fonte e autoridade oficiais identificadas no Portal para o mesmo pedido; **não inventar writer**.

OnePage e Landing Page são produtos distintos. Nenhuma chamada OpenAI foi feita; uma chamada real só seria permitida após prova da rota privada, identidade, modelo, egress e credencial exclusiva já provisionada. Sem checkout, PSP, merge, deploy, DNS, Cloudflare, produção, VPS, lead ou contratação. Sites #38 **NO-GO**.
