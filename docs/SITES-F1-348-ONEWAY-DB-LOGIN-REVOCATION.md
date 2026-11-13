# F1-348 — revogação unidirecional e revisões monotônicas do vínculo login→empresa

**Somente banco PostgreSQL 18 descartável em GitHub Actions. Não usar em produção.**

## Problema

O laboratório F1-347 já demonstra que a política RLS pode consultar um vínculo privado entre o login SQL realmente autenticado (`session_user`) e o tenant fictício. A mesma conexão passa a perder visibilidade depois que a associação é desativada. Sem um mecanismo adicional, porém, um administrador ou erro de sincronização poderia **reativar a associação**, trocar a empresa vinculada ou apagar a linha, dificultando auditoria.

## F1-348

Depois de executar F1-346 e F1-347, o teste `tests/f348-pg-monotonic-login-revocation.php` adiciona **somente naquele banco descartável** um trigger PostgreSQL à tabela fictícia de vínculos:

- É permitida apenas a transição de `active=true` para `active=false`, preservando login e empresa e avançando `revision` exatamente uma unidade.
- `UPDATE` que tente reativar, remapear empresa, repetir/retroceder revisão ou alterar indevidamente a linha é rejeitado com SQLSTATE 23514.
- `DELETE` do vínculo também é rejeitado. As associações revogadas permanecem para rastreabilidade, sem informações pessoais.

O teste exerce esses ataques tanto antes quanto depois da revogação da empresa B. O login B previamente conectado deixa de ler suas linhas; a tentativa de forjar o antigo `imagine_sites.tenant_ref` não restaura acesso. Nenhum registro adicional de projeto ou rascunho é persistido.

## Lacunas P0 que permanecem

É um **experimento**, não uma migração operacional. Uma role PostgreSQL por empresa pode ser impraticável com um pool SaaS compartilhado. Um administrador superuser poderia alterar/remover trigger e dados; a proteção não atende sozinha à custódia da autoridade. O experimento também **não prova segurança de revogação em transações simultâneas**, nem autentica clientes do Portal. A arquitetura operacional exigirá identidade/autorização Portal assinada, controle de conexão/roles, transações consistentes, trilha imutável e revisão independente DBA/AppSec.

As issues de IAM #33 e banco #34 continuam abertas, assim como #281 e demais gates OP. `config/operational-readiness.json` permanece `BLOCKED`, dez gates falsos. Sem merge, deploy, VPS, Cloudflare, DNS, clientes, dados pessoais ou custos.
