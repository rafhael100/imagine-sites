# F1-45 — Envelope HMAC *sintético* para proposta de revisão Sites → Portal

## Problema atendido

O envelope HMAC existente F1-11/F1-23 identifica comandos **Portal → Sites** de criação de projeto. A direção inversa carecia de um ensaio de transporte protegido contra adulteração, replay e vinculação incorreta de release. Este PR acrescenta somente um contrato de laboratório separado para **propor a revisão**, não para aceitar ou publicar.

`SyntheticReviewHandoffEnvelope::sign` recebe um snapshot F1-28 no estado `AWAITING_PORTAL_ACCEPTANCE`, exige hash SHA-256 correto e gera um envelope ordenado e canônico com emissor `imagine-sites`, destinatário `imagine-portal`, timestamps (validade máxima 120s), nonce e os dez campos exatos do candidato F1-31. O HMAC usa SHA-256 e chave fictícia que **não aparece** na aplicação operacional. As permissões do candidato continuam explicitamente `officialClientAcceptance=false` e `publishable=false`.

`verify` confere bytes canônicos, destinatário, emissor, assinatura, tempo, nonce e comparação com um **snapshot atual independente** da mesma empresa/site/fluxo/revisão/release/digest. Exige ainda duas dependências separadas: `ReviewHandoffAuthorityGateway` para autoridade **atual**, e `ReplayStore` para nonce consumido uma só vez. **Por padrão ambas NEGAM.** Uma identidade revogada ou versão obsoleta falha sem consumir nonce. Um HMAC correto não comprova contrato, pagamento, cliente, permissão comercial ou aceite.

## Testes negativos

PHP 8.3 e 8.4: chave diferente; emissor/destinatário alterados; expiração/futuro; nonce; replay; mudança de empresa, site, release, revisão, SHA-256; rework; revogação; reordenação/tipo de campos; extras de privilégio; tentativa de forjar `publishable` e `officialClientAcceptance` mesmo com HMAC recalculado por fixture. O único caso de sucesso injeta uma autoridade **fictícia** e um replay store em memória, que não são usados em produção.

## Limitações críticas

**Não está autenticando tráfego real.** HMAC aqui é prova de integridade entre dois objetos sintéticos que compartilham uma chave de teste; não é assinatura de aceite, mTLS, IAM, vault, sessão, tenant entitlement, assinatura assimétrica, infraestrutura confiável ou certificado de segurança. Interface de gateway exige futuro adaptador confiável do Portal, identidade de serviço, consultas de autorização por recurso e revogação, replay durável compartilhado, ordenação/concorrência e reconciliação. **Nenhum adaptador é implementado** neste PR.

Sem endpoint, cliente, dados reais, secrets, conexão GitHub–Portal, Cloudflare, VPS Leaseweb, novo custo, publicação ou evento ENTREGUE. `main` inalterada. Gates OP01/OP03/OP05, P0-DOC/RF-PEND/RV-CT, AppSec/LGPD, `config/operational-readiness.json` permanecem BLOCKED; rollback AGI-11-RB01 desabilitado.
