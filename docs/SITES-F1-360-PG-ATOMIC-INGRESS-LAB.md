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


## F1-361 — trabalhador de outbox com lease e confirmação (laboratório)

Foi acrescentada uma segunda fixture SQL, executada apenas **depois** da F1-360, na mesma base descartável. Um login fictício separado (sites_f361_worker) recebe somente USAGE no schema e EXECUTE nas duas funções de worker. Ele não pode consultar ou alterar diretamente a tabela outbox, emitir novas admissões ou assumir roles de clientes.

A função claim_next_outbox utiliza seleção FOR UPDATE SKIP LOCKED para retirar um único evento elegível por vez, gravando um token UUID de lease, validade de 1 a 60 segundos e contador de tentativas. Expiração permite novo claim com token distinto; eventos entregues ficam excluídos da seleção. A função ack_outbox só altera leased para delivered quando o token, a empresa, a operação e o prazo corrente correspondem.

O teste f361-pg-outbox-worker.php comprova dois workers independentes, segundo worker pulando um evento bloqueado, ACK exato, recusa de ACK duplicado, token obsoleto após reaquisição, privilégio mínimo, rejeição de lease inválido e drenagem de cinco eventos sintéticos (seis tentativas totais por uma reentrega). A expiração é antecipada **apenas pela conexão administradora da fixture** para evitar sleeps no CI.

**Limite determinante:** um ACK de banco **não comprova entrega externa** no Portal/CRM, nem possui comprovante assinado ou reconciliador. Este não é um dispatcher de produção. A prova de workers não cria endereços de entrega, rede, retries com backoff operacional, DLQ, monitoramento de custos, controles de LGPD ou SLA. A role e os tokens são inteiramente fictícios; não aplicar esse SQL sem desenho de identidade, observabilidade, restauração e revisão AppSec.
