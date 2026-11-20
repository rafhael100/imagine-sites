# Sites Landing PG18 — prova real de contenção da linha de autorização (draft)

Continuação de [Sites #565](https://github.com/rafhael100/imagine-sites/pull/565), base fixa `852ce559a57428e26a981aaeb47ecbff75391cfc`. Sem edição da branch de #565, Portal fixado em #1019.

## O que se comprova

As duas funções candidatas de custódia e revisão devem obter `FOR UPDATE` na **mesma linha de autorização de empresa/site**, não apenas conferir o registro sintético e continuar sem bloquear. Com duas conexões PDO independentes em PostgreSQL 18 real descartável:

1. `postgres` mantém transação aberta com `SELECT ... FOR UPDATE` na admissão de tenant A/site A;
2. o writer com role A tenta armazenar versão nova, válida: timeout de bloqueio de 300 ms deve resultar em erro negado, sem nova linha;
3. solto o lock, a mesma versão válida é persistida;
4. em teste distinto, role de reviewer A tenta registrar observação válida enquanto a admissão está bloqueada: negação sem linha;
5. solto o lock, o reviewer consegue registrar observação não autorizadora.

Além dessa prova, seguem ativas as matrizes de isolamento entre empresas, idempotência, revogação, SHA-256 e readback.

## Limites

A prova verifica contenção transacional **dentro do PostgreSQL de CI**, e não a revogação distribuída entre Portal e Sites. Não demonstra produção, identidade humana autenticada, RLS real da aplicação, transporte S2S homologado, aprovação, publicação, aceite ou cobrança. Todos os gates seguem BLOQUEADOS; sem merge/deploy/credenciais ou alterações comerciais.
