# F1-378 — Código real do Portal assina a requisição HTTP de admissão do Sites

**Escopo:** prova de compatibilidade entre repositórios, com contrato e chaves fictícios e apenas PostgreSQL18 descartável. Não é checkout, aprovação comercial nem API operacional.

A F1-377 completou o caminho HTTP cliente→Nginx→Laravel→PostgreSQL utilizando o assinador da biblioteca Sites. Para evitar que duas implementações Sites concordem entre si, a F1-378 usa o **código do Portal** de um commit fixo e verificável: `rafhael100/imagine-portal@bf1b2794de4d6000f120ffa2b1e6dd557f34c35e`.

Os arquivos do Portal `SiteContractSnapshot`, `PortalSiteContractSource`, `SitesCreateProjectEncoder` e `SyntheticSitesEnvelopeSigner` são carregados diretamente do checkout isolado `portal-candidate` do GitHub Actions. O `PortalSiteContractSource` concreto permanece um **stub sintético** criado na suíte, que emite um contrato fictício Onepage ou Landing Page. Os `tenantRef`, `orderRef`, `contractRef`, revisão, chave de idempotência e envelope canônico são serializados **exatamente pelo código do Portal**, sem reconversão manual para o formato do Sites. A assinatura usa chave HMAC publicamente fictícia.

O cliente HTTP resultante envia o pacote para o receptor PHP-FPM do Sites, que verifica HMAC, executa a função PostgreSQL de nonce e admissão, e persiste projeto e outbox numa transação. O teste comprova:
- Onepage/Landing Page: HTTP 202 `queued` por operação
- Mesmo envelope: replay 403; novo nonce idêntico: HTTP 200 `duplicate_ignored`
- Tenant de outro login SQL: 403 apesar de assinatura verdadeira
- Revisão contratual fictícia não autorizada: 403
- Chave HMAC errada: 403
- Fonte Portal sem contrato: o próprio `SitesCreateProjectEncoder` recusa construir a mensagem
- Contagem PostgreSQL em conexão administrativa separada: dois projetos, dois eventos outbox e quatro nonces novos `f378`, sem operações para outra empresa

Essa validação não utiliza a interface real do Portal, servidor Laravel Portal, gateway de pagamento, NF, banco de contratos, IAM S2S, identidade de clientes, chaves protegidas ou revogação externa. Uma fonte documental artificial **não prova** que o cliente comprou ou pagou o serviço. A fonte operacional do Portal continua bloqueada e deve ser implementada com verificação de elegibilidade independente, revisão de AppSec e testes de contingência.

O workflow também mantém os testes completos da F1-377, com a rota HTTP somente em GitHub Actions e Nginx limitado a 127.0.0.1. **DRAFT/NO-GO**, sem merge ou deploy em VPS/Cloudflare/DNS e sem dados reais.
