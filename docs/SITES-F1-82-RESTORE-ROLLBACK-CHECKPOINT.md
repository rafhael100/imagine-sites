# F1-82 — Regressão de rollback de histórico com dump e restore reais do PG18

## Objetivo
A F1-79 usa um ledger de decisões de revisão interna append-only. PostgreSQL RLS e triggers não impedem que uma restauração de backup antigo descarte decisões mais recentes. Esta etapa mede explicitamente esse risco num banco descartável, sem afirmar proteção operacional.

## Implementação
`LabReviewLedgerCheckpoint` calcula, com uma única consulta ordenada, um SHA-256 de todos os campos de evidência e IDs sequenciais observados para **um tenant**. A referência contém `throughId`, `rowCount` e `prefixSha256` e precisa ser guardada **fora do banco protegido**. A verificação aceita novas inserções posteriores ao checkpoint, mas recusa qualquer perda, alteração ou troca de empresa em seu prefixo.

O CI executa uma sequência real de ferramentas PostgreSQL 18 dentro do contêiner descartável do GitHub Actions:

1. Grava um checkpoint anterior no filesystem do runner, fora do banco de teste.
2. Executa `pg_dump -Fc` lógico do schema de revisão.
3. Insere uma nova decisão fictícia e grava um checkpoint externo mais recente.
4. Executa `pg_restore --clean --if-exists` do **dump lógico antigo** no mesmo banco descartável.
5. Comprova que o checkpoint anterior ainda confere e que o checkpoint mais recente **rejeita o estado restaurado**, além de testar RLS, adulteração de hash e acesso de outro tenant.

O teste é feito em PHP 8.3 e 8.4 com PostgreSQL 18. As referências e o arquivo de dump são apagados ao final da execução bem-sucedida. Nenhum site real ou cliente é utilizado.

## O que NÃO comprova
SHA-256 não é assinatura nem armazenamento externo à prova de adulteração; o runner é só uma simulação do guardião externo de checkpoint. Uma restauração que também retroceda ou adultere o checkpoint externo não será detectada por essa prova. Também não há restauração real de VPS Leaseweb, backup operacional, chaves confiáveis, monotonicidade entre serviços ou auditoria humana. Antes de go-live será necessário definir fonte protegida externa de checkpoints, identidade e rotação, política de backup e restore, RTO/RPO e avaliação independente.

Os dez gates de produção seguem BLOCKED; nenhum domínio, Cloudflare, Portal real, pagamento, cliente, credencial ou deploy foi acionado.
