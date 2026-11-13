# F1-360 — admissão transacional e outbox no PostgreSQL 18

**Estado:** candidato P0 para revisão DBA/AppSec; SOMENTE banco efêmero de CI. Não é migration de produção, checkout, integração HTTP nem go-live.

## Motivação e desenho

O fluxo de pedidos do Portal para Sites desenvolvido nos PRs #443 e #359 ainda utiliza idempotência em memória. Esta entrega utiliza PostgreSQL 18 real, em laboratório descartável, para testar persistência com **uma admissão e um evento ProjectQueued na mesma transação**. A operação não pode deixar uma admissão órfã quando falhar a gravação do evento. O índice único (tenant_ref, operation_ref) deduplica as tentativas e uma comparação integral de site, contrato, produto, revisão e digest recusa conflitos, mesmo quando o cliente reutiliza o mesmo hash informado.

## Isolamento

O script SQL define uma role proprietária NOLOGIN, duas roles de aplicação fictícias sem privilégios sobre tabelas e um login sem acesso à base. Apenas a função de admissão com SECURITY DEFINER, caminho de busca fixado em pg_catalog e vínculo privado por session_user pode gravar. A função verifica a revisão ativa, bloqueia autorizações revogadas e ignora variáveis GUC arbitrárias.

**Advertência:** o login SQL não autentica um cliente do Portal; a função ainda não valida assinatura S2S, pagamento, titularidade e autorização real. A tabela de revogação local não é uma autoridade fora do domínio do restore; não satisfaz o gate de revogação independente.

## Ensaios

O workflow sites-f360-pg-durable-admission.yml provisiona exclusivamente o PostgreSQL 18 de CI, nas matrizes PHP 8.3 e 8.4, com dados e credenciais inteiramente fictícios. O teste PHP recusa execução quando APP_ENV, GITHUB_ACTIONS, flag laboratorial, host, porta, base ou usuário não correspondem à configuração descartável esperada. Provas: privilégio mínimo, separação por empresa, deduplicação, conflito, GUC forjada, revisão obsoleta, erro injetado na escrita do outbox com rollback integral, rollback explícito, travamento de revogação durante transação e contagem final de admissões/outbox sem registros órfãos.

A restrição de CHECK que simula falha de outbox existe **somente para testes**. O mesmo vale para logins, senhas e SQL da fixture.

## Limites e próximos gates

Sem endpoint HTTP, migration operacional, publisher, dispatcher, reconciliador, banco de homologação permanente, backup/restore, Cloudflare, VPS, dados de cliente ou pagamentos. Integrar futuramente o comando autenticado da F1-356/359 a uma admissão atômica usando identidades S2S reais e fonte de revogação externa confiável requer avaliação específica. Os gates de readiness continuam false e os PRs #442/#443/novo F1-360 permanecem DRAFT/NO-GO. Não alterar main ou produzir efeitos em infraestrutura.
