# OnePage R33 — continuidade dos testemunhos entre observações (NO-GO)

Base Sites [#636](https://github.com/rafhael100/imagine-sites/pull/636), HEAD `942887d8091ddb9f165b66bc229a725803fcd5d7`, quatro workflows SUCCESS consultados antes da escrita. Portal #1099 e Agents #490 também consultados. Sites #541/#542 históricos. Branch exclusiva, PR [#637](https://github.com/rafhael100/imagine-sites/pull/637).

## Lacuna

A resposta pública de prévia é intencionalmente limitada a HTML, SHA, versão, revisão e flags. Porém, o leitor de aceite comparava somente essa resposta entre chamadas de render. Se a declaração de direitos e aprovação editorial fossem substituídas coerentemente por novos recibos válidos, as chamadas isoladas poderiam aceitar os novos testemunhos e retornar o mesmo HTML/SHA. O leitor de aceite não perceberia a troca. Não se trata de revogação simples já coberta por R26–R32.

## Mudança

A observação interna CI-only da prévia conserva um SHA-256 dos três mapas de testemunho já validados e ordenados canonicamente (seleção, confirmação e aprovação). O leitor de aceite usa a observação interna nas seis chamadas já existentes, sem novas consultas às fontes. A resposta de render pública continua com os oito campos originais; o leitor de aceite não retorna o fingerprint. Nenhum contrato, parser ou renderizador reimplementado. O fingerprint não autentica o comprador nem substitui assinatura ou CAS.

O teste R33 reutiliza o mesmo pedido/briefing/release/HTML sintéticos de R32: rotação coerente dos recibos durante primeira release, primeiro recibo, primeira apresentação, segundo recibo ou segunda apresentação; recusa uniforme, limites de leituras confidenciais e ausência de saída. Controle positivo, shape público inalterado e ausência de fingerprint na resposta do aceite. CI específica PHP 8.3/8.4 também cobre regressões R28–R32 afetadas. Resultado observado será registrado na PR, não presumido.

## Limites

Detecta mudanças observáveis entre chamadas, **não** prova que um aceite preexistente está ligado a testemunhos históricos anteriores à primeira leitura, nem impede corridas depois da última observação. O contrato do Portal #976 e fonte oficial de revisão imutável/bytes/direitos/aprovação, identidade autenticada e CAS transacional permanecem necessários; não se inventou autoridade ou writer. Worker OpenAI segregado não atestado; nenhuma chamada real. Landing Page independente. Sites #38 NO-GO. Sem merge, deploy, checkout, PSP, VPS, DNS, Cloudflare, credenciais, publicação ou rollback automático.
