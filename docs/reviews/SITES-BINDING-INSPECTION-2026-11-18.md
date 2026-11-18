# Imagine Sites — consulta do último evento de associação

## Uso concreto

O fluxo privado de associação e revogação já preserva os eventos e recusa repetição automática após uma resposta perdida. Seu manual orientava apurar o estado persistido por acesso separado, mas não oferecia uma consulta exata desse histórico. A API `read_current_release_binding` exige referências comerciais conhecidas e verifica a elegibilidade corrente; um retorno vazio pode decorrer de revogação, reabertura editorial ou perda de autoridade histórica. Esse retorno não descreve o último evento registrado.

A branch `feature/sites-binding-inspection-2026-11-18` acrescenta essa observação privada. Parte da PR #509, em `b288c40ee08186fb17509bc064c1ed5c63ace015`, cuja validação confirmou 19 check-runs, 404 verificações SQL do workspace e 394 HTTP. O contrato de escrita e a decisão sobre outra ação continuam separados da consulta.

## Contrato SQL aditivo

`sites_core.read_release_binding_head(uuid)` usa o owner existente `sites_core_release_reader`. A migration010 cria somente essa função e revoga o EXECUTE de PUBLIC. Não cria tabelas, colunas, políticas, roles ou grants de LOGIN. O owner já possui os SELECT e as políticas necessários; seus sete acessos de leitura e quatro EXECUTE internos anteriores permanecem reconhecidos, sem chamar ou ampliar os helpers antigos.

A função exige uma sessão somente leitura em PostgreSQL 18, isolamento read committed e um LOGIN restrito a essa entrada. Confere os owners, privilégios, políticas RLS, proteções do histórico e configuração das funções existentes. O principal de leitura é derivado de `session_user` em `release_registry_principals`, com duty `reader`, empresa vinculada, epoch positivo, ativo, sem revogação e dentro do prazo.

Uma única SELECT obtém o projeto da empresa vinculada e seu último evento por `binding_revision`. Depois, uma nova observação confere novamente empresa, duty, epoch, ativação, prazo e revogação do leitor. A função é VOLATILE para permitir snapshots novos entre suas consultas; ela não escreve, bloqueia a autoridade nem reserva uma revisão para uma ação futura.

| Estado observado | Resposta da função |
| --- | --- |
| Projeto próprio com evento `bound` | Última revisão de associação, tipo `bound`, revisão editorial e digest registrados nesse evento |
| Projeto próprio com evento `revoked` | Os mesmos campos históricos, com tipo `revoked` |
| Projeto próprio sem eventos | Uma linha com os quatro campos de observação nulos |
| Projeto inexistente ou de outra empresa | Nenhuma linha; o serviço apresenta a mesma recusa limitada |
| Leitor sem autorização corrente | Recusa, sem projeção privada |

Os dois campos de autoridade da resposta permanecem falsos. A consulta não verifica se a última associação ainda é utilizável: a aprovação editorial, o autor do artefato ou o ator da associação podem ter mudado depois do evento. Essas mudanças não apagam o histórico consultado pelo leitor atualmente autorizado.

A reversão verifica a identidade, definição e configuração da função e usa DROP sem CASCADE. Preserva eventos, projetos, artefatos, roles, políticas e APIs anteriores. Uma reaplicação volta a criar a função com acesso somente do owner; a migration não restaura grants de leitores.

## Serviço e linha de comando

`PrivateReleaseBindingInspection` recebe um PDO novo e o hash observado da CA privada. Valida um manifesto `SITES_RELEASE_BINDING_INSPECTION_V1` com exatamente `schema`, `target` e `siteRef`. O target contém host, porta, banco, OID do banco, login, endereço observado e hash da CA. O seletor não contém empresa, release, ação ou revisão esperada.

O JSON é limitado a 16384 bytes e recusa chaves repetidas, inclusive com escapes equivalentes, tipos coercivos, campos extras e coordenadas impróprias. PHP de 64 bits é obrigatório para representar a revisão BIGINT. O serviço exige PDO PostgreSQL, exceções ativas, ausência de transação herdada, UTF-8, `pg_catalog`, sessão somente leitura, read committed, TLS e identidade exata do alvo. Confere a definição pinada da função e o EXECUTE exclusivo antes e depois da consulta, mantendo o mesmo backend observado.

O comando separado `ops/release-candidate/inspect-binding.php inspect` abre a conexão verify-full e usa três arquivos privados fixos: manifesto, senha do leitor e CA. Após a consulta, relê os bytes e a identidade dos três arquivos antes de liberar stdout. Não inicializa Laravel, cria arquivo de saída, muda permissões ou habilita escrita. `--help` funciona antes do autoloader; comandos de escrita, opções e flags de aprovação são recusados.

