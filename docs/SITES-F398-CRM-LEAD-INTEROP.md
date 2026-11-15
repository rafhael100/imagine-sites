# F1-398 — Sites → CRM: vetor executável de captação assinada (laboratório)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-PII / NO-CLIENTE / NO-CHARGE.**

Emite bytes de envelope exclusivamente fictício, com referências de empresa, vínculo, submissão, site, formulário, destino, lead, nonce, origem, emissão e TTL. O domínio e a ordem dos campos reproduzem o método original de canonicalização do CRM LEADS-PG05, sem substituir a autoridade do CRM.

A classe só roda em CLI do GitHub Actions dos repositórios Imagine Sites e CRM com flag de laboratório e chave pública **falsa**. Verifica formato UUID, lista estrita dos 12 campos, mensagens expiradas/futuras, TTL até 300s, conteúdo de título restrito e detecção de qualquer adulteração via HMAC. Não possui mecanismo para HTTP, banco, outbox ou API de provisionamento.

## Próxima prova cruzada

1. Checkout da SHA fixa deste emissor no CI CRM e comparação com o método original LaboratorySignedSiteCapture::canonicalForCi, usando as classes originais LeadSubmissionIdentity/SyntheticIdempotencyClaim.
2. Reexecutar a suíte PostgreSQL16 do CRM com binding interno, revogação transacional, replay/nonce e FORCE RLS, sem alterar grants.
3. O provisionamento real do vínculo empresa/site/formulário/destino deve partir de identidade autenticada, contrato e consentimento válidos e escopo de recurso; nunca confiar em IDs fornecidos por navegador ou no próprio envelope.
4. Homologar na Leaseweb apenas após revisão independente SEG/DBA, IAM, LGPD, TLS/mTLS, chaves no vault, fila, backup e restore; Cloudflare permanece desligado.

**CI verde demonstra paridade de bytes, NÃO demonstra provisionamento real nem fluxo de compra/pagamento operacional.**

Pendências: Sites #38, CRM #27, #259 e #557.