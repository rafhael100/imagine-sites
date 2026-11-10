# F1-110 — Entrada sintética assinada de projeto, com replay e autorização independente

## Objetivo

Exercitar, em sequência, o comando `CreateProject` em formato de envelope HMAC fictício, a prevenção de replay, a autorização atual independente e a admissão transacional no PostgreSQL 18 efêmero, sem expor endpoint HTTP ou simular uma identidade operacional inexistente.

## Implementação

A classe `src/Integration/LabSignedProjectIntake.php` exige `PHP_SAPI=cli`, `APP_ENV=testing`, `SITES_F94_PROJECT_ADMISSION_LAB=1`, `SITES_F110_SIGNED_PROJECT_LAB=1` e ausência de transação previamente aberta. Nenhuma dessas verificações faz consulta externa. Em seguida:

1. `SyntheticSignedCommand::verify` exige envelope com emissor `imagine-portal`, audiência `imagine-sites`, HMAC com chave sintética fornecida pelo teste, timestamps/nonce e `ReplayStore` explicitamente fornecido. O armazenador padrão `DenyingReplayStore` recusa todos os comandos.
2. Após a verificação da assinatura e a reserva do nonce, deriva a referência do site do próprio `SiteProjectCommand` recebido, sem aceitar um site arbitrário informado pelo solicitante.
3. `LabAuthorizedProjectAdmissionStore` verifica separadamente o gateway de autorização (padrão `DenyingPortalProjectAuthorityGateway`), a conta PG restrita, a admissão e a idempotência, incluindo a segunda revalidação antes do COMMIT (F1-104).
4. Retorna apenas metadados `labOnly=true`, `portalIdentityOperational=false`, `officialClientAcceptance=false` e `publishable=false`.

O nonce sintético é **consumido antes** da autorização e da transação. Se o projeto for negado, um retry deve usar novo envelope assinado com novo nonce. Isso evita a ambiguidade de reaproveitar o mesmo nonce após falha, embora não componha uma transação distribuída entre o armazenamento de replay e o banco.

## Prova automatizada

`tests/f94-pg-authorized-project-admission.php` integra um armazenador de nonce em memória (somente para este teste), o gateway fictício e a admissão PostgreSQL18. Exercita flag desligada sem efeitos, armazenador padrão negando, ausência de autorização, nonce consumido impedindo segunda tentativa, criação de único projeto Onepage, replay exato negado, nova assinatura permitida para idempotência, adulteração da empresa rompendo o MAC, revogação e restabelecimento do gateway e revalidação com novo nonce. Também exercita o gateway de snapshot/epoch das F1-102/105, que só permite replay quando o registro fictício e a revisão corrente coincidem; avançar o epoch bloqueia até um novo comando validamente assinado. A suíte executa em PHP 8.3 e 8.4 no workflow F1-94.

## Bloqueios operacionais

Este código **não** implementa autenticação mútua, gestão de segredos, chave de assinatura operacional, armazenamento durável de nonce entre workers, adaptador Portal autenticado, IAM, autorização de cliente, aceite formal ou publicação. A HMAC com chave conhecida nos testes não prova identidade de cliente ou contrato. O contexto PostgreSQL de tenant ainda pode ser falsificado por SQL direto. Não incorporar esse módulo em controlador HTTP, worker ou produção.

Sem merge em dev/main, deploy, VPS, DNS, uso de cliente/PII ou gastos. Todos os dez gates em `config/operational-readiness.json` permanecem `BLOCKED`.
