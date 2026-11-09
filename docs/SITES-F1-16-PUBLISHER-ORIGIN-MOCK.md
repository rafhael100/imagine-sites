# SITES-F1-16 — Publicação simulada, ACK e observação independente do artefato

**Somente laboratório PHP.** Não há Cloudflare, Workers, DNS, internet, deploy, segredo, clientes nem publisher real.

- `SyntheticPublishCoordinator`: constrói uma operação técnica que passa por preflight, dispatch e **ACK fictício de provedor**. Isso não equivale a versão publicada.
- `SyntheticOriginRegistry`: simula um origin separado e armazena bytes de HTML apenas em memória da suíte. O probe calcula SHA-256 dos bytes encontrados (não confia no digest alegado pelo manifesto) e produz uma `Observation` fictícia.
- `PublicationOperation` de F1-09 é a única autoridade técnica sobre estados de tentativa neste laboratório; só digest e release exatos com flag de autorização sintética autorizam `verified`.
- Testes negativos: preflight bloqueado, ACK sem origin, alteração dos bytes servidos, release incorreta, outro tenant, autorização revogada, reenvio de prova e preservação da versão anteriormente observada sem rollback.

**Não é prova independente operacional.** O origin é um registro em memória manipulável pela fixture; o campo `verifiedByIndependentObserver` da `Observation` ainda não é assinatura, auditoria, inspeção externa nem prova de entrega no CDN. A integração real deverá verificar conteúdo/headers/certificado/hostname com leitor independente autorizado, observar exatamente a revisão aprovada, reconciliar falhas e só então emitir parecer técnico.

Entrega formal **ENTREGUE** continua exclusiva do Portal, com aceite expresso do cliente e checklist. AGI-11-RB01 continua DESABILITADO.

Gates P0-DOC01..14, RF-PEND-01/RF-PEND-04, RV-CT, LGPD, custos/hostnames e AppSec continuam abertos. PR draft sem merge/deploy automático.
