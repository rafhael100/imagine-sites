# F1-349 — ordenação de revogação com bloqueio transacional da associação SQL

**Somente teste PostgreSQL 18 descartável, sem Portal real nem produção.**

## Lacuna observada

A F1-347 demonstrou políticas RLS baseadas em `session_user`, com tabela de associação privada entre role e empresa. Entretanto, uma consulta ao vínculo que não o bloqueie pode ser lida por uma transação enquanto outro processo revoga a associação, criando uma condição de corrida entre a decisão de autorização e sua persistência.

## Experimento

`tests/f349-pg-login-revocation-lock-order.php`, executado **após F1-347 e antes F1-348**, substitui exclusivamente na base descartável a função de consulta pelo equivalente `VOLATILE SECURITY DEFINER` com `SET search_path=pg_catalog` e `SELECT … FOR SHARE` sobre a linha ativa de `session_user`.

O teste abre uma transação do login B, lê o projeto via RLS e mantém o bloqueio da associação. Outra conexão administrativa tenta revogar a associação com `lock_timeout=250ms`: a atualização deve ser negada por SQLSTATE `55P03`, sem mudar o estado. Após `COMMIT` da transação B, a revogação pode avançar; ela é revertida neste cenário para que a F1-348 teste a transição definitiva de revogação monotônica. As duas tabelas originais continuam com apenas registros fictícios de F1-93.

## O que isso comprova — e o que não comprova

Comprova **somente no processo de laboratório** uma ordenação por lock PostgreSQL: uma revogação não confirma o término enquanto uma transação que consultou a associação com `FOR SHARE` continua aberta. Não resolve aplicação de grants via Portal, conexões compartilhadas, processos que não usam o guard, lock starvation, disponibilidade, efeitos distribuídos, filas/n8n, nem autoriza clientes ou serviços reais.

A role por tenant e o uso de `SECURITY DEFINER` têm custos e riscos operacionais; precisam de DBA/AppSec e desenho de identidade Portal↔Sites antes de serem aplicados. O teste não altera a migration Laravel core e não publica políticas. Os gates #33/#34/#281 e o estado `BLOCKED` permanecem. Nenhum merge, deploy, VPS, CRM, Cloudflare, DNS, dados pessoais ou custos.
