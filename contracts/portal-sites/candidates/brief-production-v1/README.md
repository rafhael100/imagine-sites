# Briefing Onepage, snapshots e consumo — candidato v0.1

Estes arquivos são uma **proposta revisável**, com consumo local no `render.php`
existente e armazenamento técnico candidato na migration 13. O primeiro verifica
arquivos privados e grava HTML com proveniência local, sem DB. O segundo conserva
os JSONs originais sob identidade SQL e oferece leitura exata para um consumidor
interno do renderer. A composição com o registry está em implementação candidata
na migration 14, preservando essas duas entradas e seus contratos. Nenhum desses
componentes ativa endpoint, transporte, worker ou autoridade operacional.
A especificação e as decisões ainda abertas estão em
[SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1](../../../../docs/SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md).

| Schema | O que descreve |
| --- | --- |
| `onepage-brief-payload.schema.json` | O shape privado de 11 textos e 3 booleanos derivado do inventário legado Onepage. |
| `brief-snapshot.schema.json` | O manifesto candidato recebido pela persistência nativa: contexto de projeto, contador agregado da origem, revisão Sites própria e descritores privados de bytes/materiais. |
| `brief-render-plan.schema.json` | A ligação proposta entre um snapshot exato, texto editorial selecionado separadamente e bytes esperados do renderer existente. |
| [brief-snapshot-receipt.schema.json](brief-snapshot-receipt.schema.json) | Recibo técnico de treze campos da gravação candidata, com timestamp histórico e quatro flags falsas; não certifica a aprovação da origem. |

Os schemas verificam estrutura e limites representáveis por JSON Schema
2020-12. Não verificam identidade, permissão, aprovação, hashes dos arquivos,
CAS, replay ou acesso a materiais. As anotações
`x-legacy-max-utf16-code-units-before-trim` **não são assertions de JSON Schema**:
O objeto compartilhado `BriefSnapshotInput` aplica esses limites sem confundi-los
com `maxLength` em code points. Ele e `BriefRenderPlanInput`, compartilhado por
`BriefRenderProvenance` e pela composição, recusam chaves duplicadas e formas numéricas não canônicas
e conferem os vínculos de bytes que o schema sozinho não verifica. Essas
validações puras não autenticam a origem nem estabelecem CAS ou replay durável;
tais mecanismos de persistência pertencem à API SQL candidata descrita abaixo.
O `format: date-time` do recibo precisa de um format checker para validar o
calendário em JSON Schema. A expressão regular fixa a representação UTC6; o
recorder PHP também exige round-trip exato de calendário e timestamp.

O teto técnico proposto para o arquivo do briefing rico é **150000 bytes**, para
reutilizar o leitor privado existente. Ele é distinto dos 128000 bytes do body
HTTP legado e dos limites UTF-16 de cada campo; serializações acima do teto
precisam ser recusadas, sem truncar ou reescrever o payload. O input editorial
mantém os **32768 bytes** já admitidos pelo renderer. O consumo local exige
quatro arquivos distintos: plano, manifesto do snapshot, briefing rico e input
editorial. Plano e manifesto têm, cada um, o teto de **32768 bytes**.

## Exemplos inteiramente sintéticos

| Arquivo | Função no exemplo |
| --- | --- |
| `examples/onepage-brief.synthetic.json` | Corpo rico privado, referenciado pelo manifesto. Contatos e domínio usam `.invalid`; nenhum dado é de cliente. |
| `examples/brief-snapshot.synthetic.json` | Exemplo de `sourceVersion=12` agregado e `briefRevision=1` novo, com `expectedBriefRevision=0`. A declaração `sourceState=approved` não é prova de aprovação. |
| `examples/render-input.synthetic.json` | Os seis campos já aceitos por `LocalSiteRendering`, escolhidos manualmente. Não é o corpo rico renomeado nem uma transformação automática dele. |
| `examples/rendered.synthetic.html` | Bytes reais do renderer determinístico existente aplicados somente ao input fictício acima. Continua uma demonstração inerte. |
| `examples/brief-render-plan.synthetic.json` | Liga o SHA do arquivo de snapshot, o SHA/tamanho do input editorial e o SHA/tamanho do HTML esperado. Não é recibo de execução ou registro. |
| [examples/brief-snapshot-receipt.synthetic.json](examples/brief-snapshot-receipt.synthetic.json) | Sexto exemplo: treze campos coerentes com o snapshot fictício, incluindo um timestamp inventado. Não representa execução SQL, commit ou aprovação. |

