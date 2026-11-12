# F1-168 — Composição transacional do pré-voo CRM (somente laboratório)

A F1-165 gera o envelope técnico de teste, a F1-166 confere se duas leituras de uma fonte injetada apresentam vínculo e revisão coerentes, e a F1-167 registra uma tentativa de nonce de serviço globalmente única no PostgreSQL. A F1-168 une essas três etapas num único fluxo PHP LabSyntheticCrmRequestCoordinator::prepareWithinTransaction.

A classe recebe uma conexão PDO já em transação, um envelope contendo **apenas UUIDs de metadados**, operação deliver/lookup, janela de tempo e nonce. Ela exige todas as flags de CI (F165, F166, F167 e F168) antes de qualquer passo. Sem transação retorna TRANSACTION_REQUIRED. Se a fonte injetada negar o vínculo, variar revisão, for revogada ou se a janela/nonce forem inválidos, retorna BINDING_OR_WIRE_UNVERIFIED sem tocar o ledger. O wire construído em F165 usa somente HMAC com chave pública fictícia, nunca segredo real.

Se o pré-voo sintético for coerente, a classe pede ao banco a reserva transacional do nonce com TTL igual à janela de assinatura. Reuso global resulta NONCE_REPLAY_DENIED; incompatibilidade entre login do banco e organização resulta TENANT_LOGIN_SCOPE_DENIED. Somente NONCE_RESERVED_UNVERIFIED devolve um objeto LAB_PENDING_TRANSACTION_UNTRUSTED, deixando EXPLÍCITO que a transação ainda pode sofrer rollback. Todas as capacidades de autenticação, aceite, envio, confirmação, retry e marcação de entregue permanecem false.

O teste F1-168 usa PHP8.3/8.4 e PostgreSQL18 descartável com duas contas de empresa. Prova separação de transações e sessão administrativa, commit e rollback, reuso após confirmação, mudança de revisão, revogação, operação/janela inválida, negação de troca de tenant e bloqueio global entre dois logins.

A implementação **não** constitui a integração operacional do CRM: não existe transporte TLS, credencial real, IAM, autenticação independente do Portal, duas captações fisicamente independentes, PII, consentimento ou evento oficial de recebimento. OP04 #36 e 10/10 gates seguem bloqueados, sem merge, deploy, VPS ou gastos.
