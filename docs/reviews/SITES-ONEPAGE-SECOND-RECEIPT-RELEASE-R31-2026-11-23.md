# OnePage R31 — trocar release durante segundo recibo impede nova apresentação

Base verificada: Sites [#634](https://github.com/rafhael100/imagine-sites/pull/634) HEAD `ae37a149406a19a618238e15ae2918ca7c6d08f9`, 4/4 workflows SUCCESS na SHA; branch e PR draft isoladas. Portal #1099 e AI Agents #490 foram consultados, sem alterações. A branch histórica `fix/sites-onepage-private-preview-witness-context-2026-11-20-r31` estava 155 commits atrás e não tinha PR sucessora; não foi alterada.

## Defeito reproduzido antes da correção

No mesmo pedido e briefing OnePage **inteiramente fictícios**, a segunda consulta ao recibo do comprador devolve o recibo antigo exato enquanto a própria fonte altera a release corrente. A comparação prévia do head era anterior a essa leitura. O observador aceitaria indevidamente release obsoleta e executaria segunda leitura de HTML confidencial. A nova regressão cobriu substituição de releaseRef, incremento de artifactEpoch, SHA diferente e retirada da aprovação da release.

[CI vermelha antes da correção, run 45236255109](https://github.com/rafhael100/imagine-sites/actions/runs/45236255109), jobs 137908665559 (8.4) e 137908666037 (8.3): falha real `ONEPAGE SECOND RECEIPT R31 FAILED: uniform denied release` — o gate não negava a versão antiga. Não contar esta execução como sucesso.

## Implementação mínima

`OnepageExactFinalAcceptanceReadCandidate::observe()` faz a terceira leitura tipada do head **depois do segundo recibo e da revisão do briefing, antes do segundo HTML**. A alteração observada recusa sem consultar o segundo HTML. Mantém os mesmos ports, preview fail-closed, renderizador, parser, testemunhos, digest, identidade fictícia e flags de publicação falsas. A terceira consulta exige atualização das contagens positivas dos testes R28–R30; os negativos anteriores permanecem intactos.

## Limites

A prova é de **observação**, sem lock/CAS transacional, sessão autenticada, writer oficial ou autorização de publicação. As portas sintéticas não são uma fonte oficial Laravel/PostgreSQL. CI em PHP 8.3/8.4 deve ser correlacionada à HEAD final exata no comentário da PR; não existe chamada OpenAI, egress, rede privada do worker nem preview de produção. Landing, VPS, DNS, PSP, credenciais, main e deploy não foram alterados. Portal #976 e Sites #38 seguem NO-GO.
