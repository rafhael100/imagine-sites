# OnePage R18 — mapas PHP não são os bytes imutáveis (somente laboratório)

## Problema reproduzido

Na Sites #621 (`d69ddfc032327c401fedec61069b0bbdb76ca25c`) a prévia privada verificava os mesmos campos de seleção e recibos por meio de `!==` sobre *arrays associativos PHP*. Esse operador exige também a mesma ordem de inserção de chaves. As leituras independentes podem reconstruir mapas em ordens distintas sem alterar valores, revisão, identidade, digests ou os bytes reais do briefing. Isso provocava uma negativa para o comprador legítimo, embora todos os gates fossem satisfeitos.

O teste novo `tests/onepage-associative-read-order-r18.php`, no mesmo pedido OnePage sintético e no renderizador existente, reproduziu a falha antes da correção: [CI vermelha R18 45116491112](https://github.com/rafhael100/imagine-sites/actions/runs/45116491112), PHP 8.3 e 8.4. O teste original de 51 verificações passou, e a primeira leitura com chaves invertidas terminou em `ONEPAGE_BUYER_PRIVATE_PREVIEW_DENIED`, sem exposição da exceção interna.

## Correção mínima

`OnepageBuyerPrivatePreviewCandidate` agora compara **exatamente os nomes, tipos e valores** dos campos, sem depender da ordem do array: seleção do head contra a seleção extraída dos bytes, primeira/segunda leitura do head e os wrappers de registro privado. Os recibos de comprador e revisor são ordenados exclusivamente para a comparação interna entre leituras. Nenhuma string de `manifestBytes`, `sourceBytes`, render plan, artefato HTML, SHA-256 ou referência imutável é normalizada. Campos ausentes, extras ou com valores alterados continuam recusados.

O cenário novo cobre quatro casos positivos de ordenação, mais recusa de revisão efetivamente diferente durante a segunda leitura. Usa as mesmas fontes fictícias e não cria nova autoridade comercial nem reexecuta os mocks do AI Agents.

## Limites de autoridade

Continua **NO-GO**: o head e os recibos provêm de portas sintéticas injetadas, sem confirmação do comprador com identidade corporativa atestada, declaração de direitos operacional ou aprovação/revogação vinculada ao Portal Laravel/PostgreSQL #976. Portal #1003 e Sites #552 são contratos históricos. Não houve aceite real nem prévia autenticada operacional. Nenhuma chamada OpenAI real; worker OnePage requer identidade, rota privada, modelo, egress e credencial próprios autorizados. Landing Page não foi modificada nem beneficiada por evidências OnePage.

Sem merge, deploy, VPS, checkout, Asaas, DNS, Cloudflare, credenciais, publicação ou rollback. **Sites #38 permanece NO-GO**. Próxima entrega material: fonte oficial de revisão/bytes/confirmation/approval, ou ensaio autenticado independente que não fabrique autoridade.
