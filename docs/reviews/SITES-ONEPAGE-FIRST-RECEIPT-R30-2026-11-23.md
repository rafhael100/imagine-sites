# OnePage R30 — revogação durante primeiro recibo antes do HTML confidencial

**Base verificada:** Sites [#633](https://github.com/rafhael100/imagine-sites/pull/633), HEAD `0ed73baa5b3d2a1547b50d58397a08ceccef77e2`, CI 4/4 SUCCESS. Branch DRAFT filha isolada.

## Lacuna material

R29 protege a primeira leitura do recibo contra revogação durante `currentRelease`. Ainda assim, o primeiro `currentAcceptance` podia coincidir com direitos retirados, aprovação editorial revogada ou alteração dos bytes do briefing, retornando um recibo idêntico. A primeira consulta a `currentPresentation` expunha bytes HTML confidenciais desnecessariamente antes do DENY final. O contrato do recibo e a release não eram a autoridade para continuar a apresentação.

A nova regressão em `tests/onepage-receipt-race-before-first-presentation-r30.php` usa somente a OnePage fictícia preexistente. Muda os testemunhos *durante* a primeira leitura do recibo e exige `release=1, receipt=1, presentation=0`, recusa uniforme, saída privada vazia e nenhuma autorização de publicação. O controle positivo preserva 2/2/2 releituras independentes.

## Correção

A execução `OnepageExactFinalAcceptanceReadCandidate::observe` agora chama novamente o mesmo `OnepageBuyerPrivatePreviewCandidate::render` fail-closed **depois** do primeiro recibo e **antes** da primeira apresentação, exigindo conteúdo e revisão idênticos. Nenhum parser, renderizador, contrato, rota, provider, credencial ou regra de negócio foi criado ou substituído.

## Limites

Estes testes em PHP CI reproduzem apenas a corrida *observável* em portas sintéticas; não fornecem isolamento transacional, CAS, sessão de comprador autenticado, leitor oficial Laravel/PostgreSQL, testemunho de revisor operacional ou aprovação de publicação. Portal #976 e Sites #38 mantêm NO-GO. Nada altera Landing Page, worker OnePage, OpenAI, VPS, checkout, DNS ou produção. Sem merge/deploy. Evidências de CI devem ser publicadas na PR #634 com SHA real.
