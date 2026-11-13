# F1-141 — Revisão monotônica do vínculo na base PostgreSQL CI

## Lacuna tratada
A F1-139 pressupunha uma fonte que aumentasse a revisão a cada mudança. A F1-140 já realiza um registro técnico sob o mesmo bloqueio transacional da linha de vínculo, mas sua fixture permitia que um administrador reduzisse ou reutilizasse uma revisão. A F1-141 adiciona um **trigger CI** que exige `NEW.revision > OLD.revision` em toda atualização da linha.

Isso permite testar a sequência A→B→A sem revalidar uma autorização antiga: a volta ao estado A deve usar revisão nova, e alegações da revisão antiga são recusadas pela função de registro da F1-140. O trigger também impede a troca de identificador (organização/vínculo), DELETE ordinário e reativação silenciosa da mesma identidade após revogação na fixture.

## Arquivos e evidências
- `labs/postgres/f1-141-monotonic-binding-trigger.sql`: trigger no banco descartável `sites_f140_atomic_lab`; falhas SQLSTATE 23514 para revisão repetida, rollback, mudança de chave, DELETE e reativação.
- `tests/f141-pg-monotonic-binding.php`: duas versões de estado e sequência A→B→A, alegação técnica com revisão velha negada, revogação e contagem de claims.
- `.github/workflows/sites-f1-141-monotonic-binding.yml`: instalação da fixture F1-140 + trigger F1-141 e testes reais PG18 PHP8.3/8.4.

## Limites de segurança
Esta restrição funciona somente enquanto a mesma linha e seus triggers não forem removidos ou contornados por um administrador. Não é controle de acesso a uma organização, não protege revisão de uma fonte Portal/CRM remota e não substitui autenticação, assinatura, transação distribuída, revogação intersistemas, consentimento nem confirmação CRM. A função da F1-140 ainda admite UUIDs arbitrários para qualquer organização; **não deve expor-se como endpoint ou ser utilizada com dados reais**.

Revisão AppSec/DBA/SEG e requisitos OP04 (#36) continuam pendentes, com 10/10 gates de produção BLOCKED. PR draft empilhado sobre F1-140, sem merge dev/main, dados reais, VPS, publicação ou despesas.
