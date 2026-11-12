# F1-146 — Reserva concorrente de intenção técnica, sem worker real

A F1-145 escreveu o registro técnico e seu outbox na mesma transação. Esta etapa amplia a fixture PostgreSQL 18 com um bloqueio curto (`FOR UPDATE SKIP LOCKED`) para que duas sessões da mesma empresa não reservem simultaneamente **a mesma linha de metadados**.

A função `try_lease_synthetic_outbox` valida o `session_user` contra o mapeamento ativo e revisionado do F1-143, bloqueia a entrada técnica e grava apenas identificador de lease, vencimento de lease e contador. O retorno é diagnóstico: `LEASED_INITIAL_UNVERIFIED` para metadados com menos de 72 horas, `LEASED_RECOVERY_UNVERIFIED` para 72h–432h e `NO_READY_SYNTHETIC_INTENT` quando não há intenção elegível. Além de 432h, nunca concede nova reserva. A fixture usa o relógio PostgreSQL, não horário informado pelo usuário.

## Evidência

Testes com PostgreSQL18/PHP 8.3 e 8.4, duas sessões concorrentes, login A/B distintos, tentativa cruzada, revogação do login, lease repetido e classificação dos limites de idade. Para acelerar o teste dos prazos, **apenas a conexão administradora do banco descartável** simula passagem do tempo alterando o `created_at` da fixture; isto não é uma autorização para alterar T0 operacional. O aplicativo não recebe UPDATE direto nem expõe dados.

## Limites

Não há fila real de transporte, worker, confirmação CRM, conteúdo de lead, consentimento, autenticação Portal, entrega, retry automático ou evento de conclusão. Um lease representa somente intenção técnica de laboratório e não autoriza nenhum efeito externo. A política comercial de 72h iniciais + 360h de recuperação é demonstrada como classificação e limite total; a configuração final de lease e agendamento exige revisão de arquitetura. Nada publicado; OP04 #36 e os dez gates seguem BLOCKED. Revisão SEG/DBA/AppSec independente, infraestrutura de homologação e IAM real continuam necessários.
