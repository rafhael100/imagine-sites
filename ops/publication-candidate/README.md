# Candidatos privados do diário de publicação

A CLI `inspect.php` consulta uma operação conhecida do diário técnico já persistido. É útil para recuperar seu último estado após perda de processo ou resposta. A leitura não solicita tentativa, não reconcilia expiração, não envia conteúdo e não verifica publicação no destino. A reconciliação explícita possui outro comando, manifesto, credencial e autorização, descritos ao final deste manual.

## Comando

```sh
php ops/publication-candidate/inspect.php --help
php ops/publication-candidate/inspect.php inspect
```

`inspect` é a única operação de `inspect.php`. Não há argumento para caminho, listagem, filtro, saída em arquivo, retry ou mutação. O comando não inicializa Laravel nem registra rota ou worker.

O ambiente candidato é `APP_ENV=staging`, com banco `sites_staging` e sem flags de CI. O modo de teste exige conjuntamente `GITHUB_ACTIONS=true`, `APP_ENV=testing` e `SITES_PUBLICATION_INSPECTION_CI=1`, além do alvo sintético exato do runner. Produção, ambiente indefinido e misturas entre os dois modos são recusados.

## Entrada sob custódia

| Montagem fixa | Conteúdo |
| --- | --- |
| `/run/secrets/publication_inspection` | Manifesto de consulta, até16KiB |
| `/run/secrets/sites_publication_reader` | Senha do leitor existente,16–4096 bytes |
| `/run/secrets/sites_publication_ca` | CA do PostgreSQL, até64KiB, com SHA-256 pinado |

O leitor de arquivos existente exige arquivo regular, caminho absoluto/canônico, um único link, modo0400 ou0600 e dono root ou o UID que invoca a CLI. Links simbólicos, inclusive em diretórios ancestrais, são recusados. Identidade, tamanho e metadados são comparados antes/durante/depois da leitura. Manifesto e CA são relidos e comparados após a consulta, antes de produzir stdout.

`manifest.example.json` contém somente placeholders inválidos e não é instalável. O formato tem exatamente:

- `schema`: `SITES_PUBLICATION_INSPECTION_V1`.
- `target`: hostname, porta, banco, OID observado do banco, login, endereço observado do servidor e SHA-256 da CA.
- `operationRef`: UUID da operação conhecida.

Não são aceitos campos adicionais, duplicados, coerções numéricas, credenciais, caminhos arbitrários, empresa, referências de pedido ou flags de autoridade. A referência da operação seleciona um registro; a empresa e o acesso vêm do principal SQL autenticado.

## Conexão e acesso

A CLI abre uma conexão PDO nova e não persistente, com `sslmode=verify-full`, hostname conferido e CA pinada. Exige PostgreSQL18, login efetivo igual ao login da sessão, isolamento read committed, UTF-8, TLS1.2/1.3 com pelo menos128 bits, banco/OID/endereço/porta exatos. A identidade observada não é apresentada como prova de `system_identifier`; o leitor não recebe permissão nova para consultá-lo.

A sessão usa `search_path=pg_catalog`, transações somente leitura, conexão limitada a5s, statement a10s e lock a3s. O backend não modifica configurações: verifica essas condições antes e depois de chamar `PgPublicationJournal::read()`.

O catálogo deve demonstrar USAGE do namespace e EXECUTE de `read_operation(uuid)` sem grant option, sem EXECUTE das demais funções de `sites_publication` ou `sites_core`. Isso confere capacidades efetivas, inclusive privilégios herdados/PUBLIC; não consulta o rótulo privado de duty. A função SQL existente verifica principal, tenant, época, prazo, revogação, ownership, RLS e ACLs. Um login com capacidades de escrita não serve para esse comando.

Nenhum login, grant ou binding é criado ou renovado pela CLI. O acesso precisa existir sob seu procedimento próprio. Os grants e estados alterados no ensaio pertencem exclusivamente ao banco sintético descartável.

## Saída e interpretação

Sucesso retorna uma linha JSON com `schema=SITES_PUBLICATION_INSPECTION_RESULT_V1` e `status=inspected`. A lista de campos é fechada:

