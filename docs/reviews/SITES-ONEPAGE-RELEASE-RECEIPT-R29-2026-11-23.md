# OnePage R29 — revogação durante leitura inicial do release (CI-only)

**Base exata:** Sites #632, `be3c0049816edd62dfa356b96129c9b72a41fdc2`. Branch filha DRAFT isolada. O pedido/briefing/artefato e o comprador são os mesmos dados inteiramente fictícios da fixture OnePage já admitida; não são origem operacional oficial.

## Defeito e recorte mínimo

O observador `OnepageExactFinalAcceptanceReadCandidate::observe` validava o comprador e a revisão pela prévia privada R28 antes de `currentRelease`, mas não revalidava essas provas **depois da primeira chamada ao release**, antes de `currentAcceptance`. A consulta de release pode coincidir com revogação de direitos, reprovação editorial ou edição dos bytes de briefing e devolver o mesmo release. O código anterior ainda lia o recibo privado antes de perceber a inconsistência na releitura posterior.

O teste `tests/onepage-release-race-before-first-receipt-r29.php` injeta essas mudanças exclusivamente no callback da **primeira consulta de release**, usando a fixture já existente e portas em memória. Exige negação uniforme, nenhuma leitura de recibo/apresentação, ausência de saída HTTP e controle positivo com as leituras independentes e publicação bloqueada. Nenhum cenário é um login nativo.

## Correção

Após o primeiro release e a verificação dos hashes/versões já existentes, o observador executa novamente o `OnepageBuyerPrivatePreviewCandidate::render` fail-closed e exige correspondência exata com a prévia original **antes da primeira leitura do recibo**. O renderizador, exportador Portal, formato do recibo, identidade visual, revisões e verificação de publicação não mudam. As guardas anteriores R26–R28 continuam em vigor.

## Evidência e limites

A regressão vermelha é válida somente quando o workflow da SHA exata do teste anterior à correção falhar especificamente na exigência de zero leituras de recibo. Um eventual verde da HEAD corrigida será registrado em comentário de PR com SHA, IDs e resultados, sem extrapolar para homologação.

**NO-GO:** duas leituras independentes não constituem snapshot transacional, CAS, identidade do comprador, origem oficial Laravel/PostgreSQL, aprovação/revogação oficial do revisor ou autorização operacional. Portal #976 e Sites #38 seguem bloqueados; não há worker OpenAI segregado atestado nem chamada de API real. Landing Page não é alterada. Nenhuma VPS, DNS, PSP, segredo, deploy, merge ou publicação.
