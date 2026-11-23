# Sites OnePage R25 — negar release obsoleto antes de consultar outro recibo privado

## Preflight e escopo — 23/11/2026

- Sites [#628](https://github.com/rafhael100/imagine-sites/pull/628), base exata `95f2a0b9951ea1febe77375684ab301ef4cfa24d`; quatro workflows SUCCESS na SHA da base. Histórico [#541/#542](https://github.com/rafhael100/imagine-sites/pull/541) não reaplicado.
- Portal [#1099](https://github.com/rafhael100/imagine-portal/pull/1099) estava aberto na SHA `16b225a464422570e07430e900275bfcd5813970`, com seis workflows SUCCESS observados. Seu escritor/branch não foi alterado. Portal #976 e #1003 continuam referência de contrato, sem fonte operacional demonstrada.
- AI Agents [#490](https://github.com/rafhael100/imagine-ai-agents/pull/490) somente precedente isolado de worker simulado; não se criou outro mock. Landing Page sem alterações.
- Branch exclusiva derivada da SHA imutável de Sites #628; nenhuma atualização na branch alheia nem em main.

## Primeiro defeito ainda não coberto

Em `OnepageExactFinalAcceptanceReadCandidate::observe()`, depois do primeiro testemunho de HTML privado, o algoritmo consultava `currentRelease` novamente e **imediatamente consultava `currentAcceptance` para esse head novo**, antes de comparar o release ao anterior. Se o release fosse substituído/revogado durante a primeira apresentação, essa segunda leitura confidencial do recibo do comprador era desnecessária e indevida, embora a operação terminasse em DENY. R24 cobre o caso diferente de recibo alterado; não cobre o acesso indevido ao recibo causado por release alterado.

## Alteração mínima e regressão

- Reordenou-se somente a fronteira de releitura: comparação tipada do release corrente, em igualdade estrita de campos sem depender da ordem, ocorre **antes** da segunda invocação `currentAcceptance`. Só depois se verifica novamente o recibo exato; a segunda leitura de HTML continua após as testemunhas editoriais correntes.
- `tests/onepage-stale-release-prevents-receipt-r25.php` reaproveita o mesmo pedido sintético admitido na R04/R24, os mesmos leitores e HTML privado. Três casos provocam troca de `releaseRef`, `artifactEpoch` ou `htmlSha256` **após** o primeiro HTML observado. Cada caso exige duas leituras de release, **uma** leitura de recibo, **uma** leitura de apresentação, DENY uniforme e zero bytes escapados. Nenhum valor real, tenant novo, writer ou concessão foi criado.
- Workflow restrito à branch filha DRAFT e a PHP 8.3/8.4; valida SHA exata, lint, R25 e regressão R24. Os testes não verificam navegador/login real, PostgreSQL operacional ou transação/CAS.

## Bloqueios preservados

Faltam, no Portal Laravel/PG18, fonte de revisão imutável e bytes exatos vinculados ao mesmo pedido, confirmação autenticada e revogável de direitos do comprador, e aprovação/revogação por revisor independente. Um booleano de fixture não é autoridade. Prévia privada, aceite final e publicação permanecem transições diferentes; domínio só é necessário na publicação.

Sem worker OnePage exclusivo com identidade, rota/modelo, credencial específica e saída autorizada comprovados em conjunto, **não foi realizada chamada OpenAI real**. Não copiar credenciais do Faísca. Não houve merge, deploy, acesso VPS/clone, checkout, Asaas, CRM, Landing Page, main, DNS, Cloudflare ou publicação. Sites #38 permanece **NO-GO**.

CI e HEAD final devem ser confirmadas pelos runs específicos e registradas na PR após os resultados.