| Campos | Significado |
| --- | --- |
| `technicalStatus` | Estado técnico persistido, descrito abaixo |
| `revision`, `attemptNumber` | Revisão do diário e contador de tentativa |
| `editorialRevision`, `bindingRevision` | Revisões históricas associadas à operação |
| `artifactDigest`, `receiptDigest` | Hash do artefato e hash do recibo relatado, quando existente |
| `leaseExpiresAt`, `createdAt`, `updatedAt` | Datas persistidas em UTC; prazo pode ser null |
| `canPublish`, `publicationVerified` | Ambos obrigatoriamente false |

Além dos doze campos acima, `schema` e `status` completam os14 campos. A saída não contém tenant, operação/pedido/contrato/release/aceite, idempotência, referência do provider, HTML, briefing, senha ou token do lease.

| Estado | O que o registro comprova |
| --- | --- |
| `requested` | Solicitação técnica registrada |
| `dispatched` | Tentativa registrada; não comprova que houve envio |
| `acknowledged` | ACK relatado; não comprova publicação observada |
| `inconclusive` | Resultado inconclusivo persistido |

O comando conserva o estado gravado mesmo quando o prazo passou. Um `dispatched` vencido continua sendo retornado como `dispatched`; ler não chama `reconcileExpired`. A consulta histórica pode continuar após revogação do vínculo de release, desde que o próprio leitor permaneça autorizado. Isso não valida o vínculo corrente nem autoriza nova tentativa.

Falha retorna exit1, stdout vazio e somente `PUBLICATION_INSPECTION_DENIED` em stderr. Registro inexistente e registro de outra empresa têm a mesma recusa. Detalhes PDO, parâmetros SQL, nomes privados, caminhos e mensagens de exceção não são expostos.

## Verificação e limite operacional

Os testes offline de manifesto/argumentos não conectam ao banco. O ensaio real usa a CLI contra PostgreSQL18/TLS descartável, compara snapshots e verifica estados históricos, revogação, tenant, privilégios, identidade, CA e custódia. Isso não comprova disponibilidade operacional nem instala o comando no VPS.

Os dez gates continuam BLOCKED. Contrato, pagamento e aceite do cliente permanecem no Portal; o publisher, a observação independente do destino e qualquer recuperação mutável exigem suas próprias integrações e aprovações. Este candidato não altera VPS, porta18090, DNS, Cloudflare ou dados reais.

## Reconciliação explícita de uma operação conhecida

O comando separado `reconcile.php` solicita uma única chamada a `sites_publication.reconcile_expired`, pela API `PgPublicationJournal::reconcileExpired` já existente. Ele permite planejar uma solicitação e, após confirmação explícita, registrar um resultado técnico inconclusivo quando as condições atuais do SQL permitirem. O comando não cria solicitação, tentativa, ACK, lease, migration, papel ou grant; não envia conteúdo nem verifica o destino externo. Carrega somente o autoloader Composer, sem inicializar a aplicação, registrar rota ou iniciar worker.

### Manifesto, ambiente e custódia

| Montagem fixa | Conteúdo |
| --- | --- |
| `/run/secrets/publication_reconciliation` | JSON `SITES_PUBLICATION_RECONCILIATION_V1`, até 16384 bytes |
| `/run/secrets/sites_publication_reconciler` | Senha do reconciliador existente, de 16 a 4096 bytes |
| `/run/secrets/sites_publication_reconciliation_ca` | CA PEM do PostgreSQL, até 65536 bytes, com SHA-256 correspondente ao alvo |

O manifesto contém exatamente `schema`, `target`, `operationRef` e `expectedRevision`. O alvo contém exatamente `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress` e `caSha256`. A operação é um UUID conhecido, e a revisão esperada é um inteiro de 1 a 9223372036854775806 em PHP de 64 bits. Strings numéricas, campos extras e chaves duplicadas são recusados. `reconciliation.example.json` possui placeholders inválidos e não pode ser executado sem preenchimento.

A referência e a revisão selecionam a operação; não estabelecem tenant, duty, prazo vencido ou autoridade. Não há campo para HTML, relógio do cliente, duração de lease, token, recibo ou flags de publicação. O comando não aceita caminhos, credenciais, revisão ou referência alternativos pela linha de comando.

