# F1-148 — Lease técnico jamais ultrapassa T0 + 432 horas

A F1-146 recusava reservar uma intenção de metadados *após* 432 horas, mas uma reserva efetuada faltando poucos segundos para o vencimento ainda receberia `leased_until = agora + 5 minutos`. Isso abria uma inconsistência temporal na própria fixture.

A F1-148 aplica no PostgreSQL 18 `LEAST(statement_timestamp() + interval '5 minutes', created_at + interval '432 hours')`, mantendo o limite absoluto original para a lease mesmo perto da expiração. O teste físico em `tests/f146-pg-outbox-lease.php` simula, **somente como administrador do banco descartável**, uma intenção a menos de 1 minuto do limite, confirma a igualdade exata entre vencimento da lease e T0+432h e depois comprova que T0 com 433h não permite nova reserva. Os testes originais de concorrência, revogação, isolamento e sem-ACK continuam na matriz PHP 8.3/8.4 do workflow F1-146.

**Limites:** não é fila real, não é um worker, não envia contato/lead, não reprograma uma entrega operacional e não concede ACK. A manipulação do relógio da fixture não é permitida em produção. Continuam bloqueados IAM real Portal/CRM, ingressos fisicamente redundantes, retenção/LGPD, homologação e revisão independente AppSec/DBA. OP04 #36 e 10/10 gates permanecem BLOCKED; PR draft, sem merge, deploy, VPS ou custos.
