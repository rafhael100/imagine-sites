# Imagine Sites — reconciliação explícita do diário

## Resultado proposto

O diário técnico já registra solicitação, tentativa, ACK relatado e resultado inconclusivo. A CLI de inspeção permite consultar uma operação conhecida, mas preserva um estado `dispatched` mesmo após o prazo vencer. Esta entrega acrescenta um comando separado para planejar e solicitar a reconciliação pela API SQL existente, com credencial própria e autorização explícita.

Branch `feature/sites-publication-reconciliation-2026-11-18`, sobre a PR #500 / `3eacc40b2f323b246015510cad1ac60a5c17239a`. `PrivatePublicationReconciliation` usa uma única chamada a `PgPublicationJournal::reconcileExpired`. Não há alteração de SQL, schema, grants, estados permitidos ou regras de replay. O comando não cria solicitação, tentativa ou ACK e não executa envio a um provider.

## Seletores, custódia e runtime

O manifesto estrito `SITES_PUBLICATION_RECONCILIATION_V1` contém exatamente `schema`, `target`, `operationRef` e `expectedRevision`. O alvo mantém os sete campos dos candidatos privados: hostname, porta, banco, OID, login, endereço observado e fingerprint da CA. A referência da operação é UUID, e a revisão é um inteiro de 1 a `PHP_INT_MAX - 1` em PHP de 64 bits. Campos extras, duplicados e coerções são recusados. A entrada não aceita tenant, token, provider, relógio ou duração de lease.

Manifesto, senha e CA têm três caminhos privados fixos. O wrapper usa a custódia existente, conserva bytes, SHA-256 e identidade, e relê todos os inputs depois de conectar e calcular o plano. Mudança observada impede a chamada mutável. A CLI não repara arquivos, permissões ou credenciais e carrega somente o autoloader Composer, sem inicializar a aplicação.

A conexão PDO nova começa somente leitura, com TLS `verify-full`, CA pinada, `pg_catalog`, UTF-8, isolamento read committed e timeouts. O runtime observa PostgreSQL 18, TLS, banco/OID, login, endereço e porta. O catálogo precisa demonstrar USAGE de `sites_publication` e EXECUTE exatamente de `read_operation(uuid)` e `reconcile_expired(uuid,bigint)`, sem grant option nessas funções ou execução das demais APIs de core/diário. Isso verifica capabilities; não lê o rótulo privado do principal. A função SQL verifica a autoridade corrente ao aplicar.

## Plano sem consulta da operação

`php ops/publication-candidate/reconcile.php plan` não recebe opções. O plano não chama `read_operation`, não busca o head, não comprova existência da operação nem verifica o prazo do lease. Seu hash usa o prefixo próprio e o manifesto canônico completo, sem snapshot privado ou relógio. Portanto, o mesmo compromisso de seletores pode permanecer igual antes e depois de uma mudança no diário.

A resposta tem exatamente seis campos:

| Campo | Conteúdo |
| --- | --- |
| `schema` | `SITES_PUBLICATION_RECONCILIATION_PLAN_V1` |
| `status` | `planned` |
| `planSha256` | Compromisso canônico dos seletores e alvo |
| `expectedRevision` | Revisão fornecida no manifesto |
| `canPublish` | `false` |
| `publicationVerified` | `false` |

O plano não reserva revisão nem autoriza a transição SQL. Não demonstra expiração, estado corrente, principal ativo ou direito de reenviar conteúdo.

## Aplicação única e resultado técnico

`apply` exige o hash revisado e `--authorize-reconcile=SITES_PUBLICATION_RECONCILIATION_ONLY_APPROVED`, cada opção uma vez, em qualquer ordem. O wrapper recalcula o plano somente leitura, confere o hash e relê os três inputs. Só então habilita escrita nessa sessão e invoca o serviço uma única vez. Não há transação herdada, reconexão automática ou escolha de outra revisão para superar um conflito.

Quando for necessária e permitida uma nova transição, a função existente confere `dispatched`, revisão corrente e prazo vencido usando o relógio do PostgreSQL, sob seu lock. Acrescenta `lease_expired` e conserva os dados da tentativa e do lease. O comando não reinicia prazo, cria outra tentativa, restaura associação comercial ou fornece autorização de publicação.

