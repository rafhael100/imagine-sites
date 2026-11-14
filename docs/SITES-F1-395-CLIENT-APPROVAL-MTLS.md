# F1-395 — Consulta de aceite do cliente via HTTPS mTLS autenticado

**Escopo:** candidato de transporte do Imagine Sites para comprovante atual de consentimento do cliente emitido pelo Imagine Portal. Não autoriza publicação. Sem endpoint público, provider Laravel, credenciais reais ou chamadas à Cloudflare.

## Fluxo validado em CI

1. O código original do [Portal F1-394, PR #749](https://github.com/rafhael100/imagine-portal/pull/749), congelado em SHA `10fc419e73236fc8c42acb0eb361114c8e67d03b`, emite um recibo Ed25519 **fictício** a partir de uma fonte de consentimento com MFA **simulado** e aceite expresso sintético.
2. Certificados e CA efêmeros são criados no job, sem chave operacional; servidor Python em `127.0.0.1:18444` exige certificado de cliente assinado pela CA do runner.
3. `PortalClientApprovalMtlsHttpSource` faz POST exclusivamente para `/internal/sites/v1/approvals/resolve`, com schema `sites.portal-client-approval-fetch.v1` e empresa/site/release/bytes SHA-256/revisão vinculados ao `ReleaseManifest`; não aceita URL ou identidade oriundas do HTTP do cliente.
4. A classe original `SignedPortalClientApprovalGate` (F1-391, PR #473) valida o recibo Ed25519 obtido pelo transporte e exige `IndependentClientApprovalWitness` atual a cada chamada. Comprovante assinado e transportado via mTLS não basta: revogação, nova revisão, domínio e critérios comerciais continuam gates distintos.

## Garantias de rede

- HTTPS obrigatório, host previsto, caminho e porta fixos; fora de CI apenas nome DNS no 443. Sem URL com query, credenciais ou fragmento.
- Cadeia CA e hostname verificados; certificado + chave privada do serviço com caminhos fornecidos por operador e sem symlinks; TLS mínimo 1.2.
- Sem redirecionamentos nem proxy, timeout 500–5000 ms e body de resposta limitado a 4096 bytes. HTTP distinto de 200, JSON inválido, media type diferente de application/json ou corpo excessivo retornam **ausência de prova**, jamais autorização.
- Nenhum identificador, segredo, conteúdo do recibo ou dado do cliente é lançado em logs pelo adaptador.

## Casos negativos automatizados

Divergência de empresa, release, digest e revisão; consentimento revogado/fonte independente indisponível; resposta adulterada, redirecionamento, 503, corpo grande ou HTML, tipo de conteúdo errado; CA do servidor ou certificado de cliente não confiável; URL insegura ou porta fora da permitida; timeout inconsistente; link simbólico para chave privada e uso local fora do gate de CI.

**Limites importantes:** o servidor do CI é uma simulação, não o endpoint Laravel do Portal; o próprio emissor Portal F1-394 ainda tem fonte de aceite `DenyingPortalCurrentClientAcceptanceSource` por padrão e nenhum IAM operacional. A testemunha de revogação é fictícia e não é uma fonte independente implantada. Também faltam a reconciliação comercial/Asaas, autorização de domínio, infraestrutura de backup/restore, segregação IAM, aprovação AppSec/LGPD, publisher e rollback observável. Não houve DNS, VPS, cliente, pagamento ou site de produção.

**PR DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** A liberação exige revisão independente dos gates e homologação completa. Não declarar aceite real nem publicação.
