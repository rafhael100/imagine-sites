# F1-147 — Integração PHP/PDO de classificação da fila técnica

A F1-146 oferece uma reserva sintética da linha de metadados no PostgreSQL 18 usando `FOR UPDATE SKIP LOCKED` e o limite de 72+360 horas. A F1-147 cria um adaptador em PHP que somente pode executar dentro de CLI/CI/testing e transação PDO com role e banco fictícios específicos.

A classe `LabSyntheticOutboxLeaseAssessment` limita os códigos retornáveis e mantém todos os métodos de capacidade **false**. `LEASED_INITIAL_UNVERIFIED` e `LEASED_RECOVERY_UNVERIFIED` nunca autorizam o envio de leads, marcação de entregue, ACK ou retry automático. Falha de conexão, código não reconhecido, falta de transação ou revogação são negados.

Testes PostgreSQL 18 com PHP 8.3 e 8.4 executam o adaptador sobre o laboratório F1-140..146, incluindo ausência de transação, flag desligada, UUID incorreto, separação de tenants, fila vazia após lease, revogação em sessão existente, códigos adulterados e conservação do estado `pending_unverified`.

Não foi implementado nenhum transporte HTTP, worker real, API CRM, ingestão de lead ou dado pessoal. Não usar essas classes em runtime público. OP04 #36 e os dez gates de produção continuam bloqueados; dependem de IAM autenticado, homologação, LGPD, resposta CRM verificável e revisão independente AppSec/DBA.
