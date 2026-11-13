# F1-97 — Rechecagem dos bytes privados em replay idempotente

## Mudança

Em F1-95, uma gravação inicial reabria os quatro arquivos do pacote privado depois da consulta ao banco e antes do INSERT. Já o caminho de **replay idempotente** comparava a primeira prova obtida antes da avaliação de autoridade e finalizava a transação sem uma segunda leitura dos arquivos.

A F1-97 acrescenta `SyntheticPrivateDraftVerifier::verifyDirectory($directory)` dentro da transação, também no ramo de replay, comparando a prova integral com a primeira verificação. Divergência lança `lab_draft_revision_replay_bytes_changed` e executa rollback, sem responder `idempotentReplay=true`.

## Teste de regressão

O teste integrado `tests/f95-pg-verified-draft-revisions.php`, já invocado pelo workflow PostgreSQL 18 para PHP 8.3/8.4, injeta uma mudança de HTML **depois** da primeira leitura de arquivos durante a checagem do gateway Portal fictício. Exige negação e persistência de apenas um registro. Restaura o HTML original e comprova que o replay exato continua aceito.

Executar na matriz efêmera `.github/workflows/sites-f1-94-project-admission.yml`. A modificação do teste não cria endpoints nem serviços.

## Limites

Não existe snapshot atômico entre o banco e o filesystem, lease distribuída, storage imutável ou aceite humano. O teste só garante que o ramo de replay utiliza uma segunda verificação; não bloqueia alterações após o commit. O gateway continua fictício, com DENY por padrão, e a GUC PostgreSQL é forjável por alguém com SQL direto. Os 10 gates de produção permanecem BLOCKED. Sem merge dev/main, deploy, credenciais, DNS, clientes ou custos.
