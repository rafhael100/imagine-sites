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


## F1-362 — paridade do comando assinado com a admissão transacional

O argumento esperado de empresa foi adicionado à função SQL de admissão: a empresa vinda do comando assinado deve coincidir com a empresa vinculada ao session_user do PostgreSQL. Um pedido assinado válido para outra empresa não pode ser admitido pela conexão SQL atual. Essa verificação é adicional às assinaturas e evita o risco de misatribuição de projetos entre contas.

Um teste CI separado usa código candidato real e pinado de dois repositórios, ambos apenas leitura:
- Imagine Portal, encoder e assinador de CreateProject em bf1b2794de4d6000f120ffa2b1e6dd557f34c35e
- Imagine Sites, verificadores de assinatura e revogação do PR #443 em 21732e9558b730e38a6845adb593d29f309ff6a9

O fixture gera pedidos Onepage e Landing Page usando o encoder candidato Portal, valida o envelope HMAC e duas atestações de autoridade fictícias, e então grava a operação por um login SQL de laboratório da empresa B. Testa tentativas duplicadas com novos nonces, revogação assinada, manipulação de tenant e rejeição por PostgreSQL quando a assinatura autoriza A mas a conexão SQL pertence a B. O resultado esperado são dois novos registros e seus eventos outbox em uma única transação cada.

**Ainda não é integração operacional:** Portal não envia HTTP, autoridade externa e pagamento são simulados, chaves são públicas e somente fictícias, e os dados não persistem fora do job efêmero. O teste é uma compatibilidade de três componentes reais de código, mas não comprova que serviços implantados confiam nas fontes certas. CI específica será registrada somente após execução comprovada.


## F1-363 — ledger de nonce persistente e atômico

Um ledger SQL adicional, restrito à base descartável, guarda uma chave global (issuer, nonce), empresa de origem e prazo de validade. A função remember_nonce valida a role SQL autenticada, vínculo de tenant, emissor autorizado e TTL máximo de 120 segundos. A unicidade do par issuer/nonce bloqueia replay mesmo entre conexões e processos PHP diferentes.

O teste F1-363 utiliza as classes assinadas candidatas e públicas do PR #443/Portal para criar uma admissão **dentro da mesma transação PostgreSQL** da reserva do nonce e gravação do outbox. Uma falha do outbox reverte também o nonce, permitindo repetir a operação após rollback; uma tentativa idêntica após commit é negada. Uma nova assinatura com nonce novo e mesma chave idempotente retorna duplicate_ignored e não cria outro evento.

O ledger **não** deve ser considerado IAM operacional. Os tempos do teste são baseados no relógio do CI, chaves são fictícias, nenhuma chave KMS/vault ou TLS foi usada, e a fonte de revogação permanece no mesmo domínio de restauração. O TTL não concede reaproveitamento de nonce depois do vencimento: a chave permanece única até existir uma política de limpeza segura e auditada, ainda pendente. A role de aplicação sem autoridade oficial não deve receber essa função em produção.


## F1-364 — adaptador PHP transacional reutilizável, CI-only

O protótipo LabTransactionalSignedIngressV1 em src/Persistence extrai das fixtures o fluxo de aplicação: lê e verifica três provas criptográficas fictícias (pedido assinado, autorização Portal e fonte externa simulada), reserva o nonce SQL no PostgreSQL com vínculo de tenant e grava admissão+outbox **na mesma transação**. Em caso de qualquer exceção, executa rollback e não admite meia operação. O status retornado é apenas queued ou duplicate_ignored, sempre publishable=false.

O adaptador recusa execução fora do CLI de CI com ambiente e banco específicos, além de verificar a identidade efetiva da conexão SQL. Recusa transações já abertas e chaves/autoridades inválidas. O teste F1-364 usa as classes candidatas pinadas do Portal e do PR #443, verificando Onepage/Landing, retry com nonce novo, replay de nonce, conflito entre empresa da assinatura e do login SQL, assinatura adulterada, ausência do flag de laboratório, transação aninhada e erro proposital de outbox com rollback do nonce.

**Limite de implementação:** o adaptador usa credenciais fictícias e é inacessível fora do CI. Ele não é API Laravel em produção nem confirma checkout, titularidade, consentimento, IAM externo, backup/restore, dispatcher S2S ou publicação. A validação completa do componente no CI deve ser registrada antes de classificá-lo como aprovado.