A proposta documental #517 continha três schemas e cinco exemplos. Esses
artefatos históricos foram preservados; o recibo acrescenta o quarto schema
e o sexto exemplo.

O exemplo não contém materiais: declara logo e manual indisponíveis. Não prova
upload, validação de conteúdo, autorização de download ou conferência humana.
O perfil de origem é somente Onepage; o suporte local do renderer a Landing
Page não define um briefing oficial desse produto.

Cada SHA-256 identifica os **bytes completos do respectivo arquivo**, sem
re-encode de JSON, ordenação de chaves, trim ou normalização Unicode. Os JSONs
do exemplo terminam em LF; o HTML mantém a ausência de LF final produzida pelo
renderer. Alterar espaçamento ou LF do snapshot exige atualizar
`snapshot.manifestSha256` do plano. As referências opacas de payload não são
caminhos nem URLs e não autorizam leitura.

O HTML do exemplo foi materializado com
[`LocalSiteRendering::render`](../../../../src/Production/LocalSiteRendering.php),
sem alterar seu parser, template, avisos ou digest. Isso demonstra uma ligação
técnica entre arquivos fictícios. O modo abaixo acrescenta a gravação de
proveniência local, sem estabelecer consumo de briefing oficial, idempotência
durável, aprovação editorial ou publicação.

## Armazenamento técnico candidato implementado

A [migration 13](../../../../apps/sites/database/migrations/2026_11_18_000013_add_portal_brief_snapshots.php)
e a [API SQL](../../002-brief-snapshots.sql) recebem os JSONs originais do
manifesto e do corpo. O tenant é derivado do principal de `session_user`; o
contexto deve coincidir com o intake imutável de projeto. A capacidade de
receber projeto não autoriza receber briefing. Novas identidades de runtime,
transporte e produção precisam de autorização própria; a migration cria somente
roles internas sem LOGIN e estruturas negadas por padrão.

Uma nova linha exige o CAS corrente de `briefRevision` e avanço de
`sourceVersion`; o segundo é o contador agregado da origem e pode saltar.
Replay exato conserva o recibo, os bytes e a identidade/epoch histórica,
incluindo após novo head, sob autorização corrente do chamador. Isso não
verifica a declaração `sourceState=approved` nem substitui o exportador Portal.

`PortalBriefSnapshotRecorder` devolve exatamente treze campos:
`schemaVersion`, `status`, `siteRef`, `snapshotRef`, `briefRevision`,
`sourceVersion`, `manifestSha256`, `payloadSha256`, `recordedAt`,
`authorityVerified`, `clientAccepted`, `canPublish` e `canAcceptRealOrders`.
As quatro últimas flags são falsas e `status=recorded` não afirma criação pela
chamada atual. O timestamp UTC6 é o da linha original, inclusive no replay.

O SQL valida estrutura, tipos, números lexicais, hashes e contexto. O PHP
compartilhado verifica também completude, UTF-16 e semântica de URL/e-mail/domínio;
o reader revalida esse perfil, inclusive para bytes gravados diretamente pela
API SQL. `StoredBriefRendering` usa `siteRef`, `snapshotRef`, `briefRevision`
e `manifestSha256` do plano validado para recuperar a seleção exata e produzir
HTML/proveniência em memória com o renderer existente.

Recorder e reader têm transações próprias de uma tentativa e conexões com
`ERRMODE_EXCEPTION`; o reader exige default e transação somente leitura. Uma
falha de commit ou perda de resposta não prova ausência de escrita. Não há
retry automático, transação conjunta com arquivos ou registro de artefato
por esse consumidor isolado. A #519 validou essa capacidade; a composição
descrita abaixo acrescenta a relação persistente por serviço separado.
Consulte [o manual de armazenamento e leitura](../../../../docs/PORTAL-BRIEF-SNAPSHOTS.md)
para permissões, recusas e reversão. Nenhum endpoint foi ativado.

## Composição persistente em implementação

`StoredBriefReleaseRegistration` recebe plano e editorial originais, reusa o
parser compartilhado e lê/renderiza o snapshot internamente. Não aceita um
sidecar ou `ReleaseArtifact` externo como prova. A confirmação exige um
`commitmentSha256` de domínio próprio que inclui alvo canônico, plano/editorial
originais e HTML; `planSha256` continua sendo somente o SHA do plano bruto.
Serializações ou snapshots diferentes não compartilham essa confirmação
apenas por gerarem o mesmo HTML.