A resposta de apply mantém os seis campos do plano, com `schema=SITES_PUBLICATION_RECONCILIATION_RESULT_V1` e `status=reconciled`, e acrescenta dez campos técnicos: `technicalStatus`, `revision`, `attemptNumber`, `editorialRevision`, `bindingRevision`, `artifactDigest`, `receiptDigest`, `leaseExpiresAt`, `createdAt` e `updatedAt`. São **16 campos exatos**, sem referências da operação, empresa, pedido, contrato, release, provider, token, HTML ou credenciais.

`technicalStatus` é sempre `inconclusive`; os dois booleanos de autoridade continuam falsos. O status externo `reconciled` descreve o retorno válido da API, sem converter o resultado técnico em publicação observada.

## Repetição, ACK tardio e época corrente

A reconciliação pode reconhecer um evento já inconclusivo, respeitando a revisão esperada aceita pelo contrato SQL. Esse estado pode ter sido produzido por um ACK tardio e conter `receiptDigest`. Um sucesso não prova nova inserção, ausência de ACK ou correspondência entre o ator/epoch do evento histórico e o reconciliador atual.

O SQL revalida o reconciliador corrente. A regra de replay de `reconcile_expired` não exige que esse reconciliador seja o autor do evento já inconclusivo nem que conserve a época daquele evento. Essas regras são diferentes das regras de repetição de associação de release e não podem ser inferidas a partir de outro candidato. A CLI não acrescenta indicadores `created` ou `replayed` nem os calcula pela diferença de revisões.

Um ACK relatado continua sem comprovar publicação no destino. Reconciliação não valida vínculo corrente para liberar envio, não restabelece um autor revogado e não reinicia a operação. Cada nova decisão permanece explícita.

O erro externo é somente `PUBLICATION_RECONCILIATION_DENIED`, com exit 1 e stdout vazio. Falha durante ou depois da chamada pode seguir um commit; não é promessa de rollback nem evidência de que não houve ACK. O comando não faz retry, avanço automático de revisão ou reset. A inspeção por um leitor autorizado pode ajudar a apurar o estado técnico, mas não autoriza a repetição nem substitui a observação externa da publicação.

## Verificação e limites

| Ensaio local | Resultado |
| --- | --- |
| Serviço de reconciliação offline | 271 verificações aprovadas |
| CLI de reconciliação offline | 81 verificações aprovadas |
| Regressão do diário | 99 verificações aprovadas |
| Regressão do serviço de inspeção | 150 verificações aprovadas |
| Regressão da CLI de inspeção | 21 verificações aprovadas |
| PHPUnit da aplicação | 134 testes, 1186 assertions e 27 skips |
| Safety guard em cópia de fontes | Aprovado; dez gates continuam `BLOCKED` |
| Sintaxe, recusa do exemplo e revisão cruzada | Aprovadas |
| Reconciliação SQL/TLS e CLI reais | 49 verificações novas / 712 registry aprovadas em PHP 8.3/8.4 |

Os testes offline não fabricam autoridade SQL. A CLI é exercitada em subprocessos com acesso limitado ao seu diretório: help funciona sem autoload, e argumentos/ambientes recusados não alcançam os inputs privados nem o banco. O exemplo é inelegível pelo parser real. As revisões independentes do serviço e wrapper não encontraram bloqueador.

O workflow `.github/workflows/sites-publication-reconciliation.yml` deriva do workflow da base corrigida. Fixa repositório, draft, branch de origem e SHA `3eacc40b2f323b246015510cad1ac60a5c17239a`, com PHP 8.3/8.4, lint, ensaios offline, PHPUnit e contratos PostgreSQL descartáveis. Conserva os seis opt-ins anteriores e acrescenta `SITES_PUBLICATION_RECONCILIATION_CI=1`. Não adiciona jornada HTTP a esta entrega de CLI.

