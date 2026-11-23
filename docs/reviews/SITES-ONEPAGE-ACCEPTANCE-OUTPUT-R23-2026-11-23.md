# Sites OnePage R23 — contenção de saída das portas do aceite (CI-only, NO-GO)

## Preflight e escopo
Em 23/11/2026, conferidas PRs e CI das SHAs exatas: Sites #626 `6420c7c05644b94983c9babdc76f0f442f95f5a1` (4 workflows SUCCESS), Portal #1097 `b7a4510f544010f72e83ca27e0c7dafa2c388b27` (6 SUCCESS), AI Agents #490 (Responses somente simulado). Nenhum sucessor R23 OnePage Sites encontrado antes da escrita; esta branch foi criada exclusivamente a partir de #626. Sites #541/#542 são históricos; #552 e Portal #1003/#976 continuam contratos/bloqueios. Landing Page independente.

## Defeito específico e recorte seguro
A proteção contra `echo` acidental adicionada ao `OnepageBuyerPrivatePreviewCandidate` na Sites #624 não envolve os leitores seguintes do `OnepageExactFinalAcceptanceReadCandidate`: `currentRelease`, `currentAcceptance` e `currentPresentation`. O observador R22 podia aceitar ou recusar corretamente enquanto bytes ecoados por esses leitores chegavam à resposta HTTP. Um resultado final `DENIED` não recupera os bytes já emitidos.

Primeiro, na mesma fixture fictícia de pedido e HTML R04/R22, o teste `tests/onepage-acceptance-port-output-r23.php` introduziu marcadores sintéticos de saída em cada porta durante um aceite positivo e durante uma apresentação inválida. O código anterior não tinha `ob_start` no observador, portanto a expectativa de ausência de saída falhava; não são usados bytes reais, credenciais ou novos writers.

Correção mínima em `src/Production/OnepageExactFinalAcceptanceReadCandidate.php`: reservar buffer antes da primeira consulta, descartar em `finally` todos os buffers acima do nível original, tanto no sucesso quanto na negativa. Nenhuma alteração de contrato, HTML, SHA-256, confirmação de direitos, edição, release, idempotência, publicação ou fluxo de pagamento. Isto **não isola código malicioso** capaz de deliberadamente manipular ou descarregar buffers; as portas operacionais continuam exigindo revisão de confiança.

CI privada do PR filho: PHP 8.3 e 8.4, checkout por SHA exata, somente regressão nova e lint; os workflows gerais da SHA também serão verificados. Evidência de runs e eventuais falhas/ajustes será comentada na PR após verificação, sem inventar resultado.

## Bloqueios
Esta classe é **candidata de leitura CI-only**: não cria login corporativo, aprovação/revogação transacional, fonte de briefing imutável oficial Laravel/PG18, nem comprovante legal autenticado. O Gate Portal #976 continua default-deny. Ainda faltam identidade do worker OnePage da central, rota privada, modelo, saída e credencial exclusiva aprovados para eventual ensaio OpenAI; nenhuma chamada real efetuada. Não usar credencial do Faísca nem inferir autorização Landing.

Nenhum merge, deploy, VPS/clone, DNS, Cloudflare, Asaas/PSP, checkout, formulário, CRM/VEN, fornecedor ou produção. Aceite final privado e autorização de publicar seguem separados. Sites #38 permanece **NO-GO**.
