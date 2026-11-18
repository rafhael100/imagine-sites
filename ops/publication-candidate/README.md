# Consulta privada do diário de publicação

Este candidato consulta uma operação conhecida do diário técnico já persistido. É útil para recuperar seu último estado após perda de processo ou resposta. A leitura não solicita tentativa, não reconcilia expiração, não envia conteúdo e não verifica publicação no destino.

## Comando

```sh
php ops/publication-candidate/inspect.php --help
php ops/publication-candidate/inspect.php inspect
```

`inspect` é a única operação. Não há argumento para caminho, listagem, filtro, saída em arquivo, retry ou mutação. O comando não inicializa Laravel nem registra rota ou worker.

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