Os três arquivos seguem `SiteReleasePreparationFiles::read`: caminhos canônicos, arquivos regulares com um único link, sem links simbólicos inclusive nos ancestrais, modos `0400` ou `0600` e dono root ou UID efetivo da CLI. Precisam ser legíveis por esse usuário. O wrapper registra bytes, SHA-256 e identidade, e relê os três arquivos após conexão e planejamento. Qualquer mudança observada em bytes, digest, inode, proprietário, modo, número de links, tamanho ou metadados de modificação impede a chamada mutável. O tempo de acesso é excluído porque a leitura pode alterá-lo. Nenhum input é reparado, reescrito ou tem suas permissões alteradas pelo comando.

Staging exige `APP_ENV=staging`, sem GitHub Actions nem opt-in de reconciliação de CI, com banco `sites_staging`. O target precisa vir do inventário revisado do ambiente e descrever um endpoint direto, cuja porta, endereço, OID e login correspondam aos valores observados pelo servidor.

A conexão PDO é nova e não persistente, com `sslmode=verify-full`, CA fixa, `gssencmode=disable` e timeout de conexão de 5 segundos. A sessão começa somente leitura, usa `pg_catalog`, UTF-8, read committed, statement timeout de 10 segundos e lock timeout de 3 segundos. O serviço verifica PostgreSQL 18, TLS, identidade do alvo, USAGE de `sites_publication` e EXECUTE exatamente de `read_operation(uuid)` e `reconcile_expired(uuid,bigint)`, sem grant option nem EXECUTE das demais APIs de core ou diário. Essa conferência de capabilities usa catálogos; o plano não chama a API de leitura. A função SQL continua responsável pelo principal corrente, tenant, duty, epoch, expiração e revogação; o nome do login não substitui essas verificações.

### Plano somente de seletores e runtime

```sh
php ops/publication-candidate/reconcile.php --help
php ops/publication-candidate/reconcile.php plan
```

`--help` funciona sem autoload, ambiente, arquivos privados ou banco. `plan` não aceita opções e usa os três inputs fixos. Seu hash vincula o manifesto canônico inteiro, incluindo alvo, operação e revisão esperada. O planejamento não consulta a operação, não lê um snapshot privado do diário, não verifica o vencimento do lease e não reserva uma revisão. Uma resposta de plano válida não comprova que a operação existe ou que a reconciliação será permitida.

O plano retorna uma única linha JSON, com exatamente seis campos:

| Campo | Valor |
| --- | --- |
| `schema` | `SITES_PUBLICATION_RECONCILIATION_PLAN_V1` |
| `status` | `planned` |
| `planSha256` | SHA-256 em hexadecimal minúsculo do compromisso canônico |
| `expectedRevision` | Revisão fornecida no manifesto, sem consulta ao head |
| `canPublish` | `false` |
| `publicationVerified` | `false` |

### Aplicação explícita e resultado

Depois de revisar o plano concreto, a execução exige seu hash e a autorização exclusiva:

```sh
php ops/publication-candidate/reconcile.php apply \
  --plan-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_PLAN_SHA256 \
  --authorize-reconcile=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED
```

As duas opções são obrigatórias, aparecem uma única vez e podem estar em qualquer ordem. Flags dos candidatos de registro, edição, associação ou revogação não autorizam esta ação. O wrapper recalcula o plano na nova sessão somente leitura, compara o hash e relê a custódia dos três inputs. Só então habilita escrita nessa sessão e chama `apply` uma vez. O serviço revalida plano e runtime antes de invocar a API existente.

Quando houver uma nova transição permitida, o SQL usa seu próprio relógio e a revisão corrente para conferir o estado `dispatched` e o prazo vencido antes de acrescentar `lease_expired`. A transição preserva os dados da tentativa e do lease; não reinicia prazo nem cria outra tentativa. O candidato não fornece um relógio alternativo nem modifica essas precondições.