Na fixture executada, dois processos CLI reais aguardam o mesmo lock SQL. O ensaio observa ambos em `pg_stat_activity`, com `wait_event_type=Lock` e `wait_event=advisory`, e libera o lock sem ampliar o timeout do candidato. Esses valores e a atualização do snapshot de estatísticas seguem a [documentação oficial do PostgreSQL 18](https://www.postgresql.org/docs/18/monitoring-stats.html). A comparação posterior exige a mesma projeção nos dois processos e exatamente um evento de expiração, preservando todas as linhas e catálogos anteriores.

O helper novo reserva apenas os três arquivos da reconciliação, recusa qualquer path preexistente e lê fontes e recibo por descritores pinados. Confere todas as identidades do recibo antes do primeiro `unlink`; o negativo de recibo exige que todos os arquivos sobrevivam. Não assume propriedade de `/run/secrets` ou de outro diretório. O runner ordena a limpeza reconciliation → binding → editorial → registration → inspection → package. A revisão desse helper não muda a implementação nem amplia as garantias dos helpers anteriores.

A revisão identificou e corrigiu um falso positivo no negativo entre empresas: uma operação B ainda em `requested` falharia também por não ter lease. A fixture agora cria uma tentativa B real, confirma sua expiração pelo relógio do banco e exige sucesso pelo reconciliador B, com comparação integral do evento. Só depois a CLI A precisa recusar o mesmo replay elegível. O reader B já revogado continua revogado. A revisão final do helper, integração, negativos e projeções não encontrou bloqueador.

## Evidência concluída da PR #501

HEAD validada: `889bf55bde70e1ba617cb4fcde89e2c284c742e3`; **17/17 check-runs SUCCESS** na conferência final. O [run 44231736687](https://github.com/rafhael100/imagine-sites/actions/runs/44231736687) passou nos jobs [PHP 8.3 / 134558158980](https://github.com/rafhael100/imagine-sites/actions/runs/44231736687/job/134558158980) e [PHP 8.4 / 134558159205](https://github.com/rafhael100/imagine-sites/actions/runs/44231736687/job/134558159205). Ambos confirmam **49 verificações novas, 712 registry, 386 workspace, 271 checks offline do serviço, 81 da CLI e PHPUnit 134 testes/1186 assertions/27 skips**.

Expiração pelo relógio do banco, concorrência de processos, isolamento com operação elegível, replay, ACK tardio e autoridade corrente passaram nas duas versões de PHP. Os seis negativos de recibo, a limpeza reconciliation → binding → editorial → registration → inspection → package e o encerramento dos clusters descartáveis foram confirmados. Não foi iniciado PostgreSQL local; essa evidência vem do laboratório descartável de CI. A custódia dos recibos dos dois helpers antigos é uma correção separada, desenvolvida depois deste checkpoint.

## Base validada da PR #500

A base corrigida foi validada em `3eacc40b2f323b246015510cad1ac60a5c17239a`: **12/12 check-runs SUCCESS** na conferência final. O [run 44229954311](https://github.com/rafhael100/imagine-sites/actions/runs/44229954311) passou nos jobs [PHP 8.3 / 134552257610](https://github.com/rafhael100/imagine-sites/actions/runs/44229954311/job/134552257610) e [PHP 8.4 / 134552257114](https://github.com/rafhael100/imagine-sites/actions/runs/44229954311/job/134552257114), ambos com **63 verificações novas de associação/revogação, 663 registry, 386 workspace, 351 checks offline do serviço, 128 da CLI e PHPUnit 134 testes/1186 assertions/27 skips**.

Os cinco negativos de recibo, a limpeza binding → editorial → registration → inspection → package e o encerramento dos clusters descartáveis também foram confirmados. A correção de fixture anterior a essa execução não alterou código do produto. Essa evidência pertence à base #500 e não aprova antecipadamente a reconciliação #501. O [relatório de associação](SITES-RELEASE-BINDING-2026-11-18.md) mantém os detalhes.

O [manual dos candidatos do diário](../../ops/publication-candidate/README.md) descreve os comandos e a custódia. Os requisitos independentes de contrato, pagamento, aceite, publisher e observação do destino continuam documentados em [Próxima integração central](SITES-NEXT-INTEGRATION-2026-11-18.md). Esta entrega não altera staging, provider, DNS, dados reais ou gates operacionais.
