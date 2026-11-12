# A11Y-FOCUS-01 — O link de salto leva o foco ao conteúdo principal

Em continuidade ao candidato de integração F1-286 (#338), esta revisão independente **não recria o link** já implementado na cadeia F1-282..F1-285. Ela corrige uma lacuna verificável: o teste anterior exigia `location.hash=#principal`, mas não exigia `document.activeElement.id=principal` depois de Enter.

## Alteração de comportamento

- Quando o editor opta por **Pular para o conteúdo**, a página gera `<main id="principal" tabindex="-1">`. Isso permite foco no destino sem acrescentá-lo à sequência normal do Tab.
- Sem essa opção, o motor mantém `<main id="principal">` e os bytes históricos da página anterior.
- O auditor básico rejeita main com tabindex sem link de salto, link habilitado sem alvo focável, mudança para tabindex comum e duplicação do link ou do main.
- Os testes de produto em PHP cobrem dois produtos, três estilos, quatro paletas e mutações de target; o comparador Laravel mantém a distinção exata dos dois modos.
- O teste Chrome F1-284 agora exige **primeiro Tab no link**, Enter com hash local **e** foco efetivo no main em desktop, celular e largura estreita. Se o navegador não fizer isso, o teste deve falhar, não mascarar o problema.

## Segurança e integração

HTML sem JavaScript, formulários, URLs públicas, serviços pagos, clientes reais ou publicação. O output continua `noindex`, revisão interna pendente e assinatura não operacional. Dez gates em `config/operational-readiness.json` permanecem `BLOCKED`; sem merge/deploy.

A base é `integration/sites-f1-286-dev-review-candidate-2026-11-12` (PR #338), e o resultado aguarda CI e revisão independente. Não reutilizar a cadeia paralela #339–#343 sem reconciliação posterior de autoria e mudanças.