Sucesso retorna uma única linha JSON com exatamente 16 campos: os seis campos do plano, com `schema=SITES_PUBLICATION_RECONCILIATION_RESULT_V1` e `status=reconciled`, acrescidos dos dez campos técnicos abaixo.

| Campo técnico | Significado |
| --- | --- |
| `technicalStatus` | Sempre `inconclusive` |
| `revision` | Revisão retornada pela função SQL |
| `attemptNumber` | Contador persistido da tentativa |
| `editorialRevision` | Revisão editorial histórica associada à operação |
| `bindingRevision` | Revisão histórica da associação de release |
| `artifactDigest` | Digest do artefato histórico |
| `receiptDigest` | Digest de um recibo relatado, quando existente; pode ser null |
| `leaseExpiresAt` | Prazo persistido do lease |
| `createdAt` | Data persistida de criação, em UTC |
| `updatedAt` | Data persistida do resultado retornado, em UTC |

O status externo `reconciled` indica que a chamada retornou um resultado válido segundo a API; não transforma `inconclusive` em publicação confirmada. `canPublish` e `publicationVerified` permanecem falsos. A saída não contém tenant, referência da operação, pedido, contrato, release, provider, idempotência, HTML, briefing, credencial ou token do lease.

### Repetição, ACK tardio e resposta perdida

A função existente pode retornar um evento que já está `inconclusive`, quando a revisão esperada é compatível com sua regra de repetição. Isso pode incluir um ACK tardio e seu `receiptDigest`. Portanto, um sucesso não prova que houve inserção de novo evento, que nenhum ACK foi recebido ou que o ator/epoch do evento original é igual ao reconciliador atual. O SQL revalida o reconciliador corrente; não exige essa igualdade com a autoria histórica para reconhecer o estado já inconclusivo.

O comando não acrescenta `created` ou `replayed` e não deduz esses fatos pela diferença entre revisões. Um recibo relatado não comprova publicação observada no destino. A reconciliação também não renova autorização, restaura associação de release, altera aceite do cliente, envia conteúdo ou concede permissão para uma nova tentativa.

Falhas retornam exit 1, stdout vazio e somente `PUBLICATION_RECONCILIATION_DENIED` em stderr. Diagnósticos PDO, parâmetros, caminhos, identidades e cadeias de exceção são omitidos. Uma falha durante ou após a chamada SQL pode ocorrer depois de um commit; ela não comprova rollback nem ausência de ACK. Não há retry, avanço automático de revisão ou reset do lease.

Uma resposta perdida exige apuração antes de outra decisão. A CLI de inspeção, com seu próprio leitor autorizado, pode consultar o último estado técnico de uma operação conhecida; essa consulta não verifica publicação externa nem autoriza repetir a reconciliação. Qualquer nova aplicação continua exigindo uma decisão explícita e um plano revisado com os seletores adequados. Não deduza o resultado persistente apenas pelo exit status ou pelo hash do plano.

### Limite do CI de reconciliação

O laboratório exige conjuntamente `GITHUB_ACTIONS=true`, `APP_ENV=testing` e `SITES_PUBLICATION_RECONCILIATION_CI=1`. O alvo é fixado em `localhost`, porta `55449`, banco `postgres`, endereço observado `127.0.0.1` e login `pj_a_reconciler`. O OID real, a CA, a custódia, o hash e a autorização explícita continuam obrigatórios. O pin identifica o ator sintético; o SQL decide sua autoridade corrente.

Os testes offline da CLI exercitam help e recusas de argumentos/ambiente com autoload e arquivos privados inacessíveis. Eles não fabricam sucesso SQL. As condições de prazo, estado, concorrência, repetição, ACK tardio e principal precisam ser exercitadas no PostgreSQL/TLS descartável do CI. Este comando não instala nem executa reconciliação no staging, não altera o contrato SQL e não habilita publicação operacional.

## Solicitação explícita com recibo privado

O comando separado `request.php` registra uma solicitação técnica pela API existente `sites_publication.request_operation`, sem iniciar tentativa, lease, ACK ou envio ao provedor. Ele completa a entrada do diário e guarda a referência retornada em um recibo privado, para permitir uma consulta posterior com um leitor autorizado. Não inicializa Laravel, cria rota ou worker, modifica migrations/grants, concede aceite comercial nem publica um site.

