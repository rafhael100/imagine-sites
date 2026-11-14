# F1-381 — Pedido gravado no PostgreSQL do Portal até admissão HTTP do Sites

**Escopo:** quatro componentes reais de infraestrutura de teste, mas contratos, pagamentos e usuários **inteiramente fictícios**. DRAFT/NO-GO. Sem autorização para disponibilização a clientes.

## Diferença importante em relação à F1-380

O comando do Portal já chegava ao Nginx/Laravel e ao PostgreSQL18 do Sites por TLS verificável, mas a fonte de contrato ainda era um objeto sintético construído diretamente em memória.

A F1-381 inclui uma **segunda instância PostgreSQL18**, separada e limitada a `127.0.0.1:5432` do próprio runner GitHub. Seu schema é inicializado pelo **arquivo verdadeiro** `apps/portal/tools/test_persisted_purchase.php` do Portal fixado na SHA `516dc99c24961e58ec769464c9dd862bf7ac8eda`. Esse próprio teste de origem cria a tabela `purchase_rehearsal.orders`, roles PostgreSQL sem superuser, **FORCE ROW LEVEL SECURITY** por empresa e linhas de compra fictícias.

Uma outra classe verdadeira do Portal, `RoleBoundTestPurchase`, é carregada da mesma SHA imutável para criar pedidos adicionais Onepage, Landing Page, Performance e cenários negativos. O acesso SQL é autenticado por `session_user=purchase_rehearsal_a` ou `purchase_rehearsal_b` e nunca pelo tenant informado pelo browser.

Um `PortalSiteContractSource` sintético implementado **somente na suíte de CI** consulta pedidos na role A do Portal e só cria `SiteContractSnapshot` quando o produto é Onepage/Landing, o pagamento **falso** está `approved`, um briefing fictício está salvo e o pedido pertence à empresa A. O encoder/assinador são carregados das classes originais do Portal na SHA `bf1b2794de4d6000f120ffa2b1e6dd557f34c35e`, sem converter manualmente o envelope.

O comando atravessa HTTP Nginx → PHP-FPM Laravel → PostgreSQL18 do Sites, cujo banco é **diferente do banco do Portal** e opera sob TLS `verify-full` e login SQL do Sites com restrição por tenant.

## Negativas e critérios

- Dois pedidos fictícios, pagos e com briefing → dois projetos Sites e dois eventos outbox.
- Mesmo comando reapresentado → nonce recusado; novo nonce → mesmo projeto idempotente sem duplicar.
- Produto Performance, pedido pendente e pedido pago sem briefing → **não geram comando**.
- Pedido totalmente concluído de empresa B, até com o mesmo nome do negócio → invisível ao SQL login A; nenhum comando é assinado.
- Um envelope que falsifica o tenant A, mesmo com HMAC sintético correto, é recusado pelo login SQL Sites B.
- Conferência SQL independente no Sites: dois ingressos `idem-f381`, dois eventos e quatro nonces.
- O preflight Laravel já havia comprovado certificado TLS, identidade PostgreSQL e menor privilégio, mas continua **NO-GO** para negócios reais.
- Nenhuma porta Sites SQL é publicada; o Portal PG está apenas em loopback do runner. Todos os contêineres, CA, chaves e volumes são removidos.

## Restrições de segurança

O estado `payment=approved` é uma atualização **simulada**, não callback verificado de PSP ou Pix. `PortalSiteContractSource` aqui é somente uma consulta de teste, não substitui um serviço oficial assinado e sujeito a revogação/concorrência. O Sites **não comprova autonomamente** que o pedido foi pago: seu `CIHttpPortalPurchaseAuthority` só valida o formato do comando, porque a autorização efetiva ainda não foi implantada. Esse código é registrado apenas com a flag explícita de CI e deve permanecer bloqueado em staging/produção.

Faltam: conexão real de cliente logado e organização, gateway financeiro autorizado, contratos/revisões/aceite oficial, identidade Portal↔Sites no vault, anti-replay e autoridade externa de revogação, migrações versionadas seguras, backups/restore fora do domínio de falha, AppSec/DBA/LGPD e autorização de publicação.

**Não aplicar esse overlay, schema, senhas ou flags na VPS Leaseweb.** PR empilhado DRAFT, sem merge, Cloudflare, DNS, publicação, custo externo ou dados pessoais.
