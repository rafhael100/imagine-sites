# Diário técnico de publicação

Este componente persiste a intenção e o resultado relatado de uma tentativa técnica. Ele permite recuperar o histórico após a perda de um processo ou resposta, preservando o resultado como inconclusivo quando o prazo vence. Não instala transporte, provedor, rota, worker, principal ativo ou autorização de publicação.

O SQL fica no namespace `sites_publication`, instalado pela migration `2026_11_18_000007_add_publication_journal.php`. A API PHP é `Imagine\Sites\Publication\PgPublicationJournal`, com PDO PostgreSQL18 restrito e sem transação herdada.

## Operações

| Método PHP | Principal | Resultado |
| --- | --- | --- |
| `request(siteId, releaseRef, expectedBindingRevision, portalAcceptanceRef, idempotencyKey)` | `writer` | Cria intenção idempotente ligada à associação corrente do registry |
| `recordAttempt(operationRef, expectedRevision, leaseSeconds)` | `writer` | Registra a única tentativa permitida para o site e devolve o token separado |
| `recordAcknowledgement(operationRef, expectedRevision, leaseToken, providerRequestRef, receiptDigest)` | `writer` | Preserva o ACK técnico, sem confirmar publicação |
| `reconcileExpired(operationRef, expectedRevision)` | `reconciler` | Registra prazo vencido como inconclusivo |
| `read(operationRef)` | `reader`, `writer` ou `reconciler` | Consulta o snapshot da própria empresa, sem token |

Os seletores usam referências opacas; `portalAcceptanceRef` é uma referência declarada, sem verificação de aceite. O pedido, contrato, produto, digest e revisões são derivados da associação persistida. O journal não recebe um tenant arbitrário do chamador: a empresa vem do principal SQL vigente.

`request` e `recordAttempt` verificam a associação corrente antes e depois da escrita, usando o mesmo advisory lock de site do registry e do ciclo editorial. O guard original do registry é reutilizado por um executor interno NOLOGIN. O LOGIN do journal não recebe acesso às tabelas nem às APIs do core.

## Estados e concorrência

| Evento persistido | Estado técnico | Revisão |
| --- | --- | --- |
| `requested` | `requested` | 1 |
| `attempt_recorded` | `dispatched` | 2 |
| `ack_reported` antes do prazo | `acknowledged` | 3 |
| `lease_expired` | `inconclusive` | 3 |
| `late_ack_reported` | `inconclusive` | 3 ou 4 |

O relógio do PostgreSQL fixa o lease entre 1 e 60 segundos. Revisão esperada, token, login e época da autorização protegem os relatos posteriores. Um replay exato recupera o registro original, sem renovar prazo ou acrescentar evento. Conflitos de revisão ou tentativa produzem `PublicationJournalConflict`; demais recusas não expõem diagnósticos SQL ou parâmetros.

O índice único de tentativa vale por **empresa e site**, inclusive entre operações ou chaves diferentes. Uma tentativa anterior impede outra mesmo após ACK ou expiração. Não existe API de retry, resolução, desbloqueio ou rollback automático nesta versão. Essa restrição preserva o estado incerto até existir um contrato de reconciliação independente.

**Uma tentativa registrada não comprova um único envio ao provedor.** Dois processos que compartilham o mesmo login podem recuperar o mesmo token por replay. Nenhuma resposta desta API autoriza envio, e um futuro dispatcher precisará de deduplicação e fencing compatíveis com o provedor, além de observação independente. Acrescentar apenas um indicador de criação não resolveria a perda da resposta após o commit.

## ACK tardio e revogação

O histórico continua legível quando a associação é revogada ou a revisão editorial é reaberta. O ACK tardio conserva o resultado como inconclusivo e pode registrar a referência do provedor e o digest do recibo, sem alterar o core ou restabelecer autoridade.

Para escrever esse relato, o principal precisa continuar ativo, dentro do prazo de autorização e na mesma época que recebeu o token. Outro writer, uma autorização renovada ou um principal revogado não podem reutilizá-lo. O reconciliador autorizado pode registrar a expiração independentemente da revogação do writer; ele não pode inserir um ACK ou declarar o site publicado.

Todos os snapshots têm 24 campos estritos, datas UTC e `canPublish=false` / `publicationVerified=false`. `dispatched` significa que a tentativa foi registrada; `acknowledged` significa que um ACK foi relatado. Nenhum dos dois comprova entrega ou observação de release no destino.

## Persistência e privilégios

As três tabelas têm FORCE RLS. Operações e eventos recusam UPDATE, DELETE e TRUNCATE; principals recusam exclusão, redução de época ou reversão de revogação. A migração cria somente três executores internos NOLOGIN, sem associações a LOGINs ou dados iniciais.

As sete políticas adicionais do core concedem SELECT exclusivamente a `sites_publication_writer`. O novo owner recebe apenas USAGE do core e EXECUTE do guard booleano existente; não ganha leitura dos dados do core. Os logins de aplicação, provisionados separadamente em eventual ambiente aprovado, devem receber somente os entrypoints compatíveis com sua função. Não há provisionador operacional neste pacote.

O rollback verifica as três tabelas sob locks exclusivos e recusa qualquer histórico ou principal persistido com `Preserve publication journal history; recovery review required`. Dependências desconhecidas interrompem a transação; não há DROP CASCADE.

## Verificação

`php tests/publication-journal-offline.php` verifica formato, parâmetros, respostas e recusa por padrão. Não simula uma autoridade SQL positiva.

A fixture `tests/fixtures/release-registry/publication-journal.php` é opt-in no runner real do registry, exclusivamente no CI descartável. Ela usa migration, TLS, PDO e PostgreSQL18, incluindo dois processos concorrentes para operações diferentes do mesmo site. O workflow dedicado executa PHP8.3 e PHP8.4. O inventário F1-383 continua exigindo a lista exata de políticas e agora inclui as três tabelas vazias do journal.

Evidências executadas e limitações da entrega: [relatório de desenvolvimento](../../docs/reviews/SITES-PUBLICATION-JOURNAL-2026-11-18.md).
