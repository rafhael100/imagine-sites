# F1-367 — duas bases PostgreSQL18 descartáveis, recibo HTTP persistido antes do ACK

**Fase de laboratório CI:** não é implantação, autorização de pagamento ou entrega oficial no Imagine Portal.

## Arquitetura ensaiada

O GitHub Actions inicia dois serviços PostgreSQL18 distintos dentro do runner:

- Sites: porta 5432, banco fictício sites_f360_lab com admissão, outbox, controle de nonce e role de trabalhador definidos nas F1-360..364
- Portal fictício: porta 5433, outro contêiner PostgreSQL18 com banco portal_f367_lab e tabela portal_f367.receipts, exclusiva deste teste

O worker com acesso restrito ao outbox Sites retira um evento usando SKIP LOCKED e lease UUID. Ele assina o conteúdo do evento com chave HMAC fictícia e envia HTTP a 127.0.0.1:18968. O receptor, em processo PHP separado, valida o envelope, grava o registro na base Portal e executa COMMIT. **Só depois** responde com um recibo HMAC diferente; o worker confere o token de lease e digest antes de confirmar o outbox no primeiro banco.

O receptor conserva a unicidade da combinação empresa/operação. Reenvio idêntico retorna recibo já registrado sem segunda gravação; conteúdo divergente sob a mesma chave é rejeitado. Isso exercita a coordenação idempotente entre dois bancos, sem alegar transação distribuída.

## Testes negativos e recuperação

1. A inserção no Portal ocorre em transação, seguida de falha artificial antes do COMMIT. O banco Portal reverte a inserção e Sites mantém a entrega pendente
2. O lease expira; um novo token permite reentrega, gravação e recibo válido
3. Recibo da tentativa nova não autoriza ACK com token anterior
4. Evento duplicado assinado não cria segundo recibo persistente; digest conflitante é recusado em HTTP 409
5. Um segundo evento é persistido e confirmado; contagens independentes dos bancos comprovam dois recebimentos persistidos e dois ACKs adicionais
6. Registros de admissão do Sites continuam sem eventos órfãos

## Limites explícitos

Não existe autenticação serviço-serviço de homologação, gateway real do Portal, banco com retenção/backup operacional, vault, TLS, domínio, mensageria operacional, restauração de duas bases, política de prazo e auditoria LGPD, contrato/pagamento oficial, aceite do titular nem publicação. A persistência entre contêineres descartáveis comprova apenas que o código pode registrar e confirmar eventos em duas bases isoladas no mesmo runner CI. **Não comprova independência de recuperação de desastres nem disponibilização de um serviço.**

Todos os dados/chaves/senhas usados são fictícios. A execução exige APP_ENV testing, GITHUB_ACTIONS true, SITES_F367_PORTAL_DB_LAB=1 e acesso local aos bancos e portas definidos. A fonte do Portal real ainda é candidata/pinada. Os PRs #444–#446 permanecem DRAFT/NO-GO, sem merge, deploy, VPS, Cloudflare, DNS ou custos adicionais.
