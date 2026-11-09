# SITES-F1-02 — Adaptadores de autoridade que negam por padrão

**Status:** laboratório sintético, sem integração operacional nem provedores reais. Branch filha de F1-01; PR draft, sem merge/deploy.

## Mudança
- Interface PortalAuthorityGateway recebe manifesto e evidência candidata, devendo devolver PolicyDecision verificado por fonte autoritativa futura.
- DenyingPortalAuthorityGateway é o padrão de qualquer ambiente sem integração homologada: retorna sempre DENY com motivo explícito.
- PublicationPreflightService exige decisão positiva do gateway **antes** de avaliar regras puras de contrato, tenant, digest, aceite, revogação, privacidade e domínio.
- Teste usa um mock **somente no laboratório** para demonstrar o comportamento; um mock permissivo não pode ser utilizado em produção.
- Não são implementadas: autenticação, assinatura criptográfica, MFA, revogação real, RLS, HTTP, Cloudflare, CRM, Agents, n8n ou banco.

## Segurança e limites
Toda chamada sem autoridade confiável deve ser negada. Mesmo uma evidência booleana enviada como verdadeira por fonte não verificada não concede autorização. Quando existir adaptador operacional, ele deverá autenticar a origem, verificar issuer/audience, tenant, site, versão, digest, expiracão, revogação e replay, consultar autoridade oficial e proteger segredo/PII. Isso exige gate de código e homologação antes de qualquer efeito externo.

## Evidências
Laboratório local PHP 8.4.24: 8 checks F1-02 passaram, incluindo negação padrão, impedimento de bypass pelas flags, tenant e digest divergentes, revogação e bloqueador técnico. Testes no GitHub serão conferidos no PR; não declarar PASS remoto sem observá-los.

## Gates
P0-DOC01..14, RF-PEND-01, RF-PEND-04, autenticação/rastreabilidade, captação redundante, budgets e publicação continuam abertos. AGI-11-RB01 permanece desabilitado. Nenhum merge/deploy automático.
