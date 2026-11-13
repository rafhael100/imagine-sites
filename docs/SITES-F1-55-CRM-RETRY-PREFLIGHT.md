# F1-55 — Pré-checagem de reenvio de lead com vínculo independente Sites + CRM

Esta etapa avança o requisito de leads sem manusear dados pessoais. `SyntheticLeadReceipt` representa metadados de um recibo **fictício** que deveriam vir do banco com T0 imutável (F1-25), empresa/site/submissão, vínculo CRM/revisão, digest do payload e estado.

`SyntheticCrmLeadRetryPreflight` exige confirmação de vínculo ATUAL por **duas fontes diferentes** (`LeadBindingAuthoritySource`), uma do Sites e outra do CRM, com revisão, empresa, site e destino CRM idênticos ao recibo original. Sem as duas fontes o padrão é **DENY**. A regra impede reenvio após recibo entregue/expirado, antes de T0, no exato marco de 432h, para destino trocado, binding revogado/desatualizado ou indisponibilidade de qualquer API. Usa `LeadDeadline` existente: 72h iniciais + recuperação até 432h *sem alterar T0*.

O método só retorna `PolicyDecision` abstrata. Mesmo `allowed=true` significa apenas que uma fixture passou por pré-checagem local, **não** autoriza enviar contatos. Não há payload, e-mail, telefone, fila, API, worker, RLS, confirmação CRM, backup, chave ou transporte. Uma futura implementação deve autenticar cada serviço, consultar snapshots realmente independentes, executar idempotência durável e confirmação do CRM, exigir consentimento/avisos quando aplicáveis e respeitar exclusão nos backups.

Testes cobrem duas fontes, tempo, revogação, trocas de destino, versões diferentes, timeout e fingerprint de idempotência derivado só de metadados fictícios.

Gates OP04, P0/RF-PEND/RV-CT, LGPD/AppSec, Cloudflare/VPS e readiness BLOCKED. PR draft sobre F1-54 (#75), nenhum deploy, identidade real, publicação ou gasto.
