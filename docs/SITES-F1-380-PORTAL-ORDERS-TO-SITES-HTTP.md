# F1-380 — Pedido PostgreSQL do Portal atravessa HTTP até o PostgreSQL independente do Sites

**Prova funcional de integração entre dois bancos PostgreSQL 18 descartáveis, ainda SEM contratação ou pagamento reais.**

## Avanço sobre a F1-378

A F1-378 usava as classes de assinatura do Portal, mas a origem do contrato era um stub que devolvia uma referência fictícia fixa. Agora o workflow utiliza também a implementação real do Portal para **persistência de pedidos sintéticos**: `PersistedTestPurchase`, `RoleBoundTestPurchase` e o esquema `purchase_rehearsal.orders` com FORCE ROW LEVEL SECURITY, ambos pinados ao commit `516dc99c24961e58ec769464c9dd862bf7ac8eda` do Portal. O emissor e assinador continuam pinados, separadamente, ao commit `bf1b2794de4d6000f120ffa2b1e6dd557f34c35e`.

GitHub Actions inicializa **dois bancos independentes** no mesmo runner isolado: Portal `portal_purchase_rehearsal` na porta local do runner, e Sites `sites_f360_lab` somente na rede privada do Compose. O usuário Portal `purchase_rehearsal_a` tem acesso apenas à empresa fictícia `test-org-a`; a conta Sites `sites_f360_b` está vinculada a `tenant-f360-b`. As permissões do banco são inspecionadas pelo código original do Portal, não deduzidas do nome informado pelo cliente.

O cliente de CI cria pedidos Onepage e Landing Page reais **na tabela PostgreSQL de testes**, aplica estado de pagamento **fictício** `approved`, salva um briefing sintético persistente e relê a linha pela fonte `F380RoleBoundPortalOrderSource`. Ela só produz `SiteContractSnapshot` após conferir produto elegível, pedido visível ao papel SQL autenticado, estado sintético aprovado, campos mínimos do briefing e tenant obrigatório. O `SitesCreateProjectEncoder` e `SyntheticSitesEnvelopeSigner` originais do Portal geram o comando HMAC para HTTP.

A requisição percorre Nginx local → PHP-FPM Laravel → transação PostgreSQL 18 do Sites (nonce, admissão e outbox), com a mesma verificação do HMAC, binding da empresa SQL e idempotência demonstrados nas F1-375–378. O receptor ainda usa um gateway de autoridade **fictício, habilitado exclusivamente no CI**, que reconhece o formato dessas ordens; **não há consulta real a PSP, autorização financeira, autoridade externa de revogação, nem backend Portal operacional de IAM.**

## Verificações

- Dois pedidos fictícios completos, Onepage e Landing, geram exatamente duas admissões + dois outbox; são recuperáveis pelo banco Portal e pelo banco Sites;
- Pending, pagamento fictício aprovado sem briefing e produto Performance são recusados antes da assinatura;
- Uma compra fictícia de outra empresa, mesmo com o mesmo nome de negócio, é invisível à role SQL do Portal da empresa A devido a FORCE RLS;
- Repetição do envelope recebe HTTP 403; nova assinatura com nonce diferente produz `duplicate_ignored`;
- Tentativa de alterar tenant de um comando assinado é rejeitada pelo HMAC e não produz admissão;
- O banco Sites é inspecionado por processo separado, confirmando duas operações e quatro nonces F1-380 persistidos;
- Todos os serviços e dados de teste são descartados no fim; não há VPS, DNS ou Cloudflare alterados.

## O que ainda impede clientes

A aprovação de pagamento é um valor de cenário de laboratório, **não pagamento de Pix, cartão ou qualquer gateway**. A origem de pedidos do Portal ainda não é uma autoridade de autorização para produção; o receptor da F1-380 confia em um gateway mock de CI e na chave HMAC de teste. Faltam integração PSP oficial e conciliação, identidade entre serviços com rotação de chaves, revogação independente, reconciliação transacional de contratos/pagamentos, migrações SQL versionadas reais, backup externo/restore, HTTPS público, IAM/MFA operacional, AppSec/LGPD, política de publicação e aprovações. O fluxo comprovado é **técnico e isolado, não uma venda concluída ao cliente**.

**PR DRAFT/NO-GO.** Não utilizar os esquemas de teste, senhas fictícias, flags ou rotas /_ci em produção.
