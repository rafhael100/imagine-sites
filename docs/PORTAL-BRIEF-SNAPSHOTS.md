# Snapshots candidatos de briefing: recebimento, leitura exata e renderização

**Estado em 18/11/2026:** capacidade candidata validada na
[#519](https://github.com/rafhael100/imagine-sites/pull/519), HEAD
`cdfad4464d65355962d487396911fe06092dfde5`, com **15/15 checks SUCCESS**.
A branch `feature/sites-native-brief-snapshots-2026-11-18` partiu da #518 /
`907e2883393b776eebcba59fafb672aa34c99bf6`. Não há ativação operacional.

A [composição posterior com o registry](STORED-BRIEF-RELEASE-PROVENANCE.md)
está em implementação candidata na migration 14, em branch separada. Ela
reutiliza o armazenamento e o renderer deste manual; a prova da #519 não
substitui a execução dos novos ensaios SQL de associação.

## Resultado

O Sites passa a ter um receptor persistente de bytes candidatos do briefing
Onepage, ligado ao projeto criado pelo intake nativo. Uma leitura autorizada
recupera a revisão exata; o consumidor usa esses bytes no renderer existente,
com a entrada editorial selecionada separadamente. Isso conserva a identidade
do insumo mesmo depois que outra revisão é recebida.

A capacidade SQL autoriza armazenamento e consulta técnica. Não autentica a
aprovação declarada pelo arquivo, não verifica pagamento ou aceite e não concede
permissão de publicação. O produtor Portal e o transporte entre serviços
continuam exigindo integração própria. Nenhum endpoint, provider, worker ou
comando de shell novo é registrado por esta entrega.

## Componentes e uso

| Componente | Responsabilidade |
| --- | --- |
| [`BriefSnapshotInput`](../src/Production/BriefSnapshotInput.php) | Valida o manifesto e o corpo rico; mantém seus JSONs originais e metadados escalares em objeto imutável. |
| [`BriefCandidateJson`](../src/Production/BriefCandidateJson.php) | Compartilha tipos, chaves exatas, duplicatas decodificadas, números lexicais e descritores. |
| [`BriefRenderPlanInput`](../src/Production/BriefRenderPlanInput.php) | Na composição posterior, extrai o parser único do plano; conserva bytes/seletores e valida a entrada editorial e o HTML esperado, sem autenticar a origem. |
| [`PortalBriefSnapshotRecorder`](../apps/sites/app/Integration/PortalBriefSnapshotRecorder.php) | Grava pela API SQL e valida o recibo antes de permitir o commit e a devolução do resultado. |
| [`StoredBriefSnapshotReader`](../apps/sites/app/Integration/StoredBriefSnapshotReader.php) | Lê por quatro seletores exatos, revalida o perfil completo e confere novamente a identidade dos bytes. |
| [`StoredBriefRendering`](../apps/sites/app/Integration/StoredBriefRendering.php) | Usa o reader para obter a fonte armazenada e chama `BriefRenderProvenance::render`. |
| [`BriefSnapshotTransaction`](../apps/sites/app/Integration/BriefSnapshotTransaction.php) | Mantém uma transação própria, uma tentativa, a mesma PDO e o runtime esperado; não permite réplica ou resultado antes do commit. |
| [`002-brief-snapshots.sql`](../contracts/portal-sites/002-brief-snapshots.sql) e [migration 13](../apps/sites/database/migrations/2026_11_18_000013_add_portal_brief_snapshots.php) | Persistência, capacidade corrente, isolamento, imutabilidade, CAS e replay exato. |

O chamador fornece conexões já configuradas por código confiável. A conexão de
recebimento e a de consulta são distintas; a segunda já precisa ter
`default_transaction_read_only=on`. O código não ativa esse modo ou concede
privilégios automaticamente:

```php
$input = BriefSnapshotInput::fromJson($snapshotJson, $sourceJson);
$receipt = (new PortalBriefSnapshotRecorder($receiverConnection))->record($input);

$reader = new StoredBriefSnapshotReader($readerConnection);
$result = (new StoredBriefRendering($reader))->render($planJson, $editorialJson);
```

Esse exemplo representa chamadas internas, sem configurar conexão, credencial
ou transporte. O resultado da renderização contém `artifact` em memória e a
string `provenance` já definida na #518. Não escreve arquivos, registra o
artefato, muda o ciclo editorial ou cria associação/publicação.

A seleção do plano é validada pelo mesmo código de `BriefRenderProvenance`.
O reader recebe exatamente `siteRef`, `snapshotRef`, `briefRevision` e
`manifestSha256`. Não consulta uma lista nem troca silenciosamente pela última
revisão. O renderer continua consumindo os seis campos editoriais, sem derivá-los
automaticamente do corpo rico ou alterar seus avisos de demonstração.

## Identidade, tabelas e permissões

A migration cria duas tabelas com ENABLE e FORCE RLS:

- `portal_brief_principals`: login SQL, tenant, dever `receiver` ou `reader`,
  atividade, epoch, expiração e revogação. `active` começa falso. Uma alteração
  exige epoch maior; login, tenant, dever e criação não mudam. Um principal
  revogado não pode ser reativado.
- `portal_brief_snapshots`: contexto do intake, revisão, versão da origem,
  operação/chave, JSONs originais, hashes calculados, identidade/epoch do
  recebimento e timestamp. A história é append-only, com recusa de UPDATE,
  DELETE e TRUNCATE.

As roles internas `sites_core_brief_owner`, `sites_core_brief_receiver` e
`sites_core_brief_reader` não têm LOGIN, privilégios administrativos ou
memberships. O runtime pode executar somente a API de seu dever, sem leitura
ou escrita direta em tabelas. Helpers não têm EXECUTE público. A migration não
cria login, principal ativo ou grant operacional; os grants dos ensaios ficam
em bancos inteiramente descartáveis.

O guard confere runtime, permissões de tabela/coluna/função, RLS, proprietários,
policies e triggers. Os triggers esperados não podem ter `WHEN`, seleção de
colunas, argumentos ou adiamento. As permissões dos atores anteriores e suas
policies permanecem iguais. Somente o novo definer de recebimento ganha a
leitura do intake nativo por uma policy específica.

O tenant vem do principal associado a `session_user`. O manifesto precisa
coincidir com o recibo imutável `portal_project_intakes` desse tenant/site,
incluindo organização Portal, pedido, contrato, revisão contratual e produto.
Um projeto apenas semeado no banco, sem esse recibo, não satisfaz o vínculo.
Esse contexto é o do recebimento registrado; não representa uma leitura atual
de pagamento ou de elegibilidade comercial.

## Validação em duas camadas

O contrato aceita Onepage sem materiais, com os dois indicadores de
indisponibilidade verdadeiros. Mantém os limites de 32768 bytes para o manifesto
e 150000 para o corpo, sem reserializar JSON, normalizar Unicode ou retirar LF.

| Camada | Garantia própria |
| --- | --- |
| API SQL | JSON objeto com chaves únicas, shapes/tipos, números lexicais, tamanhos/hashes calculados, contexto nativo, materiais vazios, flags esperadas e revisão persistente. |
| Objeto PHP compartilhado | Também verifica completude do corpo rico, limites UTF-16 antes do trim lógico ECMAScript e o perfil explícito de URL/e-mail/domínio. |
| Leitura e renderização PHP | Reexecutam a validação completa dos bytes retornados, conferem os quatro seletores e validam a entrada editorial/HTML esperado pelo renderer existente. |

O predicado SQL de chaves únicas usa a operação documentada no
[PostgreSQL 18](https://www.postgresql.org/docs/18/functions-json.html#FUNCTIONS-SQLJSON-TESTING).
O `jsonb` auxiliar serve para inspeção; as colunas persistidas são `text` com
os bytes UTF-8 originais. O SQL conserva também o valor `json` original ao
conferir a representação lexical de inteiros, sem aceitar uma normalização
numérica como substituto.

**Uma gravação SQL isolada não certifica o perfil semântico rico.** Uma chamada
direta autorizada pode armazenar um corpo estruturalmente válido que a camada
PHP recusaria. O recibo continua sendo apenas técnico; o reader e o consumidor
de renderização rejeitam esse corpo ao revalidá-lo. A fixture possui um caso
separado para comprovar essa fronteira, sem atribuir ao SQL uma garantia que
só foi implementada em PHP.

O perfil de URLs permanece o subconjunto conservador documentado no
[contrato candidato](SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md): HTTP(S)
em ASCII, DNS ASCII sem IDNA/ponto final, IPv4 canônico ou IPv6 entre colchetes,
porta decimal limitada, sem userinfo, barras invertidas, controles ou
normalizações WHATWG. Nenhuma referência é acessada pela rede.

## CAS, replay e autorização corrente

O receiver confere e trava o principal corrente, resolve o contexto nativo e
serializa a decisão por tenant/site. Procura uma operação anterior antes de
comparar o head com `expectedBriefRevision`.

O replay precisa coincidir em operação, chave, snapshot, versão da origem e
ambos os JSONs originais. Retorna a linha histórica, com o timestamp original,
mesmo que o head tenha avançado. Colisão parcial ou mudança de conteúdo gera
conflito. Novas chaves não permitem reutilizar uma versão de origem já recebida.

Para uma nova linha, a revisão avança exatamente uma unidade e `sourceVersion`
precisa ser maior que a última. A versão agregada da origem pode saltar; um
corpo idêntico pode compor nova revisão se essa versão avançar. Duas chamadas
idênticas concorrentes devem obter um único recibo persistido. Duas chamadas
diferentes disputando o mesmo CAS não podem criar duas revisões.

O autor/epoch histórico é proveniência. Outro receiver atualmente autorizado
no mesmo escopo pode recuperar o recibo exato, sem reescrever a autoria.
A revogação do receiver anterior não apaga a história ou invalida o acesso de
um reader independente válido. A revogação do principal que faz a chamada
impede seu novo acesso, inclusive replay.

O receiver mantém o lock do principal até commit e revalida expiração/epoch
depois da operação. A consulta é somente leitura, sem row lock; sua função
VOLATILE em READ COMMITTED confere o principal antes e depois da leitura. Essa
escolha usa o comportamento de snapshots descrito no
[manual de volatilidade do PostgreSQL](https://www.postgresql.org/docs/18/xfunc-volatility.html).
A conferência autoriza a leitura naquele ponto; não cria uma permissão de
geração/publicação com validade futura.

O exportador Portal ainda precisa fixar um conjunto consistente de bytes e
versão antes do primeiro envio. O receptor não transforma o contador mutável
legado numa prova de exportação ou aprovação oficial.

## Recibo, erros e falhas

O recorder devolve exatamente treze campos, descritos no
[schema candidato](../contracts/portal-sites/candidates/brief-production-v1/brief-snapshot-receipt.schema.json):

| Campo | Conteúdo |
| --- | --- |
| `schemaVersion` | `sites.brief-snapshot.receipt.candidate.v0.1` |
| `status` | `recorded`; não afirma que esta chamada criou a linha |
| `siteRef`, `snapshotRef` | Identidades da linha selecionada |
| `briefRevision`, `sourceVersion` | Inteiros das duas sequências distintas |
| `manifestSha256`, `payloadSha256` | Hashes calculados dos JSONs originais |
| `recordedAt` | Timestamp original, UTC com seis decimais |
| `authorityVerified`, `clientAccepted`, `canPublish`, `canAcceptRealOrders` | Sempre `false` |

O exemplo de recibo é inteiramente fictício; seu timestamp não representa
uma execução de banco. A resposta não inclui corpo rico, credencial ou caminho.

Recorder e reader usam transação própria e uma única tentativa. Recusam
transação externa, outra PDO, réplica e runtime incompatível. A PDO deve estar
em `ERRMODE_EXCEPTION` antes, durante e depois da operação; o helper não
conserta configurações. O reader exige default e transação somente leitura;
o receiver exige ambos em modo de escrita.

O recorder mapeia SQLSTATE 23505 para `BRIEF_SNAPSHOT_CONFLICT`; outras recusas
usam `BRIEF_SNAPSHOT_DENIED`. O reader usa a mesma recusa para ausência, acesso
negado ou seleção divergente. `StoredBriefRendering` mantém
`LOCAL_RENDER_DENIED`. Nenhuma fronteira conserva exceção SQL anterior.

Só há resultado após commit e conferência da conexão. Falha ou perda de resposta
pode ser inconclusiva; não é prova de ausência de escrita. A recuperação usa
a solicitação original ou a seleção exata sob autorização corrente, sem
inventar nova operação/revisão. Banco e arquivos continuam sendo recursos
separados; o consumidor não promete uma transação conjunta.

## Provas e reversão

Os testes PHP de parsing, recibo, seleção e fronteira transacional usam PDO
desconectada. A regressão da #518 conserva seus 109 checks. Esses resultados
locais são distintos da prova PostgreSQL final da #519 abaixo.

| Prova da #519 no HEAD `cdfad4464d65355962d487396911fe06092dfde5` | Resultado confirmado |
| --- | --- |
| [F383 — run 44336308019](https://github.com/rafhael100/imagine-sites/actions/runs/44336308019) | PHP 8.3/8.4: **241 checks de briefing**, **65 de intake**, recorder **18 testes/84 assertions**, leitura/editorial **3/12** e **2/12**; rollback das **13 migrations**, ausência dos dois schemas próprios e cleanup. |
| [Registry — run 44336307622](https://github.com/rafhael100/imagine-sites/actions/runs/44336307622) | PHP 8.3/8.4: **923 checks totais**, **62 de registro**, quatro antigos/quatro novos de renderização e um do schema; clusters próprios inativos. |
| [Laravel — run 44336308278](https://github.com/rafhael100/imagine-sites/actions/runs/44336308278) | PHP 8.3/8.4: padrão **251/3140/27**, opt-in sintético **251/3152/21**. |
| [Containers — run 44336308134](https://github.com/rafhael100/imagine-sites/actions/runs/44336308134) | Ensaio e cleanup concluídos. |

A [fixture nativa](../tests/portal-brief-snapshot-pg-contract.php) executou
intake, recorder, reader e renderer reais em PostgreSQL 18 descartável:
reconexão, história após novo head, isolamento, conflito, concorrência, resposta
descartada, revogação e diferença entre shape SQL e perfil PHP. O
[workflow F383](../.github/workflows/sites-f1-383-operational-schema.yml) dessa
entrega conferiu inicialmente 14 tabelas/42 policies e preservou as provas
anteriores antes do rollback completo.

O [workflow de compatibilidade da #519](../.github/workflows/sites-native-brief-snapshots.yml)
aplicou a migration 13 explicitamente no runner registry. Seu check adicional
de presença/RLS/ausência de principal ou snapshot manteve as 922 verificações
anteriores e resultou nos 923 confirmados acima. Esses números são históricos
da base, não resultados atribuídos à migration 14.

O down confere a identidade dos objetos e remove somente as estruturas novas,
sem CASCADE, remoção de projetos ou restauração de grants. **Ele apaga os
snapshots privados dessa migration.** A prova com dados sintéticos não autoriza
rollback operacional, que exige política própria de retenção, backup e revisão.
Reapply recria estruturas vazias e mantém capacidades desativadas.

Não foram alterados gates, staging, infraestrutura, serviços existentes ou
fontes oficiais. `StoredBriefRendering` continua retornando HTML/proveniência
em memória; esse resultado isolado não registra o artefato.

## Composição posterior em implementação candidata

`StoredBriefReleaseRegistration` reutiliza essa leitura e renderização
internamente. O plano tem dez campos e o resultado de registro vinte;
`commitmentSha256` vincula alvo canônico, plano/editorial originais e HTML,
enquanto `planSha256` identifica somente o plano bruto. Nenhum sidecar ou
artefato externo é aceito como prova.

A migration 14 chama `record_release_artifact` existente e grava a associação
na mesma transação SQL, sem adotar retroativamente artefato antigo sem relação.
As identidades de snapshot e artefato são resolvidas no próprio banco do writer.
SQL confere estrutura/hashes/FK; PHP revalida o perfil, gera e audita HTML.
O reader do snapshot termina antes dessa escrita: a relação atômica no writer
não significa autorização atômica entre sessões.

Uma consulta histórica exata separada retorna dezoito campos por site/release/
hash do plano. Usa reader atual e não exige repetir writer de epoch antigo;
não devolve `created`, compromisso antigo, JSON bruto ou login privado.
API e semântica do registro antigo permanecem preservadas, com capacidades
alternativas exclusivas. Erro ou resposta perdida pode seguir commit, sem
retry automático ou atribuição de autoridade oficial.

O grupo PHP focado da composição passou com **39 testes/1172 assertions**,
sendo **28 novos e onze Unit existentes**; regressões **109/271 PASS**, lint e
diffcheck. Esse grupo não é a suíte geral. A nova prova PostgreSQL/CI ainda
está pendente, sem número de PR presumido. O [manual da composição](STORED-BRIEF-RELEASE-PROVENANCE.md)
detalha projeções e recuperação. Produtor Portal, transporte, materiais reais e
uso operacional continuam exigindo integração e evidência próprias.
