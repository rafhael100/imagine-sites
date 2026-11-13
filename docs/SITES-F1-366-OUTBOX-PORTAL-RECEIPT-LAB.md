# F1-366 — Outbox do Sites → HTTP Portal fictício → recibo → ACK PostgreSQL

**Estado:** experimento de CI sem dados de clientes. O Portal fictício não mantém registros, não valida pagamentos, não concede entrega oficial e não produz eventos reais. Nada foi disponibilizado na VPS, Cloudflare ou domínio público.

## Problema resolvido no laboratório

A F1-361 já demonstrava claim de outbox, lease, expiração e ACK por token com PostgreSQL 18 real. A prova ainda marcava como delivered o evento sem mostrar que algum outro processo havia recebido o conteúdo.

A F1-366 acrescenta um receptor separado na interface **127.0.0.1:18966**, com PHP embutido. Um trabalhador de outbox, autenticado com uma role SQL de menor privilégio, reclama um evento, monta uma mensagem JSON canônica e assina com HMAC SHA-256 fictício. O receptor valida a assinatura de quem envia, conteúdo exato, lease, digest e janela temporal; devolve um recibo assinado com outra chave de laboratório.

O trabalhador confirma o evento no PostgreSQL apenas quando o recibo corresponde à mesma empresa, operação, evento, digest e token do lease vigente. **HTTP 202 isolado não é comprovante suficiente.**

## Ensaios negativos no GitHub Actions

- Receptor indisponível: pedido permanece leased e não é confirmado
- Receptor retorna HTTP 202 com assinatura de recibo adulterada: confirmação negada
- O lease expira, outro trabalhador retoma a mesma mensagem com novo UUID: recibo novo é aceito e recibo antigo não serve
- ACK duplicado ou com token obsoleto é negado pelo próprio banco
- Em outro evento o receptor devolve HTTP 503: o worker não faz ACK; só depois de nova tentativa e recibo correto o banco confirma
- A contagem final prova dois eventos confirmados após quatro claims, nenhuma admissão órfã

O receptor é uma **fixture que não persiste a entrega**, portanto o teste comprova a lógica de verificação de recibos e o estado de outbox entre processos, não a permanência do registro no Portal. Ambos os MACs utilizam senhas longas, públicas e inequivocamente fictícias; não há TLS, mTLS, vault, outbox produtivo, retries com políticas operacionais, reconciliação nem idempotência de destinatário.

## Segurança e gates

A fixture só aceita conexões provenientes de 127.0.0.1 e Host 127.0.0.1:18966 com APP_ENV=testing, GITHUB_ACTIONS=true e flag explícita SITES_F366_PORTAL_RECEIPT_LAB=1. A função SQL de ACK só pode ser chamada pela role fictícia worker. Não se pode transformar essa fixture em rota Laravel ou usar suas chaves em homologação externa.

**DRAFT/NO-GO:** faltam fonte comercial do Portal, autenticação real serviço-serviço, recibo persistente e auditável, monitoramento, prevenção de replay na recepção, reconciliação, failover/restart, segurança de backups, controle de chaves, LGPD, pagamento, aprovação do cliente, publisher e autorização de infraestrutura. Não houve merge, deploy, DNS, cloud ou custo adicional.
