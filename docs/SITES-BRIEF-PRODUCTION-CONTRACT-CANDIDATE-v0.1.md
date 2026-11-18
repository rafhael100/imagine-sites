# Briefing Onepage e produção — contrato candidato v0.1

**Estado: contrato candidato com consumo local e armazenamento técnico nativo implementados, sem homologação ou ativação operacional.** Data: 18/11/2026.
Base da proposta: `1634bc45094abfe51b6e391877b4b7f8fdadf692` (#516). Origem de regras
consultada: Portal #941, `0e0ccc06755b6ddd938635e5c4c3625ff6497e2c`.

Esta proposta define um snapshot privado de briefing rico Onepage e um plano
que o vincula a uma entrada editorial separada, já consumível pelo renderer
local. O [primeiro consumidor local](reviews/SITES-LOCAL-RENDER-PROVENANCE-2026-11-18.md),
implementado sobre a proposta #517 / `c53eac9eb8060a62eb060e68f3bc5b2956dbbdff`,
confere esses arquivos e conserva proveniência privada do HTML produzido.
O [armazenamento nativo candidato](PORTAL-BRIEF-SNAPSHOTS.md), na branch
`feature/sites-native-brief-snapshots-2026-11-18` sobre a #518 /
`907e2883393b776eebcba59fafb672aa34c99bf6`, acrescenta persistência imutável,
CAS, replay e leitura exata sob identidade SQL. Seu consumidor interno renderiza
os bytes armazenados. A autenticação da aprovação Portal, o exportador oficial
e o transporte entre serviços continuam exigindo integração própria.

Os schemas e exemplos deste documento não são um contrato oficial do Portal,
um endpoint, uma autorização de produção ou uma prova de pagamento.
A proposta #517 não alterou runtime. O incremento local #518 ampliou a CLI
existente e continua sem DB. O armazenamento candidato acrescenta a migration 13,
sem LOGIN, principal ativo, grant operacional, endpoint ou mudança dos gates.
As escolhas identificadas como propostas são decisões de engenharia abertas à
revisão; não foram atribuídas à implementação legada.

## 1. Evidência e consumidor concreto

| Evidência existente | Consequência para esta proposta |
| --- | --- |
| [Inventário F1-20 com oito fontes fixadas](SITES-F1-20-PORTAL-LEGACY-PARITY.md#fonte-fixada-em-18112026), em particular [briefing.mjs](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/server/briefing.mjs) e [briefing-ui.js](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/briefing-ui.js) | Há um corpo rico Onepage, checklist, materiais e contador agregado verificáveis. Não há nesse fluxo revisão imutável do corpo ou envelope autenticado para o Sites. |
| [CT-01](SITES-CT-01-PORTAL-SITES-v0.1.md) e [fronteiras ARQ-02](SITES-ARQ-02-BOUNDARIES.md) | `SubmitBriefRevision` e `RequestGenerateRelease` já são necessidades identificadas. Portal conserva briefing oficial, contrato e aceite; Sites conserva artefato e publicação técnica. Os nomes lógicos não definem sozinhos o wire contract. |
| [Recebimento nativo de projeto](PORTAL-PROJECT-INTAKE.md) e [comando existente](../src/Integration/SiteProjectCommand.php) | Pedido, contrato e projeto já têm referências distintas. O Sites gera `siteRef`; o recibo permanece `awaiting_official_authority`. Não é necessário duplicar essa criação para propor briefing. |
| [`LocalSiteRendering::render`](../src/Production/LocalSiteRendering.php), [`SiteBrief`](../src/Production/SiteBrief.php) e [`render.php`](../ops/release-candidate/render.php) | Existe um consumidor determinístico de seis campos editoriais, sem DB, provider ou rede. Ele não aceita o corpo rico do cliente. |
| [Snapshot nativo e consumidor persistido](PORTAL-BRIEF-SNAPSHOTS.md) | A migration 13 conserva os dois JSONs originais; um reader separado revalida o perfil e resolve a seleção exata para o renderer. A identidade SQL não autentica a aprovação declarada pelo Portal. |
| [`register.php`](../ops/release-candidate/register.php), [`PrivateReleaseRegistration`](../src/Publication/PrivateReleaseRegistration.php) e [`PgSiteReleaseRegistry`](../src/Publication/PgSiteReleaseRegistry.php) | Os bytes privados resultantes já podem alimentar uma solicitação explícita de registro, sob as verificações existentes de principal, empresa, digest e referência. Renderizar não executa esse registro. |

O perfil de origem v0.1 é somente **Onepage**. O legado inspecionado exige
`product_id = site`; seu catálogo identifica Site Onepage. O suporte técnico do
renderer a `landing_page` e os objetos SAMPLE/DEMO do Portal não fornecem um
briefing oficial Landing. O documento não transfere silenciosamente essas regras.

## 2. Identidades e revisões separadas

| Identificador | Significado e limite |
| --- | --- |
| `tenantRef`, `orderRef`, `contractRef`, `contractRevision` | Seletores do contexto Portal. O receptor candidato deriva o tenant do principal SQL corrente e confronta esses valores com o intake imutável. Isso não verifica elegibilidade comercial atual; o JSON não concede acesso. |
| `siteRef` | UUID minúsculo do projeto gerado pelo Sites no recebimento nativo. No comando local é uma declaração de escopo; no armazenamento candidato precisa coincidir com o intake do tenant autorizado. |
| `sourceVersion` | Contador agregado do pedido na origem. Salvar, enviar, revisar e enviar/remover material o incrementam. Pode haver nova versão sem alteração do texto. Não equivale à revisão de conteúdo ou de HTML. |
| `snapshotRef` | UUID minúsculo proposto para identificar uma exportação imutável. O exportador o fixa antes da primeira solicitação e o conserva no replay. Não substitui identidade autenticada. |
| `briefRevision` | Sequência imutável dos snapshots candidatos armazenados pela migration 13, por tenant/projeto. Cada nova linha avança exatamente uma unidade; `sourceVersion` também precisa avançar, mas pode saltar ou acompanhar corpo textual idêntico. Não é a revisão editorial. |
| `editorialRevision` | Sequência editorial já existente no Sites: inclui registrar, revisar, submeter, aprovar internamente e reabrir. Não é a revisão do briefing Portal. |
| `releaseRef` e digest do artefato | Referência técnica e SHA-256 dos bytes de uma saída. Não representam aceite do cliente, pagamento, publicação observada ou `ENTREGUE`. |

`operationId` e `idempotencyKey` continuam campos distintos. A operação de
snapshot e a operação de render têm identidades próprias; seus valores **não
precisam ser iguais**. Uma seleção de snapshot permanece explícita se surgir uma
versão posterior durante o trabalho. Nenhum consumidor pode trocar a seleção
automaticamente ou herdar aprovação de outra revisão.

## 3. Corpo rico privado e regras do perfil

O [schema do corpo rico](../contracts/portal-sites/candidates/brief-production-v1/onepage-brief-payload.schema.json)
exige exatamente os quatorze campos abaixo, sem `schemaVersion` interno. O
manifesto externo identifica `sites.onepage-brief-payload.candidate.v0.1`.

| Campos | Tipo | Limite de compatibilidade por campo |
| --- | --- | --- |
| `company_name` | string | 160 unidades UTF-16 |
| `company_intro`, `services` | string | 10000 unidades UTF-16 cada |
| `current_site`, `contact_site` | string | 800 unidades UTF-16 cada |
| `domain` | string | 253 unidades UTF-16 |
| `email` | string | 254 unidades UTF-16 |
| `phone`, `whatsapp` | string | 60 unidades UTF-16 cada |
| `address` | string | 600 unidades UTF-16 |
| `notes` | string | 5000 unidades UTF-16 |
| `ai_support`, `logo_unavailable`, `manual_unavailable` | boolean | Somente `true` ou `false` reais |

Os limites acima reproduzem a unidade usada pelo handler, antes do `trim()`.
Um caractere astral ocupa duas unidades UTF-16; isso difere dos codepoints usados
por `SiteBrief` e de bytes UTF-8. A anotação
`x-legacy-max-utf16-code-units-before-trim` do schema não executa essa regra.
A validação semântica precisa contá-la separadamente, como documentado no
[inventário de unidades](SITES-F1-20-PORTAL-LEGACY-PARITY.md#campos-e-unidades-da-origem).

**Diferenças propostas e explícitas:** exigir todas as chaves e tipos, recusar
extras, duplicatas de chaves inclusive com escapes equivalentes, UTF-8 inválido
e NUL. O consumidor não preenche valores ausentes, converte tipos, trunca texto,
normaliza Unicode ou reaplica `trim()` aos bytes. O legado tolerava ausentes/null,
ignorava extras e aparava strings; essa tolerância não é copiada para o contrato.
Texto multilinha válido continua permitido nos campos textuais correspondentes.

O perfil candidato adota como condições de completude: nome e serviços não
vazios; apresentação não vazia ou `ai_support=true`; ao menos um entre e-mail,
telefone e WhatsApp; material de cada tipo ou a declaração de indisponibilidade
correspondente. Para avaliar vazio e sintaxe usa-se uma cópia lógica com a
semântica de `trim()` do ECMAScript, depois de medir o valor original; os bytes
armazenados e seus hashes permanecem intocados. Campos opcionais vazios são
permitidos. Quando presentes, `current_site`/`contact_site` são referências HTTP
ou HTTPS sem usuário/senha; e-mail e domínio seguem a validação de compatibilidade
fixada no handler, com domínio como hostname ASCII e não URL.

O primeiro consumidor PHP aceita um **subconjunto explícito das URLs WHATWG**
do legado: esquema HTTP(S) escrito em ASCII; DNS ASCII convencional, sem labels
IDNA (`xn--`) ou ponto final; IPv4 canônico ou IPv6 entre colchetes; porta decimal
de até cinco dígitos, de 0 a 65535. Recusa usuário/senha, barra invertida,
controles, espaços internos e formas que dependeriam de normalização WHATWG,
como host Unicode ou IPv4 abreviado. Caminho e query UTF-8 permanecem texto
literal. A avaliação usa somente a cópia lógica aparada; os bytes e hashes
originais não mudam. Não se afirma paridade integral de URLs entre PHP e Node,
e nenhuma referência é resolvida ou acessada pela rede.

Essas condições verificam completude declarada, não que o cliente confirmou o
envio. `confirmed=true` era entrada de uma ação separada na origem e não é
adicionado ao corpo. `sourceState=approved` também não prova quem aprovou ou
se a aprovação ainda vale. A exigência de domínio para aprovação permanece
**decisão de produto não homologada**; o perfil técnico aceita domínio vazio.
`ai_support=true` não autoriza chamada a IA, gasto ou uso de dados por provider.

### Bytes e referências privadas

Todo descritor de payload tem exatamente `{payloadRef, mediaType, sizeBytes,
sha256}`. `payloadRef` é um identificador opaco, nunca URL, caminho, credencial
ou capacidade de leitura. O local físico é fornecido separadamente por quem
opera o consumidor, sujeito à custódia de arquivo; o manifesto não escolhe
diretórios, rede ou storage.

`sha256` é hexadecimal minúsculo de 64 posições sobre os **bytes completos** do
arquivo. Espaços, ordem de chaves, escapes, CRLF e LF final participam do hash.
Não existe equivalência por re-encode ou JSON canônico neste perfil. JSON válido
com outra serialização precisa de outro hash e não é replay exato do arquivo
anterior. Os objetos exigem chaves exatas e inteiros JSON em notação decimal,
sem expoente ou parte fracionária; JSON Schema sozinho não garante essas regras
lexicais nem a recusa de chaves duplicadas.

O corpo rico admite **1..150000 bytes**, teto técnico proposto para reutilizar
[`SiteReleasePreparationFiles::read`](../src/Publication/SiteReleasePreparationFiles.php).
Ele difere dos 128000 bytes do body HTTP legado e dos limites UTF-16 por campo.
Uma serialização que exceda o teto é recusada integralmente; não se promete
representar todo documento possível sob os limites isolados de cada campo.
Os dois manifestos admitem até 32768 bytes cada no primeiro consumidor local;
o input editorial mantém o teto próprio de 32768 bytes.

### Materiais

`materials` admite 0..10 itens, cada um exatamente `{kind, payload}`. `kind` é
`logo` ou `manual`; o descritor identifica bytes privados e seu digest. Logo
admite os tipos declarados `image/png`, `image/jpeg`, `image/webp` ou
`application/pdf`; manual admite somente `application/pdf`. Os limites propostos
retêm a referência de 10 MiB por arquivo e 50 MiB no conjunto. `payloadRef` de
cada material deve ser único no conjunto. Não se transportam nome de storage,
URL assinada, autor interno ou nome original como caminho.

O tipo declarado e o hash não provam arquivo seguro, licença de uso ou permissão
de download. As checagens legadas de extensão/assinatura são superficiais. Um
futuro consumidor de materiais precisa de autorização corrente, validação de
conteúdo e política de retenção próprias. URLs textuais do briefing são apenas
referências: nenhum consumidor deste recorte as visita ou obtém recursos delas.

**Primeiro perfil executável:** `materials=[]`, `logo_unavailable=true` e
`manual_unavailable=true`. Qualquer plano com materiais ou sem essas declarações
é explicitamente não suportado nesse incremento. Não se ignora uma lista de
materiais para produzir sucesso aparente.

## 4. Manifesto e armazenamento candidato de snapshot

O [schema de snapshot](../contracts/portal-sites/candidates/brief-production-v1/brief-snapshot.schema.json)
possui exatamente estas dezessete chaves:

| Campo | Regra |
| --- | --- |
| `schemaVersion` | `sites.brief-snapshot.candidate.v0.1` |
| `operationId` | Referência opaca da solicitação de snapshot |
| `idempotencyKey` | Referência opaca própria para repetição dessa solicitação |
| `tenantRef` | Organização declarada; o receptor candidato confronta com o intake do tenant derivado da identidade SQL |
| `siteRef` | UUID minúsculo do projeto Sites |
| `orderRef` | Pedido associado ao projeto |
| `contractRef` | Contrato associado ao contexto da solicitação |
| `contractRevision` | Inteiro 1..2147483647; não prova elegibilidade financeira |
| `productCode` | `onepage` |
| `sourceProfile` | `sites.onepage-brief-payload.candidate.v0.1` |
| `sourceVersion` | Inteiro 0..9007199254740991, contador agregado da origem |
| `sourceState` | `approved`, declaração não autenticada pelo arquivo |
| `snapshotRef` | UUID minúsculo da exportação imutável proposta |
| `expectedBriefRevision` | Inteiro 0..2147483646; zero identifica ausência de snapshot anterior |
| `briefRevision` | Inteiro 1..2147483647, exatamente `expectedBriefRevision + 1` |
| `payload` | Descritor do JSON rico: `application/json`, tamanho e SHA dos bytes exatos |
| `materials` | Lista privada descrita acima; vazia no primeiro consumidor |

Referências opacas seguem `^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$`, com 1..128
caracteres e sem controles. UUIDs e SHAs seguem os formatos fechados dos schemas.
Nenhuma referência autoriza resolver ou ler o recurso.

### Persistência, CAS e replay implementados no candidato

A [API SQL](../contracts/portal-sites/002-brief-snapshots.sql) e os serviços
descritos no [manual de snapshots](PORTAL-BRIEF-SNAPSHOTS.md) implementam o
recebimento técnico abaixo. Não ativam o transporte lógico de
`SubmitBriefRevision` nem alteram o comando local sem DB da seção 6.

1. O receiver confere a capacidade corrente e deriva o tenant de `session_user`.
   Resolve o projeto pelo intake imutável e confronta pedido, contrato, revisão
   contratual e produto. `can_receive_project` não autoriza receber briefing:
   há principal e função SQL próprios. Essa identidade não autentica o estado
   `approved` declarado no arquivo.
2. O replay é resolvido no mesmo tenant/projeto antes do CAS do head. Operação,
   chave, snapshot, `sourceVersion`, hashes e ambos os JSONs originais precisam
   coincidir. Uma identidade parcialmente coincidente ou conteúdo diferente
   gera conflito, sem renomear ou criar outra revisão.
3. Para uma linha nova, o SQL compara `expectedBriefRevision` ao head e exige a
   equação da revisão. O lock de site serializa a decisão com a gravação;
   comandos diferentes disputando o mesmo CAS não criam duas revisões.
   Recorder e reader têm uma tentativa, sem incremento ou retry automático.
4. `sourceVersion` precisa superar a última versão recebida nesse contexto.
   Pode saltar, enquanto `briefRevision` avança uma unidade. Uma versão da
   origem já recebida não pode ser reutilizada por novas chaves.
5. O armazenamento mantém os JSONs, hashes, identidade/epoch do receiver e
   timestamp originais. Replay conserva a evidência histórica, inclusive após
   novo head ou por outro receiver atualmente autorizado. Revogar o receiver
   anterior não apaga a linha; toda nova gravação, repetição ou leitura continua
   exigindo a capacidade corrente de quem faz a chamada.

O [recibo técnico](../contracts/portal-sites/candidates/brief-production-v1/brief-snapshot-receipt.schema.json)
possui treze campos: `schemaVersion`, `status`, `siteRef`, `snapshotRef`,
`briefRevision`, `sourceVersion`, `manifestSha256`, `payloadSha256`,
`recordedAt`, `authorityVerified`, `clientAccepted`, `canPublish` e
`canAcceptRealOrders`. As quatro flags são falsas. `status=recorded` não
declara criação pela chamada atual; `recordedAt` conserva o timestamp UTC6 da
linha, conferido pelo PHP por round-trip de calendário.

O reader usa conexão dedicada com default e transação somente leitura e resolve
exatamente `siteRef`, `snapshotRef`, `briefRevision` e `manifestSha256`.
O SQL valida estrutura, tipos, hashes e contexto; o objeto PHP compartilhado
também valida completude, UTF-16 e URL/e-mail/domínio. Um corpo gravado
diretamente pela API SQL pode satisfazer a estrutura sem satisfazer todo o perfil
rico. Reader e renderer revalidam os bytes e recusam esse caso. O recibo não
certifica a semântica rica nem a aprovação da origem.

### Requisitos operacionais ainda separados

O exportador precisa fixar bytes, referências e versão de um estado consistente
da origem **antes** do envio e conservar esse conjunto para repetir a operação.
O contador agregado e os arquivos mutáveis do legado não oferecem, por si só,
essa exportação imutável. A implementação da fonte deve resolver essa lacuna;
um receptor não pode preenchê-la atribuindo confiança a um hash enviado.

Endpoint, autenticação entre serviços, autorização de dados reais e retenção
operacional ainda precisam de definição e evidência próprias. O armazenamento
candidato não demonstra que o Portal emitiu ou aprovou o conteúdo recebido.

Uma falha antes da transação persistente não cria snapshot. Uma falha de commit
ou perda de resposta pode ser inconclusiva; uma nova chamada explícita com a
solicitação original ou a seleção exata recupera evidência sob autorização
corrente, sem presumir ausência de escrita. Não há atomicidade entre DB e
arquivos. `StoredBriefRendering` retorna HTML/proveniência em memória; a relação
durável entre snapshot e artefato do registry ainda exige uma entrega própria.

## 5. Plano técnico para o renderer existente

O [schema do plano](../contracts/portal-sites/candidates/brief-production-v1/brief-render-plan.schema.json)
tem exatamente nove chaves. O consumidor é fixo: `LocalSiteRendering::render`.
O manifesto não seleciona código, provider, ferramenta ou comando de shell.

| Campo | Regra |
| --- | --- |
| `schemaVersion` | `sites.brief-render-plan.candidate.v0.1` |
| `operationId` | Referência opaca da intenção de render, distinta da operação de snapshot |
| `idempotencyKey` | Chave própria dessa intenção; não cria deduplicação persistente no modo local |
| `tenantRef` | Deve coincidir exatamente com o snapshot |
| `siteRef` | Deve coincidir exatamente com o snapshot |
| `releaseRef` | Referência técnica destinada ao registro explícito posterior |
| `snapshot` | Exatamente `{snapshotRef, briefRevision, manifestSha256}`; refs/revisão iguais às do manifesto e hash dos bytes originais desse arquivo |
| `renderInput` | Descritor privado `application/json`, 1..32768 bytes e hash do input editorial de seis campos |
| `expectedArtifact` | Exatamente `{sha256, sizeBytes}`; expectativa do HTML, 1..150000 bytes |

O JSON editorial conserva exatamente `productCode`, `brand`, `headline`,
`description`, `callToAction` e `benefits`, já definidos pelo parser atual. Nesse
perfil, `productCode` deve ser `onepage` e coincidir com o snapshot. Permanecem
os limites de codepoints de `SiteBrief`: marca 120, título 180, descrição 600,
CTA 80 e de um a oito benefícios com até 180 cada, além da validação existente.

O texto editorial é selecionado por uma pessoa e entregue separadamente. Não
se supõe que `company_intro` caiba em `description`, que `services` seja uma
lista de benefícios ou que o cliente tenha fornecido título e CTA. O modo local
não verifica a identidade dessa pessoa nem equivale à aprovação editorial
durável. Nenhum campo `approvedBy` ou booleano de autoridade supre esse fluxo.

O consumidor verifica os quatro arquivos: plano, manifesto do snapshot, corpo
rico e input editorial. Verifica estruturas, completude, limites, escopo,
revisões declaradas, bytes/digests de cada vínculo e o produto. Depois aplica
o renderer e auditoria inerte existentes ao input editorial e compara os bytes
resultantes a `expectedArtifact`. Divergência é recusa integral; não recalcula
a expectativa no plano, usa template alternativo ou substitui o snapshot.

`expectedArtifact` é uma expectativa verificável, não um recibo de execução.
Pode ser preparado com o renderer já existente. O novo modo acrescenta uma
conferência e proveniência; não adiciona geração criativa, IA ou um worker vazio.

## 6. Primeiro incremento de código executável

**Implementado em candidato isolado:** o comando existente foi ampliado,
preservando seu modo anterior. [Serviço](../src/Production/BriefRenderProvenance.php)
e [relatório de validação](reviews/SITES-LOCAL-RENDER-PROVENANCE-2026-11-18.md):

```text
php ops/release-candidate/render.php render \
  --brief=/diretorio-privado/render-input.json \
  --output=/diretorio-privado/saida/index.html \
  --plan=/diretorio-privado/render-plan.json \
  --snapshot=/diretorio-privado/brief-snapshot.json \
  --source-brief=/diretorio-privado/onepage-brief.json
```

`--plan`, `--snapshot` e `--source-brief` formam um grupo opcional indivisível.
Sem o grupo, o contrato atual de render permanece. Com o grupo, não há caminhos
derivados de `payloadRef`: o operador fornece os quatro inputs explicitamente.
O validador novo tem como chamador essa CLI e reutiliza `LocalSiteRendering`
para os seis campos e bytes de saída, sem duplicar seu parser ou renderer.

A custódia reaproveita as verificações dos
[arquivos de render](../src/Production/LocalSiteRenderingFiles.php) e do leitor
privado existente. Inputs são arquivos privados, regulares, sem links, com
identidade, bytes e ancestrais observados e revalidados antes de escrever. O
parent da saída é canônico, pertence ao EUID e tem modo 0700. O modo novo reserva
exclusivamente dois arquivos 0600 no mesmo parent, mantendo ambos os FDs:
`index.html` e **`render-provenance.json`**. Não há overwrite, chmod reparador,
rename, limpeza automática ou segunda árvore escolhida por referência.

O sidecar privado tem exatamente sete campos:

| Campo | Conteúdo |
| --- | --- |
| `schemaVersion` | `sites.local-render-provenance.candidate.v0.1` |
| `status` | `rendered` |
| `planSha256` | SHA-256 dos bytes completos do plano lido |
| `artifact` | Exatamente `{sha256, sizeBytes}` dos bytes de HTML conferidos |
| `authorityVerified` | `false` |
| `canPublish` | `false` |
| `canAcceptRealOrders` | `false` |

O hash do plano compromete o snapshot exato, que compromete o corpo rico; também
compromete input editorial e expectativa de HTML. Não é necessário repetir
contatos ou referências comerciais no sidecar. Para inspecionar essa cadeia é
necessário conservar os arquivos originais: hashes não guardam seu conteúdo,
não autenticam o emissor e não tornam um arquivo impossível de substituir.

Sucesso exige escrita integral, flush/fsync, leitura de conferência dos bytes e
verificação de identidade/custódia de **ambos** os FDs e caminhos. Só então a CLI
imprime a projeção pública atual de sete campos: `schema`, `status`,
`inputSha256`, `artifactDigest`, `sizeBytes`, `canPublish=false` e
`canAcceptRealOrders=false`. O termo `inputSha256` continua referindo-se ao input
editorial. Plano, corpo rico, seletores e paths não são impressos. Erros continuam
limitados, sem conteúdo privado ou cadeia de exceções.

Os dois arquivos não constituem uma transação atômica. Uma falha pode deixar
reserva vazia, arquivo parcial ou um dos arquivos completo. Nada é apagado para
simular rollback. Repetir sobre uma saída existente é recusado; o operador
inspeciona os arquivos e, se necessário, usa um novo destino privado. Reproduzir
os mesmos inputs no mesmo renderer permite conferir os mesmos bytes, mas não
constitui replay durável ou prova de unicidade global da operação.

Este modo verifica a equação declarada de CAS e a consistência dos seletores;
não consulta head, recibo de intake, permissão, estado corrente ou pagamento.
Também não grava uma revisão de briefing em `sites_core`. O sidecar é evidência
técnica local candidata, distinto do recibo persistente descrito na seção 4.

O HTML continua com os mesmos avisos e bytes da demonstração inerte atual. Pode
ser usado como arquivo privado no `register.php` já existente, com manifesto de
registro explícito usando `siteRef`, `releaseRef`, digest e tamanho correspondentes.
Esse passo conserva sua autorização SQL e semântica própria de replay por ator/
epoch. O registro atual não ingere o sidecar nem persiste a relação com o briefing:
essa ligação durável ainda requer uma entrega própria. Não há registro,
aprovação, binding, publicação ou promoção automática no render.

## 7. Fronteiras de decisão e autoridade

| Pode ser fechado como engenharia candidata agora | Exige fonte, decisão ou evidência adicional para efeito real |
| --- | --- |
| Shape estrito, limites técnicos, unidade UTF-16, descritores privados e hashes de bytes exatos | Adoção pelo produtor Portal e captura consistente de uma revisão com autorização corrente |
| Armazenamento candidato, identidade SQL, CAS/replay e leitura exata implementados | Produtor e transporte Portal autenticados, aprovação verificável da origem e ativação de capacidades operacionais |
| Renderização em memória a partir do snapshot persistido e da entrada editorial separada | Relação durável entre snapshot e artefato registrado, com autorização própria e critérios de recuperação |
| Entrada editorial manual separada e conferência no renderer existente | Aprovação humana comprovada e política de início de produção; um arquivo local não identifica o aprovador |
| Modo local sem materiais, sidecar privado e integração explícita com registro já existente | Acesso/validação de materiais reais, direitos, retenção, dados pessoais e credenciais operacionais |
| Exemplos fictícios Onepage e testes do consumidor local | Regras de Landing, obrigatoriedade de domínio na aprovação e demais diferenças de produto |
| Manter todos os indicadores de autoridade falsos | Contrato/pagamento oficial, aceite expresso da release exata, publicação observada e `ENTREGUE` do Portal |

Os estados financeiros legados, a dispensa administrativa do gate de pagamento,
sete dias úteis e duas rodadas não são transportados como regra operacional.
Prazos e rodadas dependem do contrato aprovado. A prova de TLS de staging já
existente não precisa ser repetida para definir este perfil; ela também não
substitui autorização por recurso, fonte oficial ou aprovação de uso real.

Ausência de contrato oficial não bloqueou o modo local ou o armazenamento
candidato, pois ambos têm consumidores concretos e efeitos técnicos delimitados.
Continua impedindo tratar a declaração de aprovação ou o transporte ainda não
ativado como comando oficial autenticado.
Os gates de [AGENTS](../AGENTS.md) e [SEC-01](SITES-SEC-01-GATES.md) continuam
inalterados; AGI-11-RB01 permanece desabilitado.

## 8. Artefatos revisáveis e critérios de aceitação

O [conjunto atual de quatro schemas e seis exemplos](../contracts/portal-sites/candidates/brief-production-v1/README.md)
acrescenta o schema de recibo e seu exemplo fictício aos três schemas e cinco
exemplos originais da #517. O corpo rico não foi reduzido automaticamente aos
seis campos. O HTML do exemplo foi produzido pelo renderer existente, sem
alteração dos templates, avisos ou digest:

| Arquivo | Bytes | SHA-256 dos bytes exatos |
| --- | ---: | --- |
| `onepage-brief.synthetic.json` | 861 | `a3932320c0e4bd731ac7c18c61a46b39af09c8c503ed6489a9de4aa4c348a98b` |
| `render-input.synthetic.json` | 563 | `6b6a9fdf1af6bd9c0708b82d7d8467622e195626d8d6e47c103e3244e41eca1e` |
| `brief-snapshot.synthetic.json` | 845 | `5066ae0512fde48e94965ba767ccad1d53b2d347b2bd26ff5f06dc7fab895af0` |
| `rendered.synthetic.html` | 5167 | `12549ec46a09e83ea76353a43a48723daa99aa30c6bbdd461923f1a800543d15` |

Os JSONs conservam LF final; o HTML conserva a ausência de LF final do renderer.
Schemas verificam estrutura, não custódia, hash real, regra UTF-16, soma de
materiais, CAS, identidade ou autoridade. O exemplo de `sourceState=approved`
é uma declaração fictícia e não uma prova obtida no Portal.

Na proposta documental #517, os três schemas e seus exemplos foram validados com JSON
Schema 2020-12, os quatro vínculos de bytes foram recalculados e o HTML foi
reproduzido exatamente pelo renderer existente. Identidade entre arquivos,
equação de revisão e limites UTF-16 dos exemplos foram conferidos separadamente.
Uma revisão cruzada de documento/schemas não encontrou bloqueadores. O scan de
segurança passou sobre 632 arquivos de fonte, conservando os dez gates BLOCKED.
Essas verificações documentais pertencem à #517 e não executaram o consumidor
novo. A entrega de implementação possui provas próprias no relatório vinculado.

Os critérios do incremento local são exercitados pelo serviço e comando reais:

- O exemplo de quatro arquivos, byte a byte, com sidecar ligado ao plano e HTML
  idêntico ao renderer direto; o modo antigo continua com sua saída conhecida.
- Recusa de grupo parcial de flags, chaves duplicadas/extra, produto divergente,
  campo acima do limite, UTF-8/NUL inválidos, CAS declarativo inconsistente,
  troca de tenant/site/revisão e divergência em qualquer tamanho/digest.
- Recusa de materiais nesse primeiro perfil; URLs textuais permanecem inertes
  e não provocam rede. Alterar só espaço ou LF em um arquivo invalida o vínculo
  anterior, sem normalização silenciosa.
- Preservação de arquivo existente, substituição observável de input durante a
  operação recusada, reserva exclusiva dos dois destinos e resultado de falha
  descrito honestamente quando um arquivo já tiver sido criado.
- Saída pública sem conteúdo rico, refs comerciais, paths ou autoridade; nenhum
  acesso a DB, Portal, provider, fila, publicação ou gate novo.

Os testes locais do incremento passaram com 109 checks do serviço, 80 da nova
CLI e os 137/87 anteriores. A fixture acrescentou quatro verificações ao
registro; a [PR #518](https://github.com/rafhael100/imagine-sites/pull/518),
HEAD `907e2883393b776eebcba59fafb672aa34c99bf6`, confirmou **62 checks de registro/922 registry**
e limpeza dos clusters no [run 44323010319](https://github.com/rafhael100/imagine-sites/actions/runs/44323010319),
em PHP 8.3/8.4. A entrega concluiu doze check-runs SUCCESS.
A matriz Laravel dessa mesma entrega confirmou **206/2174/27** no modo padrão e
**206/2186/21** no opt-in sintético. Esses números continuam pertencendo à #518.

O incremento de armazenamento tem **45 testes novos**: os onze testes do input
passaram na execução anterior e ficaram intactos; os serviços passaram após
ERRMODE com **34 testes/904 assertions**, sem avisos. A extração conservou os
109 checks existentes de proveniência. A suíte geral 249/3133/27 foi executada
antes desse último ajuste transacional, portanto não é o resultado final dele.
O [recibo de exemplo](../contracts/portal-sites/candidates/brief-production-v1/examples/brief-snapshot-receipt.synthetic.json)
foi validado estruturalmente; seu calendário requer um format checker em JSON
Schema e não representa commit. O PHP confere o timestamp por round-trip exato.

As provas PostgreSQL de CAS concorrente, replay, revogação, isolamento,
recuperação e consumo persistido estão na fixture candidata, ainda pendentes de
CI. A compatibilidade registry prevê **923 checks**; não há resultado atribuído
à nova branch antes de sua execução. O [manual](PORTAL-BRIEF-SNAPSHOTS.md)
descreve a reversão e os limites desse armazenamento técnico.