### Manifesto e caminhos fixos

| Caminho fixo | Uso |
| --- | --- |
| `/run/secrets/publication_request` | Manifesto `SITES_PUBLICATION_REQUEST_V1`, de 1 a 16384 bytes |
| `/run/secrets/sites_publication_request_writer` | Senha do escritor existente com capability exclusiva de solicitação, de 16 a 4096 bytes |
| `/run/secrets/sites_publication_request_ca` | CA PEM do PostgreSQL, até 65536 bytes, correspondente a `target.caSha256` |
| `/var/lib/imagine-sites/publication-requests` | Diretório de recibos já existente, canônico, modo `0700`, pertencente ao UID efetivo da CLI |

O manifesto contém exatamente oito campos:

| Campo | Regra |
| --- | --- |
| `schema` | `SITES_PUBLICATION_REQUEST_V1` |
| `target` | Os sete campos `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress` e `caSha256` |
| `siteRef` | UUID do projeto existente |
| `releaseRef` | Referência opaca de release |
| `expectedBindingRevision` | Inteiro de 1 a 9223372036854775807 em PHP de 64 bits |
| `portalAcceptanceRef` | Referência opaca informada para o registro técnico |
| `idempotencyKey` | Chave opaca de idempotência da solicitação |
| `receiptRef` | Nome de 1–64 caracteres ASCII alfanuméricos, `_` ou `-`, começando com letra ou número |

As três referências opacas usam a regra existente: 1–128 caracteres ASCII, primeiro alfanumérico, demais alfanuméricos ou `.`, `_`, `:`, `-`. O arquivo de saída será exatamente `<receiptRef>.json` no diretório fixo. O manifesto não aceita caminho alternativo, tenant, HTML, token, duração de lease ou parâmetros de provedor. Chaves duplicadas, extras, tipos incorretos e números sujeitos a coerção são recusados. `request.example.json` contém placeholders inválidos e não pode ser executado sem preenchimento revisado.

`portalAcceptanceRef` é apenas uma referência persistida no diário. A CLI não consulta Portal nem verifica assinatura, identidade, pagamento ou consentimento do cliente. Um binding técnico e uma aprovação editorial interna também não substituem essa evidência externa. O nome do login e os seletores do manifesto não concedem autoridade; a função SQL decide principal, duty, tenant, epoch, expiração e estado.

Os três inputs usam o leitor privado existente: caminhos canônicos sem links simbólicos observados, arquivos regulares legíveis com um único link, modo `0400` ou `0600` e proprietário root ou o UID efetivo. O wrapper registra os bytes, SHA-256 e identidade e faz nova leitura de todos eles depois do planejamento. Mudança observada de bytes, inode, modo, dono, links, tamanho ou metadados de modificação impede a chamada mutável; tempo de acesso não participa da comparação. A CLI não reescreve nem repara inputs.

O helper específico de recibo verifica os ancestrais do diretório de saída: canônicos, sem componentes que sejam links simbólicos, propriedade root ou UID efetivo e sem escrita por grupo/outros. Um ancestral root com sticky bit é permitido; o parent imediato do recibo exige sempre UID efetivo e modo `0700`. O destino precisa estar ausente. Arquivo existente, diretório, link simbólico, link pendente ou reserva de outra execução causam recusa, mesmo que os bytes desejados fossem iguais. A CLI não cria diretórios nem altera permissões.

### Ambiente, plano e chamada única

Staging exige `APP_ENV=staging`, sem GitHub Actions nem opt-in de solicitação de CI, e banco `sites_staging`. O alvo deve vir do inventário revisado e corresponder ao endpoint direto observado. A conexão PDO é nova, não persistente, com `sslmode=verify-full`, CA fixa, `gssencmode=disable`, timeout de conexão de 5 segundos, UTF-8, `pg_catalog`, read committed, statement timeout de 10 segundos e lock timeout de 3 segundos. Começa somente leitura.