A migration 14 chama a operação existente de registro e grava uma associação
atômica com FK para a seleção exata no próprio banco do writer. A relação guarda
plano/editorial originais, sem duplicar HTML ou corpo rico. Não há adoção
retroativa de artefato antigo sem associação. A API antiga e seu replay por
autor/epoch são preservados; a capacidade composta é exclusiva e alternativa.

| Projeção técnica | Campos e interpretação |
| --- | --- |
| Plano | Dez campos; consulta o snapshot, mas não reserva autorização de escrita. |
| Registro | Vinte campos, incluindo o compromisso confirmado, `created` e timestamp devolvidos pelo SQL. |
| Leitura histórica exata | Dezoito campos por site/release/`planSha256`; sem `created`, compromisso antigo, texto bruto ou login. Usa reader atual separado, sem repetir writer de epoch antigo. |

As quatro flags `authorityVerified`, `clientAccepted`, `canPublish` e
`canAcceptRealOrders` continuam falsas. SQL valida estrutura/hashes/vínculo;
PHP revalida o perfil, gera e audita HTML. A leitura termina antes da escrita,
sem atomicidade da autorização entre sessões. Falha pode seguir commit e não
autoriza retry automático. Não há CLI, HTTP, provider, transporte ou aprovação
Portal ativados. O [manual da composição](../../../../docs/STORED-BRIEF-RELEASE-PROVENANCE.md)
detalha o contrato; a execução da nova fixture PostgreSQL ainda está pendente.
O modo local abaixo permanece sem DB e sem mudanças de flags ou sidecar.

## Consumo local implementado

O mesmo comando de renderização aceita o grupo indivisível `--plan`,
`--snapshot` e `--source-brief`, junto das opções anteriores `--brief` e
`--output`. Cada opção aparece uma vez, em qualquer ordem; grupos incompletos,
opções desconhecidas e caminhos relativos são recusados.

```sh
php ops/release-candidate/render.php render \
  --brief=/privado/render-input.json \
  --output=/privado/saida/index.html \
  --plan=/privado/brief-render-plan.json \
  --snapshot=/privado/brief-snapshot.json \
  --source-brief=/privado/onepage-brief.json
```

| Opção de entrada | Arquivo | Máximo em bytes |
| --- | --- | ---: |
| `--plan` | Plano candidato | 32768 |
| `--snapshot` | Manifesto do snapshot | 32768 |
| `--source-brief` | Corpo rico de onze textos e três flags | 150000 |
| `--brief` | Input editorial de seis campos do renderer atual | 32768 |

Os caminhos são explícitos, distintos, absolutos e canônicos. Cada arquivo é
regular, privado, de link único, modo `0400` ou `0600`, sob custódia de root ou
do usuário efetivo, com ancestrais verificados e sem symlink observado. Os
exemplos versionados precisam de cópias privadas com essa custódia antes de uma
execução. Nenhuma referência do JSON autoriza resolver um caminho ou fazer uma
requisição externa.

O serviço confere igualdade de tenant/projeto e identidade/revisão do snapshot,
SHA dos bytes originais do manifesto, SHA/tamanho do corpo rico e do input
editorial, e SHA/tamanho do HTML produzido. Os `operationId` e `idempotencyKey`
de snapshot e render são de operações distintas; os valores não são comparados,
permitindo tanto igualdade quanto desigualdade entre as operações. A igualdade
`briefRevision = expectedBriefRevision + 1` é somente uma relação local, sem
consulta ao head, reserva, CAS global ou deduplicação persistente.
`sourceState = approved` não comprova aprovação ou autoridade.

O perfil implementado aceita apenas `onepage`, `materials=[]`,
`logo_unavailable=true` e `manual_unavailable=true`. Valida os catorze campos,
as condições de completude e os limites UTF-16 da origem, usando aparagem
ECMAScript apenas em uma cópia lógica para validação. Não transforma o corpo
rico nos seis campos editoriais, não reescreve os bytes comprometidos pelos
hashes e não busca materiais. O modo anterior, com apenas `--brief` e
`--output`, continua disponível para Onepage e Landing Page.

### Perfil de URLs

Para `current_site` e `contact_site` não vazios, o consumidor usa um
subconjunto explicitamente mais estreito que WHATWG `new URL()`:

