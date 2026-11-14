# F1-371 — Evidência de recibo Portal gravada atomicamente no banco Sites

**Estado:** componente restrito ao PostgreSQL18 descartável do GitHub Actions. Não é migration produtiva, webhook real, autenticação S2S ou autorização de checkout.

## Por que esta etapa importa

Na F1-370 uma auditoria encontrou um backup antigo do Portal porque havia uma referência de laboratório mais recente. Em produção, depender de outra cópia do próprio Portal para saber quais entregas devem existir pode ser insuficiente se ambas as cópias estiverem desatualizadas.

A F1-371 demonstra uma alternativa parcial: persistir no **banco do Sites**, de maneira atômica com a confirmação do outbox, os metadados de um recibo assinado pelo receptor Portal. Esse registro é uma evidência em domínio de banco diferente, permitindo identificar recibos ausentes em uma restauração do Portal sem consultar a versão mais recente daquele banco.

## Prova técnica

A fixture `tests/fixtures/f371-sites-portal-receipt-evidence.sql` cria uma tabela protegida `sites_f360.portal_receipt_evidence` vinculada ao registro de outbox. Só o login worker fictício pode executar `sites_f360.ack_with_portal_receipt`; não possui SELECT/INSERT/UPDATE direto na tabela. A função recebe a identidade do evento, o lease UUID, a assinatura MAC em hex, a disposição `stored` ou `already_recorded` e o instante de emissão, valida formato/tempo/role e executa a alteração de estado e a gravação da evidência em **uma mesma transação PostgreSQL**.

O worker PHP de teste utiliza o receptor HTTP da F1-369 conectado à base Portal fictícia restaurada e valida o HMAC, empresa, operação, SHA-256, lease, versão, disposição e prazo **antes** de executar o ACK com evidência. Casos negativos confirmam que assinaturas adulteradas são recusadas no worker, metadados malformados são bloqueados na função SQL, escrita direta na tabela é proibida e ACK duplicado não cria evidência adicional.

Depois do ACK, o teste lê **somente a tabela Sites de evidência** para determinar quais recibos devem existir. Confirma a presença na base Portal íntegra e detecta a ausência na base restaurada de backup antigo, sem usar uma cópia posterior do Portal para construir essa expectativa.

## Limites e trabalho restante

A função SQL **não** valida o MAC criptograficamente. Essa validação ocorre apenas no trabalhador PHP experimental; se o login worker fosse comprometido, não haveria garantia de prova genuína. Um serviço operacional precisa de identidade S2S real, recibos canônicos verificáveis, chave de verificação protegida e rotacionável, isolamento de roles, auditoria de usuário/serviço, trilha de eventos imutável, retenção e backups independentes, além de estratégias para reconciliação de gerações e watermarks.

A fixture cobre **um evento novo** pelo caminho de evidência; eventos antigos confirmados pelo protótipo `ack_outbox` permanecem sem evidência, portanto não se deve inferir cobertura total. O ambiente continua somente de laboratório, com chaves públicas fictícias, bancos temporários e sem HTTPS, vault, contratos reais, LGPD, VPS, Cloudflare, DNS ou promoção de PRs. **DRAFT/NO-GO.**
