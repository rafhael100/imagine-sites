# F1-372 — PostgreSQL valida assinatura de recibo antes de confirmar outbox

**Escopo: fixture CI-only de PostgreSQL 18. Não é uma migration de produção.**

## Motivação

A F1-371 já registra o recibo do Portal fictício atomicamente com o ACK do Sites, porém a função SQL confia que o worker PHP fez a verificação do MAC. Um worker comprometido poderia informar um MAC de 64 caracteres arbitrários e acionar o caminho antigo, mesmo sem um recibo genuíno.

## Reforço aplicado

- A fixture instala `pgcrypto` no PostgreSQL 18 descartável e guarda uma chave **inteiramente fictícia** do receptor Portal numa tabela privada `sites_f360.portal_receipt_verifiers`, acessível somente à função dona do schema. O login worker não pode ler essa tabela.
- A nova função `sites_f360.ack_verified_portal_receipt(unsigned_json, mac)` é `SECURITY DEFINER` com `search_path=pg_catalog`; valida a identidade autenticada `session_user` e o HMAC-SHA256 sobre os bytes JSON originais, além dos campos, do prazo, do contrato de evento ProjectQueued, do SHA-256 e do token de lease.
- Somente depois dessa validação a função atualiza `sites_f360.outbox` para `delivered` e grava os metadados em `portal_receipt_evidence` dentro da **mesma transação**.
- O login worker perde permissão de executar **ambos os caminhos antigos**, `ack_outbox` e `ack_with_portal_receipt`. Caso contrário, a nova verificação seria facilmente contornável.
- O teste cria um pedido fictício novo (Onepage), obtém recibo assinado via HTTP do receptor Portal ligado ao banco restaurado, tenta HMAC falso e conteúdo adulterado diretamente na função SQL e confirma que o outbox continua leased sem evidência. O recibo correto permite um único ACK; replay e duplicação falham. A evidência independente no Sites ainda permite reconhecer que o backup antigo do Portal omite o evento.

## Limitações

As chaves HMAC são públicas e artificiais, armazenadas apenas para CI; a função não é aprovada para IAM operacional. Em produção será necessário usar verificadores com gestão e rotação de chaves no vault, identidade serviço-serviço, isolamento de permissões, autenticação mTLS/TLS, revisão AppSec (inclusive comportamento de comparação em tempo constante), sigilo de registros, reconciliadores, backup externo e controle de replays do receptor. Um verificador SQL não prova a legitimidade comercial de contrato nem o estado de pagamento.

O foco é **não permitir que um simples login worker de banco forje um recibo** no experimento. Os testes só utilizam bancos descartáveis e rede loopback do GitHub Actions; nenhum cliente real, VPS, Cloudflare, DNS ou sistema produtivo foi alterado.

**DRAFT/NO-GO** até revisão independente e homologação com serviços autorizados.
