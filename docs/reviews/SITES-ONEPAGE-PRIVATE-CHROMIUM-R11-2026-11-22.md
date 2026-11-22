# OnePage R11 — serialização de recibos exatos com múltiplos workers (laboratório, NO-GO)

## Preflight de 22/11/2026
- Base imutável Sites #614 R10: `77fd8e3503cafc0f43fd8d9569a9b40181cea983`, quatro workflows GitHub Actions `success` observados nessa SHA (45026409029, 45026409027, 45026409038, 45026409055).
- Consulta de PRs e branches anterior à escrita: nenhuma R11 existente. OnePage #605 (SQL), Landing Sites #580 / Portal #1088 e Agents #490 continuam em ramos distintos. #541/#542 são somente históricos.
- Branch filha exclusiva e escopo de quatro arquivos: fixture PHP, orquestrador Chromium, workflow, nota. Nenhum merge, main, deploy ou rota pública.

## Lacuna técnica corrigida
Em R10 a leitura do JSONL e a decisão de inexistência do ref ocorriam **antes** do `LOCK_EX` isolado do append. Dois workers poderiam ler a mesma versão sem recibo e ambos appendê-la, apesar do lock por escrita. Isso torna a idempotência de recibos vulnerável à concorrência. A correção adiciona um lock exclusivo por arquivo de controle de caminho fixo, abrangendo leitura, verificação de ref, append e releitura. Quando o lock falha, devolve 503 e não indica aceite. R10 (sink não durável), negação por sessão e checks de SHA/release permanecem.

## Ensaio novo (não repetir a regressão R10 sem alteração)
- Servidor PHP interno de laboratório com `PHP_CLI_SERVER_WORKERS=4`, apenas em `127.0.0.1`, diretório 0700 descartável.
- Depois das três gerações A/B/C e do ensaio negativo D já existentes, o Chromium observa a geração fictícia E (`epoch=5`, mesmo SHA HTML de C) uma vez. Em seguida emite **12 POSTs simultâneos**, pelo mesmo comprador/sessão fictícios e mesmo payload exato.
- Apenas na geração E, um intervalo sintético de 110 ms após leitura e antes do append amplia a janela de concorrência. Com lock, a expectativa é exatamente **um HTTP 201**, onze respostas idempotentes **HTTP 200**, **um novo recibo E**, preservação literal dos três recibos A/B/C e nenhuma duplicação de ref.
- O leitor PHP independente verifica A/B/C/E, revisão/geração, SHA, comprador, release e ref. CI draft-only confirma SHA e arquivos exatos. Este texto não é evidência de execução até CI verde na HEAD final.

## Limites
Teste de concorrência da **fixture**, não transação operacional nem identidade real. Não prova fsync, persistência sob pane, autoridade do comprador no Portal, briefing imutável de origem oficial, declaração de direitos, revisor autorizado ou publicação. Portal #976 continua default-deny; Agents #490 não confirma worker OnePage live. Nenhuma chamada OpenAI, credencial, saída de rede externa, pagamento, CRM ou Replit. Landing independente. Sites #38 permanece **NO-GO**.
