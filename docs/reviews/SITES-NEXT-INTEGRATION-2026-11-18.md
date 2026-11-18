# Próxima integração central: Portal → briefing → geração

## Decisão de continuidade

**18/11/2026.** O próximo avanço central é receber uma revisão oficial de briefing do Portal e fazê-la alimentar uma produção persistente no Sites. As primeiras inspeções das linhas nativas não encontraram um contrato homologado desse insumo. A investigação ampliada do legado identificou regras concretas que permitem elaborar uma proposta técnica, descrita na atualização abaixo. A fonte operacional, a identidade de transporte e a autoridade comercial continuam distintas dessa proposta.

Essa conclusão delimita a integração; não afirma esgotamento de todo desenvolvimento. O trabalho autorizado em branch isolada pode continuar em componentes com uso concreto e defeitos demonstrados. A inspeção privada do diário é um exemplo: consome a API persistente existente sem criar autoridade ou depender de um briefing inventado.

### Composição persistente candidata validada na #520

A branch `feature/sites-brief-artifact-provenance-2026-11-18` acrescentou a
[ligação técnica ao registry](../STORED-BRIEF-RELEASE-PROVENANCE.md), sobre a base
#519 validada. A [#520](https://github.com/rafhael100/imagine-sites/pull/520) concluiu
**15/15 checks SUCCESS** no HEAD `8b717d3c0c03146bc48ee812e906bc70a1c479a9`.
`StoredBriefReleaseRegistration` recebe plano/editorial originais,
lê o snapshot exato e gera o HTML internamente. Não recebe sidecar ou artefato
externo como prova. A migration 14 usa a operação existente de registro e
persiste a relação na mesma transação, sem adoção retroativa de artefatos antigos
sem associação; a API anterior mantém seu contrato e sua capacidade exclusiva.

O plano tem dez campos, o resultado de registro vinte e o leitor histórico
exato dezoito. `commitmentSha256` vincula alvo, plano/editorial originais e HTML;
`planSha256` identifica os bytes do plano. O SQL confere estrutura, hashes e FK
no banco do writer; a geração e a auditoria permanecem no PHP. A leitura do
snapshot termina antes da escrita, sem prometer autorização atômica entre
sessões. O reader histórico usa identidade separada e não repete a escrita de
um autor/epoch antigo. Não há retry automático ou aprovação oficial; a #520
não acrescentou CLI, HTTP, provider ou transporte.

**Preparação local desse incremento:** grupo PHP focado de **39 testes/1172 assertions
PASS**, com **28 novos e onze Unit existentes**; regressões **109/271 PASS**,
lint e diffcheck. Esse grupo não é a suíte geral.

