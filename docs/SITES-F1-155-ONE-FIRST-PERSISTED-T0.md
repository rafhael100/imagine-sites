# F1-155 — Um só T0: primeiro registro durável rege lease, prazo e descarte

## Falha encontrada

A F1-145 criava dois timestamps no mesmo commit: o primeiro em `synthetic_claims.created_at` e o segundo na linha de `synthetic_claim_outbox.created_at`. F1-148/149 reforçaram o limite de 432h, mas a lease ainda media o prazo a partir do timestamp **do outbox**, enquanto F1-154 aplicava a retenção pelo timestamp **da identidade original**. Mesmo diferenças pequenas violam a definição de um único T0; um timestamp do outbox artificialmente mais novo poderia tornar elegível uma submissão já expirada.

## Correção e provas

F1-155 passa a selecionar os metadados de outbox por `JOIN` com `synthetic_claims` e usa exclusivamente `c.created_at AS original_t0` para elegibilidade (72h iniciais, 432h total), ordenação e `leased_until = LEAST(now+5min, original_t0+432h)`. Preserva as proteções de F1-142/143 para login de banco e F1-149 para binding/revisão vigente e bloqueio concorrente.

`tests/f146-pg-outbox-lease.php` foi atualizado para simular **apenas no CI administrador** a idade da identidade original; o novo `tests/f155-pg-identity-t0-lease-and-retention.php` altera o timestamp do outbox para uma data futura e confirma que essa alteração **não** estende lease, recuperação ou purga. Além disso, a função owner-only F1-154 usa a mesma identidade original para descarte, preservando o tombstone que impede reset por retry. Matriz PostgreSQL18 PHP8.3/8.4.

## Limitações

Este é um laboratório de metadados sintéticos, sem CRM, lead real, consentimento, redundância física, autenticação do Portal, worker, retenção efetiva LGPD nem eliminação em backups. A adulteração de timestamps pelo owner serve exclusivamente à simulação do tempo; em operação real é necessário proibir correções destrutivas do T0 e revisar a política de retenção. OP04 #36 e 10/10 gates seguem BLOCKED; sem merge, deploy, VPS ou despesas.