- HTTP(S) absoluto, com letras ASCII no esquema; variações ASCII de caixa são aceitas.
- DNS ASCII sem IDNA, labels `xn--` ou ponto final; IPv4 decimal canônico ou IPv6 entre colchetes.
- Porta decimal opcional de um a cinco dígitos, entre `0` e `65535`.
- Sem usuário/senha, barras invertidas, controles ou espaços internos, e sem conversão de formatos alternativos de IPv4.
- Caminho/query podem conter texto não ASCII preservado; nenhuma URL é normalizada, resolvida ou acessada.

A aparagem lógica dos extremos do campo não altera o JSON ou a URL original.
Uma referência sintaticamente válida não prova existência ou titularidade do
domínio e não ativa publicação.

## Saídas e limites da evidência

O modo cria `index.html` e `render-provenance.json` no mesmo parent existente,
canônico, modo `0700` e do usuário efetivo. Os dois destinos precisam estar
ausentes. Ambos são reservados exclusivamente como `0600` antes da primeira
escrita, e os dois descritores permanecem abertos durante a conferência do par.
Os quatro inputs e seus ancestrais são revalidados antes das reservas, antes
de escrever e depois das escritas; os dois outputs passam por flush, fsync,
leitura dos bytes escritos e conferência de identidade antes de fechar.

O sidecar é JSON compacto seguido de LF, com **sete campos raiz**:
`schemaVersion=sites.local-render-provenance.candidate.v0.1`,
`status=rendered`, `planSha256`, `artifact={sha256,sizeBytes}`,
`authorityVerified=false`, `canPublish=false` e
`canAcceptRealOrders=false`. O SHA do plano identifica seus bytes completos;
preserve os quatro arquivos originais privados para conferir a cadeia.

O stdout preserva os **sete campos anteriores**:
`schema=SITES_LOCAL_RENDERING_RESULT_V1`, `status=rendered`,
`inputSha256` do JSON editorial, `artifactDigest`, `sizeBytes`,
`canPublish=false` e `canAcceptRealOrders=false`. Só é emitido após fechar
as duas saídas, sem corpo privado, referências ou caminhos.

Não há atomicidade entre os dois arquivos nem travessia atômica de todos os
ancestrais por descritor. Uma falha ou resposta perdida pode deixar reservas,
arquivos parciais ou completos; a CLI não promete rollback e não faz cleanup,
sobrescrita, reparo de permissões ou retry automático. Uma recusa capturada pelo
comando retorna exit `1`, stdout vazio e somente `LOCAL_RENDER_DENIED` no stderr;
interrupção ou resposta perdida pode deixar o resultado indisponível. A presença de um nome
de arquivo não substitui a conferência dos bytes e da custódia.

Somente o HTML é consumido pelo registro de artefato existente. O sidecar não
concede autoridade ao registry, não altera revisão editorial ou binding e não
constitui pacote de publicação. Consulte o
[manual do comando](../../../../ops/release-candidate/README.md#local-rendering-with-candidate-briefing-provenance)
e o [relatório de implementação](../../../../docs/reviews/SITES-LOCAL-RENDER-PROVENANCE-2026-11-18.md).

Evidência local: **109 checks do serviço**, **137 do renderer existente**,
**80 da nova CLI** e **87 da CLI anterior**, todos aprovados. Os testes de
arquivos usam diretórios temporários privados e o comando real; não atribuem
autoridade oficial do Portal, execução PostgreSQL ou implantação a esse
resultado.

O armazenamento foi validado na **#519**, HEAD
`cdfad4464d65355962d487396911fe06092dfde5`, com **15/15 checks SUCCESS**:
**241 checks de briefing**, **923 registry/62 de registro** e Laravel
**251/3140/27**, além de **251/3152/21** no opt-in, em PHP 8.3/8.4.
As provas de rollback e cleanup estão no
[manual de snapshots](../../../../docs/PORTAL-BRIEF-SNAPSHOTS.md).

Na composição seguinte, o grupo PHP focado passou com **39 testes/1172
assertions**, incluindo **28 novos e onze Unit existentes**, sem representar
toda a suíte. Regressões **109** de proveniência e **271** do registro anterior
PASS. A nova prova PostgreSQL/CI permanece pendente; esses resultados PHP não
demonstram persistência da nova relação. Nenhum novo schema ou exemplo foi
acrescentado a este diretório pela composição.