**Prova final própria em PHP 8.3/8.4:** o
[registry 44344390814](https://github.com/rafhael100/imagine-sites/actions/runs/44344390814)
confirmou **119 checks novos/1042 totais**, e o
[F383 44344390782](https://github.com/rafhael100/imagine-sites/actions/runs/44344390782),
**245 de briefing/65 de intake**, batch das **14 migrations** e down com os dois
schemas ausentes. A [matriz Laravel 44344390833](https://github.com/rafhael100/imagine-sites/actions/runs/44344390833)
confirmou **279/4250/27** e **279/4262/21**; os
[containers 44344390839](https://github.com/rafhael100/imagine-sites/actions/runs/44344390839)
também passaram. Clusters próprios foram encerrados e os laboratórios concluíram
cleanup. As correções da fixture trataram comparação de catálogo `bytea`/streams
e ciclos Laravel/PDO, sem alteração de produto.

A [CLI privada de consulta](../BRIEF-ARTIFACT-INSPECTION.md) foi validada na
[#521](https://github.com/rafhael100/imagine-sites/pull/521), HEAD
`73d610513c777c5f3a1709fcb19263d6073139c6`, com **12/12 checks SUCCESS**.
O [run 44346085528](https://github.com/rafhael100/imagine-sites/actions/runs/44346085528)
confirmou **51 checks offline**, **25 novos/1067 registry totais** em PHP 8.3/8.4,
remoção dos três arquivos privados e clusters próprios inativos. A matriz
[Laravel 44346084649](https://github.com/rafhael100/imagine-sites/actions/runs/44346084649)
confirmou **279/4250/27** e **279/4262/21**; F383 e containers continuam com a prova
anterior identificada pela #520.

A [CLI candidata de registro composto](../STORED-BRIEF-REGISTRATION-CLI.md)
acrescenta plano real somente leitura e uma chamada explícita de registro com
compromisso confirmado, duas identidades e seis entradas privadas. A
[#522](https://github.com/rafhael100/imagine-sites/pull/522), HEAD
`0b735a1794ee3bb965e5a05d5d738083ddac9544`, concluiu **12/12 checks SUCCESS**.
O [run 44349683339](https://github.com/rafhael100/imagine-sites/actions/runs/44349683339)
confirmou **95 offline**, **27 novos/1094 registry totais** em PHP 8.3/8.4 e
cleanup; a matriz [44349683049](https://github.com/rafhael100/imagine-sites/actions/runs/44349683049)
confirmou **279/4250/27** e **279/4262/21**. A prova pertence ao renderer e aos
vetores daquele HEAD. A correção de quebra de texto foi depois validada na
[#524](https://github.com/rafhael100/imagine-sites/pull/524), HEAD
`89d8a99b83d13fd1b5472b55d7f2891809d2cf81`, com **15/15 checks SUCCESS**:
**429 workspace/1094 registry** em PHP 8.3/8.4, **8 novos/447 HTTP totais**
e vetores atualizados. [Prova própria do renderer](SITES-RENDERER-LONG-TEXT-2026-11-18.md).
Produtor Portal, exportação consistente, transporte e materiais reais continuam
como dependências operacionais distintas.

### Armazenamento técnico candidato e consumo persistido validados na #519

A [PR #519](https://github.com/rafhael100/imagine-sites/pull/519), HEAD
`cdfad4464d65355962d487396911fe06092dfde5`, concluiu **15/15 checks SUCCESS**.
A migration 13 conserva manifesto/corpo imutáveis, CAS de `briefRevision` e
replay exato sob principal SQL; `sourceVersion` permanece contador agregado
independente. O reader revalida o perfil completo e resolve a seleção exata
para `StoredBriefRendering`. Seu resultado isolado é HTML/proveniência em
memória. O [recibo de armazenamento](../../contracts/portal-sites/candidates/brief-production-v1/brief-snapshot-receipt.schema.json)
tem treze campos e quatro flags falsas; não autentica aprovação, pagamento ou
aceite do cliente.

O [F383 44336308019](https://github.com/rafhael100/imagine-sites/actions/runs/44336308019)
confirmou **241 checks de briefing**, **65 de intake**, recorder **18/84**,
grupos de leitura/editorial **3/12** e **2/12**, rollback das **13 migrations**
e ausência dos dois schemas. O [registry 44336307622](https://github.com/rafhael100/imagine-sites/actions/runs/44336307622)
confirmou **923 totais/62 de registro**, quatro checks antigos/quatro novos de
renderização e um de schema, com clusters inativos. A
[matriz Laravel 44336308278](https://github.com/rafhael100/imagine-sites/actions/runs/44336308278)
confirmou **251/3140/27** e **251/3152/21** em PHP 8.3/8.4; o
[ensaio de containers 44336308134](https://github.com/rafhael100/imagine-sites/actions/runs/44336308134)
também concluiu cleanup. Essa é a prova histórica da base, não da nova
associação. [Manual de snapshots](../PORTAL-BRIEF-SNAPSHOTS.md).

### Primeiro consumidor do briefing candidato implementado

Sobre a proposta #517 / `c53eac9eb8060a62eb060e68f3bc5b2956dbbdff`, a branch
`feature/sites-local-render-provenance-2026-11-18` implementa o modo de
[proveniência local](SITES-LOCAL-RENDER-PROVENANCE-2026-11-18.md) na CLI existente.
Ele confere os quatro arquivos e o HTML esperado, mantém a entrada editorial
separada do corpo rico e grava um sidecar privado. A fixture estendida encaminha
o HTML desse modo ao registro de artefatos já existente, com a mesma autorização
SQL. O sidecar permanece local e não é ingerido pelo registro.

As provas offline passaram: 109 checks do serviço, 80 da CLI e os 137/87
anteriores. A [PR #518](https://github.com/rafhael100/imagine-sites/pull/518),
HEAD `907e2883393b776eebcba59fafb672aa34c99bf6`, concluiu **12/12 checks SUCCESS**.
O [run 44323010319](https://github.com/rafhael100/imagine-sites/actions/runs/44323010319)
confirmou quatro checks adicionais/922 registry totais e limpeza dos clusters
em PHP 8.3/8.4. Nenhum resultado da #516 ou #517 é atribuído ao código novo.

A #518 isoladamente não incluía persistência de briefing; seu comando continua
sem DB. O receptor candidato descrito acima acrescenta essa fronteira por
serviços internos separados, mantendo a autorização própria do registry e sem
transformar o sidecar local em uma associação persistida.

### Continuidade após a candidata unificada: fonte concreta no legado

Depois da #516, a leitura foi ampliada aos handlers e ao formulário Node do Portal no mesmo commit `0e0ccc06755b6ddd938635e5c4c3625ff6497e2c`. O [inventário reconferido](../SITES-F1-20-PORTAL-LEGACY-PARITY.md#fonte-fixada-em-18112026) fixa oito blobs e demonstra um briefing Onepage real do protótipo: onze textos, três flags, propriedade por pedido, envio, revisão e materiais. Esse fluxo é separado do objeto PHP SAMPLE anteriormente examinado.

Há também um consumidor técnico existente: `LocalSiteRendering::render` consome os seis campos editoriais, produz bytes HTML exatos e permite o registro posterior por `PrivateReleaseRegistration`. O briefing rico não fornece automaticamente os títulos, chamada do botão ou benefícios dessa entrada editorial. A proposta preserva a transformação editorial explícita e vincula os dois insumos por referências e digests.

Portanto, a falta de contrato oficial não impede fechar escolhas de engenharia reversíveis. O [contrato candidato de briefing e produção](../SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md) passa a definir a identidade do snapshot, a diferença entre o contador agregado da origem e a revisão imutável candidata, a concorrência, os bytes consumidos e a proveniência do artefato local. Ele não migra o SQLite, não ativa transporte, não cria uma fonte oficial de autorização e não promove demonstrações para dados comerciais.

Os insumos de homologação listados adiante continuam necessários para receber dados reais e iniciar produção autorizada. Eles não devem ser usados como bloqueio genérico para especificar ou implementar um consumidor local explicitamente candidato sobre contratos e arquivos sintéticos verificáveis.

A ausência de um contrato oficial também não proíbe desenhar propostas reversíveis. Ela limita o que se pode afirmar sobre compatibilidade e consumo de dados reais do Portal. Na continuidade desta revisão, a [comparação de texto literal](SITES-INERT-TEXT-COMPARISON-2026-11-18.md) foi corrigida e o [registro privado de artefatos](SITES-ARTIFACT-REGISTRATION-2026-11-18.md) foi iniciado sobre uma API SQL já definida, com uso concreto na preparação de pacotes. Essas frentes não precisam transformar o briefing sintético em fonte oficial.

## Evidência do Portal inspecionada

Foram consultados títulos, escopos e referências das PRs #924–#940. As HEADs #924, #935 e #936 foram reconferidas nesta revisão. PR mais recente não significa contrato mais recente da integração: existem linhas paralelas.

| Referência fixada | Evidência e limite |
| --- | --- |
| [#924, `ed9333f0`](https://github.com/rafhael100/imagine-portal/blob/ed9333f0b7fe8f2c1207ef13ffb4302755e2ef6c/apps/portal/labs/onepage-atomic-read/README.md) | Leitura do pedido próprio no laboratório; conserva `pending_payment`, `settled=false`, `preview_admitted=false` e vínculo ausente. Não autoriza prévia nem fornece o briefing oficial. |
| [#925, `1577cdab`](https://github.com/rafhael100/imagine-portal/commit/1577cdab6560424603d1ace363be20a6b28cc254) e [#926, `7c42a13c`](https://github.com/rafhael100/imagine-portal/commit/7c42a13c03e7f98c1626f3950b9f19bf2f77d4a4) | Reserva e vínculo Onepage em PostgreSQL descartável; os escopos declaram simulação e ausência de baixa operacional/prévia Sites. |
| [#928, `951e02e5`](https://github.com/rafhael100/imagine-portal/commit/951e02e5d8eedee93851416778027cf07d105296), [#933, `f8a1c309`](https://github.com/rafhael100/imagine-portal/commit/f8a1c3098ee8e3098773c4cdec459841670542f0) e [#936, `815ffc5e`](https://github.com/rafhael100/imagine-portal/commit/815ffc5eeeddc928d631e9ba3a5cf2aca35ff233) | Composição Onepage, autenticação nativa no Kernel e diagnóstico limitado, todos de laboratório. Não conectam Sites nem ativam pagamento ou produção. |
| [#927, `30715cf4`](https://github.com/rafhael100/imagine-portal/commit/30715cf49355a8aad39c2ff8c989dcd1cb2566d9); [#929, `58aa1ae4`](https://github.com/rafhael100/imagine-portal/commit/58aa1ae4b82cc0fc2bbe8cb481d62c50c6fd136a), [#930, `ed6a4f75`](https://github.com/rafhael100/imagine-portal/commit/ed6a4f75c20064ae78c433ceabcac636065fc8e3), [#931, `26075ce0`](https://github.com/rafhael100/imagine-portal/commit/26075ce0b409a32419bc2919270d4e25cb609772), [#934, `b3408c49`](https://github.com/rafhael100/imagine-portal/commit/b3408c49fbf3a245c3d393ce4794da7fabd1d534) | Frentes Performance/GSC. Seus títulos e escopos não entregam contrato de briefing para Sites. |
| [#932, `82deeb00`](https://github.com/rafhael100/imagine-portal/commit/82deeb0062f80f1cf979a2c0105d394c2b1adc23) e [#935, `49685869`](https://github.com/rafhael100/imagine-portal/commit/4968586998f79f892a7c41da417cf762345df2b6) | Preservação de contexto visual no cadastro/login e correção de expectativas de teste de sessão; não constituem nova origem de briefing. |

A leitura de código reforça esse limite. [SiteBriefDraft na HEAD #936](https://github.com/rafhael100/imagine-portal/blob/815ffc5eeeddc928d631e9ba3a5cf2aca35ff233/apps/portal/app/Domain/SiteIntake/SiteBriefDraft.php) continua explicitamente SAMPLE, restrito a organizações e referências DEMO. O [F48 na HEAD #935](https://github.com/rafhael100/imagine-portal/blob/4968586998f79f892a7c41da417cf762345df2b6/apps/portal/labs/sites-intake-f48/README.md) define somente metadados de prévia, exclui corpo do briefing/assets e mantém transporte, aceite e publicação desautorizados. Esses arquivos conservam os mesmos blobs inspecionados na #924. **Não foi encontrado contrato oficial de corpo de briefing nessas referências**; isso não presume inexistência em fontes não fornecidas.

### Reconferência durante a entrega de associação de releases

A [PR #937, `ecc225c3`](https://github.com/rafhael100/imagine-portal/commit/ecc225c3f8c17caadfb2361f471c96cdc76e74e3) acrescenta cinco arquivos de laboratório para vincular retomada/repetição Onepage ao comprador autenticado. A [PR #938, `74cd2d12`](https://github.com/rafhael100/imagine-portal/commit/74cd2d12378b2cc7ff40fbda61327edcbcf40cd4) acrescenta catorze arquivos de laboratório/workflow para entrada sintética de recibo e sessões SCRAM separadas. Seus escopos excluem integração Sites; #938 também exclui briefing e liberação financeira operacional. Foram conferidos os arquivos alterados de ambas.

O [SiteBriefDraft na HEAD #938](https://github.com/rafhael100/imagine-portal/blob/74cd2d12378b2cc7ff40fbda61327edcbcf40cd4/apps/portal/app/Domain/SiteIntake/SiteBriefDraft.php) foi comparado integralmente com o da HEAD #936: os bytes são idênticos, conservando SAMPLE/DEMO e os três retornos de autoridade falsos. Essas duas entregas posteriores não fornecem o corpo oficial de briefing necessário à integração central. A revisão editorial e a associação privada de releases continuam usando seus contratos Sites existentes.

### Reconferência durante a recuperação da comparação

Na consulta de continuidade em 18/11/2026, a listagem por criação continuava encerrando na PR #938; as HEADs #937/#938 permaneciam exatamente nos pins acima. O arquivo `SiteBriefDraft` da #938 foi relido e conserva SAMPLE/DEMO, validações sintéticas e os três métodos de autoridade retornando `false`.

A PR #927 avançou de `30715cf49355a8aad39c2ff8c989dcd1cb2566d9` para `86c9f74e890d5d2ae254c63b65dee36af3a58e7f`. A [comparação desses commits](https://github.com/rafhael100/imagine-portal/compare/30715cf49355a8aad39c2ff8c989dcd1cb2566d9...86c9f74e890d5d2ae254c63b65dee36af3a58e7f) contém três commits e 22 paths de inventário, recuperação, materiais/build de Performance e seus testes/workflow. Esse delta não modifica domínio, rota, intake, briefing ou consumidor Sites. O pin anterior da tabela permanece como registro da inspeção histórica; esta conferência registra o avanço posterior.

Não apareceu contrato de briefing oficial versionado nem consumidor Sites no intervalo consultado. Isso continua sendo uma conclusão limitada às fontes acima. O desenvolvimento isolado prosseguiu na reconciliação do diário, custódia de recibos e recuperação da comparação, usando contratos e fluxos já existentes.

### Atualização do Portal durante a consulta de associação

Em 18/11/2026, a consulta por criação avançou para as PRs #939 e #940. As HEADs #937, #938 e #927 permaneceram nos últimos pins registrados. A nova [#939, `4464e322`](https://github.com/rafhael100/imagine-portal/pull/939), acrescenta um laboratório de confirmação sintética e indicação do próximo passo. O [NextStep](https://github.com/rafhael100/imagine-portal/blob/4464e3220e077118f05928ee20a48f33ea6440ec/apps/portal/labs/onepage-confirmation-private/NextStep.php) retorna somente `next_step=null` ou `next_step=briefing`, com sessão nativa e escopo de teste, sem registrar rota. O [SQL de confirmação](https://github.com/rafhael100/imagine-portal/blob/4464e3220e077118f05928ee20a48f33ea6440ec/apps/portal/labs/onepage-confirmation-private/confirmation.sql) persiste fontes, eventos e decisões sintéticas; suas revisões e digests descrevem a confirmação, sem corpo de conteúdo para geração.

O [SiteBriefDraft nessa HEAD](https://github.com/rafhael100/imagine-portal/blob/4464e3220e077118f05928ee20a48f33ea6440ec/apps/portal/app/Domain/SiteIntake/SiteBriefDraft.php) foi relido e mantém SAMPLE/DEMO e as três autoridades falsas. O avanço é um precursor concreto da indicação de habilitação do briefing, mas o marcador não substitui uma revisão do seu conteúdo nem um consumidor Sites.

A [#940, `1b394f98`](https://github.com/rafhael100/imagine-portal/pull/940), acrescenta recuperação simulada de identidade de cobrança após resposta perdida. O [Recovery](https://github.com/rafhael100/imagine-portal/blob/1b394f9826f623dec02beffcd2528b6a17d0c51e/apps/portal/labs/onepage-recovery-composition-private/Recovery.php) confere conta/ambiente e correspondência da cobrança; não implementa transporte HTTP e não interpreta o status como pagamento ou liberação. Esses dois deltas foram lidos sem alterar o Portal. Não fornecem o corpo oficial versionado ou o consumidor de geração necessário ao Sites nas fontes consultadas. As declarações de preparação dos respectivos READMEs não foram tratadas como resultado de CI.

### Pedido nativo e prova pareada posteriores

A [PR Portal #941](https://github.com/rafhael100/imagine-portal/pull/941), HEAD `0e0ccc06755b6ddd938635e5c4c3625ff6497e2c`, é um avanço concreto sobre a #940. A [fonte nativa de contrato](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/apps/portal/app/Infrastructure/Commerce/PostgresPortalSiteContractSource.php) relê o pedido aceito do comprador pelo reader PostgreSQL e fornece os metadados ao encoder `CreateProject v1`. A referência de integração da empresa corresponde ao `tenantRef`; o UUID interno não é usado como substituto.

A [composição pareada](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/apps/portal/tests/Integration/NativeOnepageSitesPairAssertions.php) chama o `PortalProjectIntakeRecorder` real da [Sites #512](https://github.com/rafhael100/imagine-sites/pull/512), HEAD `9b80387fe1c580436b444a6aad2b63f3baafa37d`, em outra base descartável. Confere o recibo de quinze campos, persistência, reconexão/replay e recusas, conservando solicitação pendente, flags falsas e outbox bloqueado. O [run 44304843544](https://github.com/rafhael100/imagine-portal/actions/runs/44304843544) passou em PHP 8.3/8.4, jobs `134803607144` e `134803606588`. Portanto, a associação nativa de pedido/projeto já possui consumidor e prova próprios; não deve mais ser descrita apenas como objeto SAMPLE.

O [provider](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/apps/portal/app/Providers/OnepageCommerceServiceProvider.php) registra cinco rotas privadas de pedido. O provider e o controller examinados não enviam o comando ao Sites nem conservam o recibo no Portal; a chamada ao receiver está na composição de teste. O [manual da entrega](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/apps/portal/docs/NATIVE-ONEPAGE-CHECKOUT.md) mantém transporte entre servidores, envio automático, acompanhamento de recibo e briefing de produção como integrações seguintes. O pagamento PSP da prova é simulado.

Os 24 arquivos do [manifesto pareado](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/apps/portal/tests/Integration/portal-sites-source-pins.json) foram comparados com a Sites #515, HEAD funcional `1732ad5b0d7b60067154c2a3354cd16b99a6658b`, e seus hashes permanecem idênticos. A #515 acrescenta a cobertura de ACL da tabela nova no guard do registry, sem modificar comando, recorder, recibo ou migration 11. A prova pareada continua fixada à #512 e não comprova ainda a migration 12; a adoção dessa revisão e dos arquivos adicionais deve acompanhar a linha pareada existente.

O comando de criação e o recibo não incluem o conteúdo ou a revisão do briefing. A nova evidência reduz a pendência de recebimento de projeto, mas não define esse insumo de geração. A [#942](https://github.com/rafhael100/imagine-portal/pull/942), HEAD `1cb443fd2a54baf0d6eca2327ab4e3bf6a65899b`, acrescenta somente um laboratório de admissão de testemunha D0 sintética ancorada; o delta examinado também não fornece briefing ou consumidor Sites. A conclusão continua limitada às HEADs e fontes consultadas, sem presumir ausência em branches não publicadas.

### Reconferência na candidata unificada Sites #516

A [Portal #943](https://github.com/rafhael100/imagine-portal/pull/943), HEAD `ad5f67cfd5324a69a63a5bd8cf236227ad88c957`, foi conferida contra a base #942. Os [seis arquivos do delta](https://github.com/rafhael100/imagine-portal/compare/1cb443fd2a54baf0d6eca2327ab4e3bf6a65899b...ad5f67cfd5324a69a63a5bd8cf236227ad88c957) pertencem ao workflow diagnóstico e aos laboratórios D0: ajustam checkpoints, pins e a preparação de leituras sintéticas antes da transação de reservas. Não alteram código de aplicação, contrato de briefing ou transporte Portal→Sites. O escopo foi lido sem executar ou investigar esse laboratório.

A [Sites #516](https://github.com/rafhael100/imagine-sites/pull/516), HEAD funcional `a92b365a789b3d09638feb95becc5e2dc1d384ca`, reúne o recebimento #512 e a correção #515 com a interface #511/#513/#514. Os 24 arquivos Sites consumidos pela prova pareada Portal #941 mantêm os hashes de origem; a prova do Portal continua pinada à #512. A revisão das pendências não encontrou outro contrato de briefing oficial ou consumidor definido nessas novas referências. A investigação posterior do legado fundamentou a proposta #517 e seu primeiro consumidor local, descritos acima. Os três insumos seguintes delimitam adoção operacional, sem necessidade de repetir a associação nativa já entregue.

## O que as peças Sites já fazem

| Peça atual | Consequência para a integração |
| --- | --- |
| [SiteProjectCommand](../../src/Integration/SiteProjectCommand.php) | Comando candidato de projeto com referências comerciais; não transporta corpo/revisão de briefing nem comprova autoridade. |
| [SiteBrief](../../src/Production/SiteBrief.php), [PrivatePreviewWorkspace](../../src/Production/PrivatePreviewWorkspace.php) e [SQL do workspace](../../contracts/private-preview/001-workspace.sql) | Persistem e renderizam os seis campos de demonstração, contando `productCode` e os cinco campos de conteúdo. `demonstration_only` é uma restrição explícita; salvar esses campos em outro schema não os torna um briefing oficial. |
| [EditorialRevisionWriter](../../apps/sites/app/Editorial/EditorialRevisionWriter.php) | Avança o contador a cada transição, incluindo submissão, aprovação interna e reabertura, recebendo digest. Portanto, revisão editorial não identifica uma versão do insumo nem pode substituí-la. |
| [LaravelAdmittedPreviewLab](../../apps/sites/app/Labs/LaravelAdmittedPreviewLab.php) | Usa texto fictício fixo, sob guardas de CI. Não lê briefing do cliente e não é worker de geração operacional. |
| [BriefRenderProvenance](../../src/Production/BriefRenderProvenance.php) e [render.php](../../ops/release-candidate/render.php) | Conferem um snapshot candidato e sua entrada editorial por bytes exatos, preservando o renderer existente e um sidecar privado. Não consultam head, autenticação do Portal ou autorização comercial, nem persistem briefing em `sites_core`. |
| [BriefSnapshotInput](../../src/Production/BriefSnapshotInput.php), [recorder e reader nativos](../PORTAL-BRIEF-SNAPSHOTS.md) | A migration 13 e os serviços implementam armazenamento técnico, CAS/replay e leitura exata sob principal SQL corrente. O perfil rico completo é validado em PHP; o recibo não comprova aprovação da origem. |
| [StoredBriefRendering](../../apps/sites/app/Integration/StoredBriefRendering.php) | Usa o snapshot persistido selecionado pelo plano, sem fallback para o head. Retorna HTML/proveniência em memória e não grava o artefato ou sua relação no registry. |
| [StoredBriefReleaseRegistration e consulta histórica](../STORED-BRIEF-RELEASE-PROVENANCE.md) | Composição validada na #520: usa renderização interna, confirma compromisso próprio e solicita registro/associação atômicos; uma identidade de leitura separada consulta o recibo exato. Não ingere sidecar nem autentica aprovação Portal. |
| [Pacote técnico](../../ops/release-candidate/README.md), [diário](../../contracts/publication-journal/README.md) e [inspector](../../src/Publication/PrivatePublicationInspection.php) | Trabalham sobre artefato/vínculo e estado técnico já existentes. Não fornecem o insumo ausente, não verificam pagamento/aceite e não autorizam envio. |

Os nomes lógicos `SubmitBriefRevision` e `RequestGenerateRelease` já constam em [SITES-CT-01](../SITES-CT-01-PORTAL-SITES-v0.1.md), explicitamente candidato e não homologado. O [contrato de briefing](../SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md) acrescenta payloads e regras técnicas revisáveis, hoje usados pelo consumidor local e pelo armazenamento candidato. A identidade SQL verificada nesse recebimento não transforma a declaração `approved` em aprovação oficial nem ativa o transporte dos comandos lógicos.

## Insumos necessários para adoção operacional da integração central

1. **Adoção versionada pelo Portal, com responsável e SHA revisável:** fonte oficial, regras de revisão/substituição/conflito, conteúdo permitido e vínculo com pedido/projeto/produto. Os exemplos e campos candidatos já existem; falta comprovar que o produtor autorizado os emite consistentemente. Endpoint e transporte permanecem separados dessa definição.
2. **Leitura e entrega autorizadas:** identidade entre serviços e escopo por empresa/recurso, revogação corrente, acesso aos materiais privados e custódia/retenção operacionais. Referência recebida no JSON não concede acesso. O candidato já tem tabelas, capacidade SQL, replay e reader negados por padrão; ativação de grants, transporte e uso real exigem fonte e autorização próprias.
3. **Consumidor de produção autorizado:** o modo local e o consumidor do snapshot persistido já vinculam bytes e geram HTML inerte; a composição durável com o registry possui prova SQL própria na #520. Continua necessário comprovar qual operação pode iniciar produção real e a aprovação humana da entrada editorial. Para IA/ferramenta externa, definir previamente ferramenta, orçamento e dados permitidos; a fixture não substitui execução autorizada.

O desenvolvimento em draft fechou a relação técnica persistente com o registry, mantendo revisões de briefing e editorial distintas. A #519 conserva as provas do receptor e a #520 acrescenta as de registro/associação e recuperação histórica. A CLI de consulta possui prova própria na #521; a entrada privada de registro composto possui prova própria na #522. A composição pareada com o Portal deve usar fontes fixadas dos dois repositórios; um sucesso só do Sites não comprova o produtor Portal ou o transporte.

## Dependências operacionais e alçadas preservadas

O [staging privado](../SITES-P0-PRIVATE-STAGING-2026-11-16.md) e a [verificação real de TLS PostgreSQL](../SITES-P0-EXISTING-TLS-2026-11-16.md) já possuem evidência própria. Isso não instala os novos candidatos nem comprova a cadeia comercial. Continuam necessários produtor nativo homologado de pedido/contrato, identidade S2S e revogação operacionais, credenciais/escopo Sandbox por canal privado e confirmação financeira independente, conforme a [matriz integrada](../SITES-P0-LEASEWEB-INTEGRATED-REHEARSAL-2026-11-15.md). Não se deve repetir como pendente a prova de TLS já executada, nem inferir que ela libera essas outras dependências.

A autorização vigente permite desenvolver e revisar candidatos isolados. [AGENTS.md](../../AGENTS.md) continua exigindo alçada específica para merge/deploy, VPS, DNS/Cloudflare, APIs reais, gastos, credenciais e dados de clientes; operações limitadas documentadas anteriormente não autorizam uma nova instalação. O Portal conserva contrato, briefing, aceite expresso da versão exata e `ENTREGUE`; Sites conserva artefato e publicação técnica. Aceite, backup externo/restore, observação independente da publicação e homologação mantêm suas evidências próprias. Os [dez gates](../../config/operational-readiness.json), [P0/RF-PEND e bloqueio de rollback automático](../SITES-SEC-01-GATES.md) permanecem inalterados.

**Critério de continuidade:** contrato candidato, armazenamento nativo e consumidores já permitem avançar código e CI isolados. Fonte oficial, credenciais e autorização do ambiente permitem preparar depois a operação concreta para revisão. As pendências operacionais não exigem pedir novamente autorização a cada entrega de código nem impedem uma próxima implementação reversível e verificável.
