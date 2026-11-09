# SITES-F1-25 — Timestamp seguro e imutabilidade da captação (CI PostgreSQL descartável)

**Problema real identificado na revisão do F1-10:** o schema de laboratório anterior permitia ao criador do recibo fornecer `T0` e `deadline`. Em um backend real, confiar no timestamp vindo do cliente permitiria adiar artificialmente a janela de 432 horas e reenvios indevidos.

**Correção candidata em laboratório:** uma segunda camada SQL `f1-25-server-receipt-fence.sql` força o `T0=clock_timestamp()` do PostgreSQL e `deadline=T0+432 horas`, ignorando relógio e estado inicial alegados pelo remetente. Marca o estado como pendente na recepção, proíbe update de T0, prazo, digest, destino CRM, empresa/site/submissão/revisão, impede reabrir recibo entregue e marcação antecipada como expirado. Também define `attempted_at` no relógio do banco.

**Retenção:** função owner-only `ingress.purge_expired_metadata` do laboratório apaga recibos expirados e seus metadados de tentativas. Um registro histórico fictício é preparado antes de ativar os triggers, para exercitar limpeza real na base descartável. Um novo processo `psql` verifica que o registro antigo desapareceu e o novo permanece. O schema F1-10 original é mantido para testes históricos; esta camada faz parte de **um candidato novo** e não foi executada na VPS.

**Limitações importantes:** o teste usa PostgreSQL 18 único do runner e o schema owner para fixtures. Não existe papel runtime mínimo pronto, API de leads, confirmação CRM, criptografia, payload pessoal, worker de exclusão agendado, controle de backups e restore, legal hold, auditoria durável, idempotência global entre os bancos ou redundância física. O timestamp deve ser autenticado pelo serviço de entrada confiável. Apagar uma linha de teste **não comprova** exclusão em backups/filas/providers.

A alteração não autoriza envio de leads reais, modificação de vínculos CRM ou deploy. IN-127, P0/RF-PEND/RV-CT, AppSec/LGPD e contratos do CRM continuam sob gates. AGI-11-RB01 desabilitado.
