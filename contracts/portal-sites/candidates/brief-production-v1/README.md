# Briefing Onepage e consumo local — proposta v0.1

Estes arquivos são uma **proposta revisável**, com consumo local limitado já
implementado no `render.php` existente. Esse modo verifica arquivos privados e
grava HTML com proveniência local; não acrescenta endpoint, worker ou autoridade
operacional. A especificação e as decisões ainda abertas estão em
[SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1](../../../../docs/SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md).

| Schema | O que descreve |
| --- | --- |
| `onepage-brief-payload.schema.json` | O shape privado de 11 textos e 3 booleanos derivado do inventário legado Onepage. |
| `brief-snapshot.schema.json` | Uma revisão imutável proposta, com referências de projeto, revisão agregada da origem, CAS próprio e descritores privados de bytes/materiais. |
| `brief-render-plan.schema.json` | A ligação proposta entre um snapshot exato, texto editorial selecionado separadamente e bytes esperados do renderer existente. |

Os schemas verificam estrutura e limites representáveis por JSON Schema
2020-12. Não verificam identidade, permissão, aprovação, hashes dos arquivos,
CAS, replay ou acesso a materiais. As anotações
`x-legacy-max-utf16-code-units-before-trim` **não são assertions de JSON Schema**:
O consumidor local `BriefRenderProvenance` aplica esses limites sem confundi-los
com `maxLength` em code points. Ele também recusa chaves duplicadas e formas
numéricas não canônicas e confere os vínculos de arquivos que o schema sozinho
não verifica. Isso não autentica a origem nem estabelece CAS ou replay durável.

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

O exemplo foi materializado com
[`LocalSiteRendering::render`](../../../../src/Production/LocalSiteRendering.php),
sem alterar seu parser, template, avisos ou digest. Isso demonstra uma ligação
técnica entre arquivos fictícios. O modo abaixo acrescenta a gravação de
proveniência local, sem estabelecer consumo de briefing oficial, idempotência
durável, aprovação editorial ou publicação.

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
