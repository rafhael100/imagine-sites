# OnePage: grant técnico com contexto e epoch (CI isolado, NO-GO)

Data: 20/11/2026. Continuação da PR Sites #575, base `09585fa03529162b29b21d5e1d06d6e8a74f4cd0`.
Sites #541/#542 históricos; Landing Page #569 não é objeto desta PR. Portal #976, #1003/Sites #552 e a interface #486 seguem candidatos não operacionais.

## Lacuna objetiva

No Sites #575, a fonte de alçada devolvia apenas `PolicyDecision(true, [])`.
Trocar a concessão A pela B mantendo os dois booleanos `true` não era detectável.
A alçada também não tinha tenant, site, release, SHA, revisão ou operação técnica vinculados.

## Alteração candidata

- `OnepagePublisherGrantSnapshotCandidate` introduz contexto, ação `promote` ou `rollback`, grantRef, grantEpoch, ator e decisão.
- A classe é um objeto NÃO AUTENTICADO: criar ou editar esse objeto não é autorização para publicar.
- `OnepageReleaseConsentAndPublisherFenceCandidate` passa a exigir o objeto tipado, escopo exato e igualdade de grantRef/epoch antes e depois da revalidação do aceite assinado do comprador.
- A fonte legada que retorna somente booleano/PolicyDecision passa a negar por padrão.
- Testes novos rejeitam tenant alheio, SHA/revisão incompatível, operação incorreta, troca de epoch com dois grants positivos e operação desconhecida.
- Regressões anteriores permanecem. Não repetimos HTTP loopback #574 nem parser/paridade #552.

## Limites e próximo passo

Dois reads não são CAS, lock, transação ou barreira contra ABA. Não há fonte oficial de alçada nem identidade auditada do publicador.
Portal #976 ainda não prova fonte operacional dos bytes imutáveis do briefing, confirmação com declaração de direitos do comprador e aprovação/revogação do revisor.
Faltam autenticação S2S, prova privada HTTP ligada à fonte oficial, epoch transacional, revisão humana e autorização independente.
Prévia, aceite final e publicação são atos distintos. Domínio só é exigido para publicação.

Sites #38 e os dez gates continuam em **NO-GO**. Nenhum merge, deploy, checkout/Asaas, VPS, DNS/Cloudflare, segredo, Replit, fornecedor ou autorização comercial nesta entrega.
