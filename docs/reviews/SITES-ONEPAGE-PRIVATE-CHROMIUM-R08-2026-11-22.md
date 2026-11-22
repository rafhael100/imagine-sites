# OnePage R08 — testemunha de apresentação vinculada à sessão (laboratório, NO-GO)

## Preflight — 22/11/2026

- Sites #611: base draft `test/onepage-chromium-same-sha-new-epoch-r07-2026-11-22`, HEAD imutável `5865eeb358c6570df37aa9af01180b2c1f3853f0`; quatro workflows concluídos `success` nessa SHA, inclusive Chromium `45011552266`. Sites #610/#609 são precedentes; #605 (persistência SQL) e #596 (briefing com bytes diferentes) são cadeias separadas e não foram editadas.
- Portal #976 continua com `ApprovedOnepageBriefSnapshotSource` / `CurrentOnepageBriefApprovalWitness` sem adaptador oficial; #1003/#552 só verificam fronteiras contratuais. Portal #1087 pertence à Landing, independente. AI Agents #490 permanece somente simulado. Sites #541/#542 são referências históricas.
- A busca por PRs OnePage abertas e branches Chromium não encontrou sucessor da #611 antes desta mudança. Branch filha exclusiva, sem disputa de HEAD existente.

## Falha material da fixture anterior e correção restrita

A rota `/private-onepage` gravava `presentation.json` com **comprador, release, geração e SHA**, mas **sem identificar a sessão de observação**. Com isso, depois de o mesmo comprador entrar em uma segunda sessão fictícia, um POST com a observação anterior poderia utilizar a testemunha produzida pela primeira sessão e alcançar o caminho idempotente de aceite (HTTP 200) **sem apresentação naquela segunda sessão**.

Na R08, a testemunha local também guarda o identificador de sessão do laboratório e o POST exige igualdade exata. Isso é **correção do harness de ensaio, não alteração de autorização operacional**; o único cookie permitido continua fictício e não é tratado como identidade Portal.

A prova nova deve executar no Chromium, sobre **o mesmo pedido fictício e a release C já aceita**:
1. Salvar só os campos `sha`, `epoch` e `length` observados na sessão A, sem ler nem publicar o cookie.
2. Abrir a sessão B do **mesmo comprador fictício** e enviar o POST sem GET/iframe de apresentação novo. Exigir HTTP 409, `ok:false`, **três recibos** inalterados.
3. Navegar à revisão na sessão B, observar novamente por GET o HTML e SHA da release C, clicar no botão visível e exigir somente replay idempotente HTTP 200 do recibo C, **sem quarto efeito**.
4. Manter recusa HTTP 403/zero bytes para outro comprador da mesma empresa, e a prova anterior das épocas A/B/C com B/C byte-idênticos.

## Escopo e gates

Quatro arquivos e somente esses: dois scripts do ensaio Chromium existente, workflow R08 isolado, esta nota. O workflow exige branch/base/HEAD exatas, diff com quatro caminhos, PHP/Node, Chromium descartável e marcadores de releitura. Nenhum segredo, endpoint externo, API OpenAI, custo, PSP/Asaas ou egress adicional. Prévia apenas 127.0.0.1 e dados sintéticos; recibos são arquivos locais, não aceite jurídico/autenticado em Portal. Não há produção, release oficial, S2S com identidade, CRM, publicação, rollback ou vínculo transacional oficial do briefing.

**O controle real ainda depende** de sessão/identidade corporativa, snapshot imutável aprovado, declaração de direitos do comprador, revisor autorizado e revogação do Portal #976, além da persistência e revalidação finais; não extrapolar esta proteção fictícia. OnePage e Landing mantidas distintas. Sem merge/deploy/VPS/DNS/Cloudflare/main/credenciais. Gates Sites #38, autorização de pagamento e publicação seguem **NO-GO**.

Resultado e CI só são considerados após confirmação da **SHA final exata**, com informação do run e jobs no próprio PR; nunca usar um `success` da R07 como prova R08.