O serviço verifica PostgreSQL 18, TLS, OID, endereço, porta, login e capability efetiva restrita a `request_operation(uuid,text,bigint,text,text)`, sem grant option ou execução das demais APIs de core/diário. Não exige nem usa `read_operation`, `record_attempt`, `record_acknowledgement` ou `reconcile_expired`. A conferência de capability por catálogo não consulta o duty privado; a função SQL revalida a autoridade quando chamada.

```sh
php ops/publication-candidate/request.php --help
php ops/publication-candidate/request.php plan
```

`--help` funciona sem autoload, ambiente, arquivos privados ou banco. `plan` não aceita opções. Ele confere os inputs, o runtime e a disponibilidade observada do caminho do recibo, sem reservar arquivo ou chamar uma API privada de estado. Não consulta o binding corrente, o head do diário ou a idempotência existente e não comprova que a solicitação será permitida. Seu hash inclui o manifesto canônico inteiro, inclusive o destino técnico, os cinco seletores da solicitação e `receiptRef`, sem depender de relógio ou estado privado.

O plano retorna uma linha JSON com exatamente seis campos: `schema=SITES_PUBLICATION_REQUEST_PLAN_V1`, `status=planned`, `planSha256`, `expectedBindingRevision`, `canPublish=false` e `publicationVerified=false`. Para aplicar um plano revisado:

```sh
php ops/publication-candidate/request.php apply \
  --plan-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_PLAN_SHA256 \
  --authorize-request=SITES_PUBLICATION_REQUEST_ONLY_APPROVED
```

As duas opções são obrigatórias, aparecem uma vez e podem estar em qualquer ordem. A CLI recusa outras flags, overrides de caminho e autorizações dos demais candidatos. Recalcula o plano numa sessão nova somente leitura e compara o hash antes de reservar o recibo.

Após a confirmação, o helper cria exclusivamente um arquivo vazio `0600`, confere e mantém seu descritor aberto. O wrapper relê os três inputs, verifica que a reserva e seus ancestrais permanecem iguais, habilita escrita apenas nessa sessão e chama `apply` uma única vez. O serviço revalida runtime/hash e usa a API de solicitação já existente. Só após o retorno, a CLI grava o JSON mínimo no mesmo descritor, executa flush/fsync, relê os bytes, confere tamanho/identidade/custódia, fecha o arquivo e emite o resultado público.

### Saída pública e recibo para inspeção

O resultado público tem exatamente 16 campos. Mantém os seis campos do plano com `schema=SITES_PUBLICATION_REQUEST_RESULT_V1` e `status=recorded`, e acrescenta `technicalStatus`, `revision`, `attemptNumber`, `editorialRevision`, `bindingRevision`, `artifactDigest`, `receiptDigest`, `leaseExpiresAt`, `createdAt` e `updatedAt`. Os dois booleanos de autoridade continuam falsos. Não há referência de operação, projeto, release, aceite, idempotência, caminho, credencial ou token em stdout.

O recibo privado contém exatamente três campos e um newline final:

| Campo | Conteúdo |
| --- | --- |
| `schema` | `SITES_PUBLICATION_REQUEST_RECEIPT_V1` |
| `planSha256` | Compromisso do plano usado para esta execução |
| `operationRef` | UUID retornado pelo diário, mantido apenas no arquivo privado |

Esse recibo é um ponteiro privado vinculado ao plano, não um recibo do provedor, prova de aceite ou verificação de publicação. Não copia estado/revisão mutáveis. Um operador com acesso autorizado pode usar seu `operationRef` para preparar o manifesto privado de `inspect.php`, com alvo e credencial do leitor separado. O inspector relê o estado corrente por sua própria API e autorização; ele não recebe automaticamente o recibo nem herda autoridade do arquivo. A referência e o conteúdo do recibo devem permanecer fora dos logs e das saídas públicas.

### Idempotência, estado histórico e falha após commit

Uma solicitação nova requer o binding corrente e os atores vinculados válidos segundo o SQL. A idempotência é por tenant. Uma repetição com os mesmos cinco seletores pode retornar a operação e o estado mais recente já existentes; alterar esses seletores e reutilizar a chave causa conflito. O ator atual continua sujeito à autorização corrente, mas uma repetição válida não exige que seja o mesmo ator/epoch histórico da solicitação original nem revalida um binding histórico como autorização para enviar.