O resultado público possui exatamente oito campos: `schema`, `status`, `lastBindingRevision`, `lastEventKind`, `editorialRevision`, `artifactDigest`, `canPublish` e `canAcceptRealOrders`. Os quatro campos de observação são nulos em conjunto quando o projeto autorizado não tem evento. As referências privadas, tenant, login e ator não saem em stdout. Falhas usam somente `RELEASE_BINDING_INSPECTION_DENIED`, sem encadear diagnóstico SQL.

O [manual do candidato](../../ops/release-candidate/README.md#read-only-inspection-of-the-last-binding-event) documenta os mounts, limites, comando, exemplo e interpretação de cada campo. A instalação da função e o provisionamento de leitores em um ambiente existente permanecem operações separadas.

## Interpretação após uma resposta perdida

Uma observação pode ajudar a comparar o histórico com os dados de um plano conhecido. Ela não identifica qual chamada produziu o evento, distingue criação de repetição, atribui o evento a um operador ou comprova que uma chamada interrompida teve sucesso. Outra ação pode ter sido registrada depois dela.

O número retornado não constitui uma reserva de CAS. Não há retry, incremento de revisão, reconciliação, bind ou revoke automático. Outra decisão exige entradas revisadas e as conferências correntes da API de escrita correspondente. O último evento `bound` também não comprova pagamento, aceite expresso, elegibilidade de publicação ou publicação externa.

## Verificação executada e pendente

O serviço passou em **172 assertions offline** de formato, ambiguidade JSON, pins e recusas de sessão/conexão. A CLI passou em **49 checks offline** de ajuda, argumentos e ambiente, com autoloader e mounts indisponíveis. Esses testes não fabricam uma consulta SQL positiva. A suíte completa da aplicação passou em **168 testes, 1818 assertions e 27 skips explícitos**, com `--do-not-cache-result`. Lint dos arquivos PHP, sintaxe YAML/shell do workflow, matriz PHP 8.3/8.4, pins de draft/base/head e dez opt-ins passaram.

As revisões independentes do serviço, testes, CLI, exemplo e manual não identificaram bloqueadores. SQL e migration foram lidos separadamente e seus hashes conferidos: contrato `c5a62ac65d4dcad98a6ef68f7c87b80f424cd0427cd262d52cdf374789bf02a1`, corpo da função `4e3c9069a99223296b0fe95311fe8e5e27bcfd2ef900fb2afae80144b15a6a04`.

A fixture PostgreSQL/CLI está implementada e revisada independentemente como décimo opt-in da cadeia descartável, com **55 verificações novas/905 registry previstas**, preservando as 850 anteriores. O workspace mantém suas 404 verificações. A fixture cria dois leitores separados e um binder sintético próprio; usa as APIs reais para artefatos, revisão editorial, associação e revogação. A resposta da escrita é descartada antes da inspeção, sem inferir atribuição da chamada.

Os casos cobrem projeto próprio vazio, último bound e revoked, evento preservado após inativação do binder e reabertura editorial, leitor da outra empresa com resultado positivo próprio, desconhecido/empresa diferente e tentativa de tenant via sessão. O ator histórico desativado é o binder novo, não o autor do artefato. Alterações intencionais de ativação/prazo do leitor avançam seu epoch; os snapshots de cada leitura são tomados depois dessas alterações e devem permanecer iguais. A revogação definitiva do leitor fica por último, sem restauração.

Também são conferidos grants extras, coluna privada, grant option, membership, FORCE RLS, política, sessão, OID, TLS real, CA não confiável e custódia dos três inputs. Up/down/reapply preservam linhas, funções antigas, OIDs, ACLs, roles, políticas e triggers; grants de leitores não voltam implicitamente. O helper novo reserva três paths próprios, testa recusa por inode divergente e participa da limpeza antes do auxiliar editorial. PHP lint, AST Python, Bash e revisão cruzada passaram. **Os 55/905 e a CLI conectada ainda aguardam CI; nenhuma execução PostgreSQL local foi feita.**

## Fronteiras

O workspace demonstrativo, o fluxo comercial do Portal, os escritores existentes e as autoridades de publicação conservam seus contratos. A entrega não fornece um corpo oficial de briefing nem substitui o aceite do cliente. O [acompanhamento da integração](SITES-NEXT-INTEGRATION-2026-11-18.md) distingue o novo marcador sintético do Portal do insumo versionado ainda necessário.

O desenvolvimento permanece em draft. Não houve instalação em staging, uso de dados reais ou alteração de infraestrutura. Os dez gates operacionais continuam BLOCKED.
