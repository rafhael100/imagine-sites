# F1-121 — Integração com emissor Portal vinculado à fonte de confiança candidata

## Escopo

A F1-119 verificou a paridade do envelope HMAC v2 entre um emissor Portal que ainda aceitava parâmetros diretos de chave/época e o verificador Imagine Sites. A F1-121 amplia essa prova para o novo emissor Portal F1-13, que não aceita segredo nem revisão do chamador, e consulta uma fonte de material de assinatura **independente e default DENY**.

## Teste entre repositórios

`.github/workflows/sites-f1-23-cross-repo-contract.yml` fixa o checkout do Portal no SHA público `b2bfd8e8c3e5f646764b4b3d91ce34612886d452` ([Portal PR #264](https://github.com/rafhael100/imagine-portal/pull/264)). Executa PHP 8.3 e 8.4, sem Composer, rede operacional, banco, chave privada ou cliente, sobre classes reais dos dois repositórios.

O teste `tests/f23-cross-repo.php`, para Onepage e Landing Page:

1. Usa `SitesCreateProjectEncoder` real do Portal com pedido/contrato fictícios e fonte comercial de laboratório.
2. Constrói `LabPortalSitesVersionedIssuer` com uma fonte sintética separada de material (`CandidatePortalSitesSigningMaterial`).
3. Exige que a saída seja **idêntica** ao envelope HMAC v2 esperado pelo verificador Sites, incluindo todos os campos e a MAC.
4. Verifica o comando recebido no Sites com a revisão corrente e novo `ReplayStore` sintético, sem interpretar HMAC como aprovação comercial.
5. Comprova negação durante indisponibilidade da origem.
6. Simula uma mudança de revisão entre duas consultas do emissor, mantendo a mesma chave, e exige que nenhum envelope seja retornado.
7. Recupera a fonte fictícia com geração mais recente e exige emissão e verificação corretas com novo envelope.
8. Comprova que um emissor Portal sem fonte autorizada continua em default DENY.

## Segurança e limites

A fonte do Portal usada nesta prova é apenas **uma fixture em memória**. Embora o código já não aceite segredo/geração do solicitante, não existe fonte IAM oficial do Portal, vault, certificado de serviço, mTLS/OAuth, canal HTTPS, revisão monotônica operacional, aprovação/aceite de cliente, homologação ou publicação. A leitura dupla não é operação atômica e não cobre revogação posterior à emissão.

O teste é cross-repo com **revisão específica do PR**, não prova a integração da branch atual de produção. Antes de qualquer promoção, precisam ser aprovados o contrato versão v2 entre os projetos, credenciais de homologação, integração com autoridade de recursos/contratos e revisão AppSec independente.

PRs permanecem drafts e empilhados, sem merge em main/dev, dados reais, DNS, VPS, Cloudflare, deploy ou gastos adicionais. **10/10 gates operacionais BLOCKED.**
