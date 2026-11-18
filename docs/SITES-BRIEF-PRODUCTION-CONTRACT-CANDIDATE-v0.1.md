# Briefing Onepage e produção — contrato candidato v0.1

**Estado: proposta revisável, sem homologação ou ativação.** Data: 18/11/2026.
Base Sites: `1634bc45094abfe51b6e391877b4b7f8fdadf692` (#516). Origem de regras
consultada: Portal #941, `0e0ccc06755b6ddd938635e5c4c3625ff6497e2c`.

Esta proposta define um snapshot privado de briefing rico Onepage e um plano
que o vincula a uma entrada editorial separada, já consumível pelo renderer
local. A primeira implementação útil será conferir esses arquivos e conservar
proveniência privada do HTML produzido. O recebimento durável e autenticado de
briefing pelo Sites permanece uma etapa posterior, com requisitos explícitos.

Os schemas e exemplos deste documento não são um contrato oficial do Portal,
um endpoint, uma autorização de produção ou uma prova de pagamento. A proposta
não altera runtime, migrations, grants, gates, publicação ou rollback automático.
As escolhas identificadas como propostas são decisões de engenharia abertas à
revisão; não foram atribuídas à implementação legada.

## 1. Evidência e consumidor concreto

| Evidência existente | Consequência para esta proposta |
| --- | --- |
| [Inventário F1-20 com oito fontes fixadas](SITES-F1-20-PORTAL-LEGACY-PARITY.md#fonte-fixada-em-18112026), em particular [briefing.mjs](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/server/briefing.mjs) e [briefing-ui.js](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/briefing-ui.js) | Há um corpo rico Onepage, checklist, materiais e contador agregado verificáveis. Não há nesse fluxo revisão imutável do corpo ou envelope autenticado para o Sites. |
| [CT-01](SITES-CT-01-PORTAL-SITES-v0.1.md) e [fronteiras ARQ-02](SITES-ARQ-02-BOUNDARIES.md) | `SubmitBriefRevision` e `RequestGenerateRelease` já são necessidades identificadas. Portal conserva briefing oficial, contrato e aceite; Sites conserva artefato e publicação técnica. Os nomes lógicos não definem sozinhos o wire contract. |
| [Recebimento nativo de projeto](PORTAL-PROJECT-INTAKE.md) e [comando existente](../src/Integration/SiteProjectCommand.php) | Pedido, contrato e projeto já têm referências distintas. O Sites gera `siteRef`; o recibo permanece `awaiting_official_authority`. Não é necessário duplicar essa criação para propor briefing. |
| [`LocalSiteRendering::render`](../src/Production/LocalSiteRendering.php), [`SiteBrief`](../src/Production/SiteBrief.php) e [`render.php`](../ops/release-candidate/render.php) | Existe um consumidor determinístico de seis campos editoriais, sem DB, provider ou rede. Ele não aceita o corpo rico do cliente. |
| [`register.php`](../ops/release-candidate/register.php), [`PrivateReleaseRegistration`](../src/Publication/PrivateReleaseRegistration.php) e [`PgSiteReleaseRegistry`](../src/Publication/PgSiteReleaseRegistry.php) | Os bytes privados resultantes já podem alimentar uma solicitação explícita de registro, sob as verificações existentes de principal, empresa, digest e referência. Renderizar não executa esse registro. |

O perfil de origem v0.1 é somente **Onepage**. O legado inspecionado exige
`product_id = site`; seu catálogo identifica Site Onepage. O suporte técnico do
renderer a `landing_page` e os objetos SAMPLE/DEMO do Portal não fornecem um
briefing oficial Landing. O documento não transfere silenciosamente essas regras.

## 2. Identidades e revisões separadas

| Identificador | Significado e limite |
| --- | --- |
| `tenantRef`, `orderRef`, `contractRef`, `contractRevision` | Seletores do contexto Portal. Um futuro receptor deve compará-los ao vínculo nativo persistido e à autoridade corrente, derivada da identidade autenticada. O JSON não concede acesso. |
| `siteRef` | UUID minúsculo do projeto gerado pelo Sites no recebimento nativo. No ensaio local é apenas uma declaração sintética de escopo. |
| `sourceVersion` | Contador agregado do pedido na origem. Salvar, enviar, revisar e enviar/remover material o incrementam. Pode haver nova versão sem alteração do texto. Não equivale à revisão de conteúdo ou de HTML. |
| `snapshotRef` | UUID minúsculo proposto para identificar uma exportação imutável. O exportador o fixa antes da primeira solicitação e o conserva no replay. Não substitui identidade autenticada. |
| `briefRevision` | Sequência imutável **proposta** de snapshots aceitos para o projeto. Ainda não existe no banco atual. Cada nova aceitação avança exatamente uma unidade, mesmo que o corpo textual seja igual e outro componente da origem tenha mudado. |
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

## 4. Manifesto de snapshot proposto

O [schema de snapshot](../contracts/portal-sites/candidates/brief-production-v1/brief-snapshot.schema.json)
possui exatamente estas dezessete chaves:

| Campo | Regra |
| --- | --- |
| `schemaVersion` | `sites.brief-snapshot.candidate.v0.1` |
| `operationId` | Referência opaca da solicitação de snapshot |
| `idempotencyKey` | Referência opaca própria para repetição dessa solicitação |
| `tenantRef` | Organização declarada; confrontar com identidade e vínculo nativos em um receptor autenticado |
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

### Persistência, CAS e replay: proposta para o receptor futuro

O recebimento durável abaixo **não está implementado por esta entrega**, nem
pelo modo local descrito na seção 6. Ele explicita o comportamento a exigir de
um futuro consumidor de `SubmitBriefRevision`, sem escolher transporte, endpoint
ou credenciais:

1. Autenticar o serviço e conferir a capacidade corrente por ação e recurso.
   Derivar tenant e projeto do vínculo persistido, confrontar pedido/contrato e
   suas revisões. `can_receive_project` não autoriza receber ou aprovar briefing.
   O estado aprovado precisa vir de fonte Portal verificável, não do campo JSON.
2. Resolver replay sob o mesmo escopo de tenant/projeto/tipo de comando, antes
   de exigir que o head atual ainda seja o CAS antigo. Operação, chave, snapshot
   e digest do manifesto original devem coincidir. A mesma identidade com
   conteúdo diferente é conflito; não corrige, renomeia ou cria outra revisão.
3. Para uma solicitação nova, comparar `expectedBriefRevision` ao head corrente
   e exigir a equação da revisão. Serializar essa decisão com persistência do
   snapshot e do recibo. Uma única concorrente pode avançar o head; a outra
   recebe conflito, sem retry que invente uma nova revisão esperada.
4. Vincular `sourceVersion` ao mesmo pedido/perfil e exigir avanço para um novo
   snapshot. A versão da origem pode saltar, mas a revisão Sites avança uma.
   Uma versão de origem já aceita não pode reaparecer com outros bytes/materiais
   nem ser recriada com novas chaves para contornar um recibo perdido.
5. Conservar imutavelmente manifesto original, descritores, proveniência
   autenticada e resultado técnico. Replay retorna o resultado histórico sem
   novo evento, sem mover o head para trás e sem afirmar autorização comercial
   atual. Acesso corrente continua necessário; revogação impede novo acesso ou
   efeito, mesmo diante de um identificador conhecido.

O exportador precisa fixar bytes, referências e versão de um estado consistente
da origem **antes** do envio e conservar esse conjunto para repetir a operação.
O contador agregado e os arquivos mutáveis do legado não oferecem, por si só,
essa exportação imutável. A implementação da fonte deve resolver essa lacuna;
um receptor não pode preenchê-la atribuindo confiança a um hash enviado.

Uma falha antes da transação persistente não cria snapshot. Uma falha de commit
ou perda de resposta pode ser inconclusiva: consultar ou repetir exatamente a
mesma solicitação recupera a evidência, sem presumir ausência de escrita. Não
há recibo implementado aqui nem promessa de atomicidade entre DB e arquivos.
Consulta/revogação e retenção desse registro pertencem ao desenho do receptor,
não a um contador mantido no processo de renderização.

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

## 6. Primeiro incremento de código executável após esta proposta

**Escolha proposta:** ampliar o comando existente, preservando seu modo atual:

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

O sidecar privado proposto tem exatamente sete campos:

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
técnica local candidata, não o recibo futuro de `SubmitBriefRevision`.

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
| CAS proposto, separação de versões e regra de replay exato | Receptor durável autenticado, capacidade própria, recibo, consulta e revogação por recurso |
| Entrada editorial manual separada e conferência no renderer existente | Aprovação humana comprovada e política de início de produção; um arquivo local não identifica o aprovador |
| Modo local sem materiais, sidecar privado e integração explícita com registro já existente | Acesso/validação de materiais reais, direitos, retenção, dados pessoais e credenciais operacionais |
| Exemplos fictícios Onepage e testes futuros do consumidor local | Regras de Landing, obrigatoriedade de domínio na aprovação e demais diferenças de produto |
| Manter todos os indicadores de autoridade falsos | Contrato/pagamento oficial, aceite expresso da release exata, publicação observada e `ENTREGUE` do Portal |

Os estados financeiros legados, a dispensa administrativa do gate de pagamento,
sete dias úteis e duas rodadas não são transportados como regra operacional.
Prazos e rodadas dependem do contrato aprovado. A prova de TLS de staging já
existente não precisa ser repetida para definir este perfil; ela também não
substitui autorização por recurso, fonte oficial ou aprovação de uso real.

Ausência de contrato oficial não bloqueia a implementação reversível do modo
local acima, pois existe consumidor e os efeitos são privados e técnicos.
Bloqueia tratar seus seletores ou resultados como comando oficial autenticado.
Os gates de [AGENTS](../AGENTS.md) e [SEC-01](SITES-SEC-01-GATES.md) continuam
inalterados; AGI-11-RB01 permanece desabilitado.

## 8. Artefatos revisáveis e critérios de aceitação

Os [três schemas e cinco exemplos](../contracts/portal-sites/candidates/brief-production-v1/README.md)
são inteiramente sintéticos. O corpo rico não foi reduzido automaticamente aos
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

Nesta especificação, os três schemas e seus exemplos foram validados com JSON
Schema 2020-12, os quatro vínculos de bytes foram recalculados e o HTML foi
reproduzido exatamente pelo renderer existente. Identidade entre arquivos,
equação de revisão e limites UTF-16 dos exemplos foram conferidos separadamente.
Uma revisão cruzada de documento/schemas não encontrou bloqueadores. O scan de
segurança passou sobre 632 arquivos de fonte, conservando os dez gates BLOCKED.
Essas verificações documentais não executam o consumidor novo proposto abaixo.

A implementação seguinte será aceita quando exercitar pelo comando real:

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

Esses são critérios para o incremento ainda não implementado, não resultados
de CI desta especificação. A próxima entrega pode acrescentar validação e o
modo explícito na CLI existente sem criar worker, storage genérico ou protocolo
oficial. O receptor durável futuro exigirá provas próprias de CAS concorrente,
replay, revogação, isolamento e falha de commit, ligadas ao consumidor real.