Por isso, `status=recorded` não afirma que uma nova linha foi criada. `technicalStatus` pode ser `requested`, `dispatched`, `acknowledged` ou `inconclusive` por repetição de uma solicitação que já avançou. A CLI não acrescenta `created` ou `replayed`, não cria outra tentativa, não renova lease e não usa um ACK relatado como prova de publicação observada. Um novo `receiptRef` muda o compromisso do plano e o arquivo de saída; não muda, por si só, a identidade SQL da solicitação.

Banco e arquivo não compartilham uma transação atômica. Falhas retornam exit 1, stdout vazio e somente `PUBLICATION_REQUEST_DENIED` em stderr, sem diagnóstico PDO, paths, seletores ou cadeia de exceção. A recusa pode deixar uma reserva vazia antes da chamada SQL, ou um arquivo parcial/completo depois de um commit. Falha ou perda da resposta não comprova rollback nem ausência da operação.

O helper mantém o descritor e verifica as identidades observadas, mas as operações por pathname do PHP não constituem uma travessia atômica de todos os ancestrais. Ele nunca remove, renomeia, sobrescreve ou corrige um destino, mesmo quando uma etapa falha. Não há retry automático. Inspecione o arquivo e o estado por acessos separados autorizados antes de decidir uma recuperação. Se uma repetição explícita for autorizada, use os mesmos seletores/idempotência e um novo `receiptRef`, obtenha um novo plano e confirme-o; isso permite aproveitar a idempotência existente sem sobrescrever o recibo anterior. A CLI não toma essa decisão pelo operador.

### Consulta exata sem repetir o writer

O [leitor candidato de solicitação](../../docs/PUBLICATION-REQUEST-READER.md)
acrescenta `PgPublicationJournal::readRequest` pelos mesmos cinco seletores. Uma
identidade reader atual e exclusiva pode recuperar em memória o snapshot e seu
`operationRef` mesmo após a revogação do writer original, sem chamar `request`.
Ausência ou divergência é uma recusa limitada e não cria operação. O estado é
histórico; a API não decide envio, retry ou publicação.

O leitor foi validado na #536 com **33 novas/1241 registry totais**, rollback16
e cleanup nas duas versões PHP. A [CLI separada da #538](../../docs/PUBLICATION-REQUEST-RECOVERY-CLI.md)
consome esse leitor em `recover-request.php plan|recover` e grava o UUID em
novo recibo privado. Confirmou **63 checks offline**; sua composição PostgreSQL
está implementada como [ensaio separado](../../docs/reviews/SITES-PUBLICATION-RECOVERY-COMPOSITION-2026-11-18.md),
com **38 novos/1279 totais confirmados** em PHP 8.3/8.4 na #540, incluindo
snapshots, rollback16 e cleanup. O inspector
conserva sua própria API, identidade e alvo;
não recebe automaticamente o retorno nem herda permissões.

### CI e limites da evidência offline

O caminho isolado exige `GITHUB_ACTIONS=true`, `APP_ENV=testing` e `SITES_PUBLICATION_REQUEST_CI=1`. Fixa `localhost`, porta `55449`, banco `postgres`, endereço observado `127.0.0.1` e login `pj_request_a_writer`. O OID, CA, capability exclusiva de solicitação, inputs privados, reserva, hash e autorização continuam obrigatórios. A allowlist de inspeção em CI aceita também `pj_request_a_reader`, para consultar a operação com um leitor sintético novo sem reativar o leitor anteriormente revogado. Esse acréscimo não modifica staging ou os guards SQL da inspeção.

Os testes offline cobrem help/recusas com autoload bloqueado e IO puro de recibos sintéticos em diretório `/tmp` próprio. Exercitam reserva real, escrita dos três campos, preservação, substituições de inode/parent e ausência de cleanup, sem simular sucesso SQL. Solicitação, replay, isolamento, revogação, consulta posterior e falha do recibo depois de um commit precisam da fixture PostgreSQL/TLS descartável do CI. Esta entrega não instala o comando, concede grants operacionais nem inicia publicação.
