# Imagine Sites — associação e revogação explícitas de releases

## Resultado proposto

Os comandos de registro e revisão editorial já produzem as duas entradas exigidas pelo registry: artefato imutável e revisão interna aprovada. A associação entre elas e sua revogação ainda eram chamadas somente pelas APIs PHP nos ensaios. Esta entrega acrescenta a CLI privada `binding.php`, com planejamento e comandos separados para associar ou revogar uma release existente.

Branch `feature/sites-release-binding-2026-11-18`, sobre a PR #499 / `9789cd050e1d4b88b9c469149cb796985ed615a2`. O serviço `PrivateReleaseBinding` reutiliza `PgSiteReleaseRegistry::bind` e `revoke`; não altera o SQL, suas regras de autorização ou suas tabelas.

## Seletores e planos

O manifesto estrito `SITES_RELEASE_BINDING_V1` contém `schema`, `target`, `action`, `siteRef` e `expectedBindingRevision`. O alvo conserva os sete campos de identidade e TLS dos candidatos anteriores. A ação determina as demais chaves permitidas:

| Ação | Seletores adicionais | Autoridade SQL necessária |
| --- | --- | --- |
| `bind` | `releaseRef` e `editorialRevision` | Principal vigente com duty `binding_writer` e somente a API de associação |
| `revoke` | Nenhum | Principal vigente com duty `revoker` e somente a API de revogação |

O manifesto não aceita HTML, tenant, pedido, contrato, produto ou um digest externo. A referência da release seleciona um artefato imutável, e o SQL compara o digest desse artefato com o da revisão editorial corrente aprovada. A assinatura existente não recebe um digest esperado adicional; compará-lo somente depois da gravação não forneceria uma precondição atômica.

O plano usa somente leitura e vincula o manifesto canônico inteiro ao hash `SITES_RELEASE_BINDING_PLAN_V1`. Não lê head, principal privado, artefato ou digest. Sua saída tem sete campos: schema, status, hash do plano, ação, revisão esperada e os dois booleanos de autoridade falsos. Um plano não reserva o CAS nem prova que o comando posterior será autorizado.

## Comandos e custódia

`plan` lê a ação do manifesto privado, sem aceitar overrides na linha de comando. `bind` exige `--plan-sha256` e `--authorize-bind=SITES_RELEASE_BINDING_ONLY_APPROVED`; `revoke` exige o hash e `--authorize-revoke=SITES_RELEASE_REVOCATION_ONLY_APPROVED`. A ação do manifesto deve corresponder ao comando. As opções não permitem trocar alvo, ação, revisão ou caminhos.

Manifesto e CA usam caminhos fixos. Cada ação lê apenas sua própria senha, também em caminho fixo. O wrapper conserva bytes, hash e identidade dos três arquivos utilizados e os relê depois da conexão e do planejamento. A sessão PDO nova começa somente leitura; a escrita é habilitada apenas depois da concordância entre ação, plano e custódia.

Os controles de runtime verificam PG18, TLS `verify-full` com CA pinada, alvo observado, identidade restrita, `pg_catalog`, UTF-8, read committed e EXECUTE exclusivo da função correspondente, sem grant option. Não há transação herdada, aplicação inicializada, worker ou reconexão automática.

## Resultado e histórico

Um `bind` bem-sucedido devolve dez campos técnicos: os sete campos do plano com schema/status de resultado, mais revisão de associação, revisão editorial e digest derivado do SQL. Um `revoke` devolve oito, acrescentando somente a revisão de associação. O objeto que contém referências comerciais não é serializado na resposta.

O SQL conserva suas regras de repetição, identidade e epoch. A resposta não distingue inserção de repetição, e o candidato não inventa `created` ou `replayed` a partir da aritmética das revisões. Cada chamada solicita uma única operação com o CAS revisado.

Uma nova revogação acrescenta uma entrada ao histórico e invalida a associação corrente; uma repetição reconhecida pelo SQL pode devolver o registro correspondente sem inserir outra entrada. Isso não remove o artefato, não apaga a aprovação interna, não despublica um site externo e não impede uma futura associação explicitamente autorizada. A revogação também pode ser necessária quando o autor ou binder anterior já não é válido; sua autorização depende do próprio revoker e da revisão corrente, sem exigir nova validade dos antecedentes.

O erro externo é somente `RELEASE_BINDING_DENIED`, com exit 1 e stdout vazio. Uma falha depois da chamada pode seguir um commit. Não há retry, avanço automático de CAS ou promessa de rollback; uma resposta inconclusiva exige apuração antes de outra decisão. Todas as respostas mantêm `canPublish=false` e `canAcceptRealOrders=false`.

## Ensaio integrado

O novo ensaio usa as CLIs reais de registro, transição editorial, associação, preparação, verificação e revogação, com identidades separadas. O autor original do ensaio de registro continua revogado. A allowlist exclusiva de CI do registro passa a aceitar também o autor sintético já existente `rr_registration_a_artifact_writer`, ainda ativo, conservando todos os controles de alvo, transporte, custódia e autoridade SQL. A regra de staging não muda.

O helper de associação reserva somente seus quatro arquivos ausentes e usa o módulo comum de leitura por descritores. Os três inputs ativos por chamada são conferidos integralmente. A limpeza respeita os recibos e a ordem binding → editorial → registration → inspection → package.

**Validação local aprovada:** 351 verificações offline do serviço, 128 da CLI, 65 de regressão da CLI de registro e PHPUnit 134 testes/1186 assertions/27 skips. Os testes offline verificam formato, compromisso do plano e recusas anteriores à conexão. A fixture prevê **63 verificações novas e 663 registry integradas**, com contagem conferida antes da execução. Sucesso SQL, associação corrente, repetição e revogação serão comprovados pelo workflow isolado `.github/workflows/sites-release-binding.yml`, em PHP 8.3/8.4, com os seis opt-ins explícitos. Não se declara autoridade SQL a partir de testes offline.

## Base concluída e próxima integração

A PR #499 passou em `9789cd050e1d4b88b9c469149cb796985ed615a2`, com **18/18 check-runs SUCCESS**. O [run 44227302252](https://github.com/rafhael100/imagine-sites/actions/runs/44227302252) confirmou **68 verificações editoriais novas, 600 registry, 386 workspace, 307 checks offline do serviço, 76 da CLI, 18 de custódia e PHPUnit 134 testes/1186 assertions/27 skips** nos dois runtimes. A evidência foi registrada no [relatório editorial](SITES-EDITORIAL-TRANSITION-2026-11-18.md).

O [manual dos candidatos de release](../../ops/release-candidate/README.md) descreve os inputs e comandos. A [reconferência da integração Portal](SITES-NEXT-INTEGRATION-2026-11-18.md) inclui as PRs #937 e #938 e preserva a distinção entre contrato oficial de briefing e laboratório sintético. Esta entrega não depende de inventar esse contrato. Não houve merge, deploy, mudança de staging, chamada a provider, aceite, pagamento ou habilitação de gates operacionais.
