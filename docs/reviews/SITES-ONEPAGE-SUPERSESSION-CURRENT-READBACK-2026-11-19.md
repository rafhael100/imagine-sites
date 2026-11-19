# Onepage: readback de recibo de supersessão, sem autoridade operacional

19/11/2026. Candidata independente construída **sobre a HEAD exata** da PR Sites #548 `8a84d829ca724adcc2cab5fd58126521b14f00de`, depois das #541/#542 e #543–#547. Branch nova; nenhuma branch predecessora alterada.

## Implementação

`StoredOnepageReviewSupersession::assertCurrent()` compara um recibo não confiável ao resultado da composição da supersessão **reexecutada** com as duas revisões do briefing armazenadas no PostgreSQL para o principal atual da empresa. O novo verificador recusa envelopes ausentes, com campos extras, tipos errados, bytes/hashes divergentes (inclusive modificações re-hash), troca de tenant, fonte diferente e reuso da revisão antiga, sem detalhar dados do cliente nas exceções. Reutiliza o renderer e os leitores SQL da cadeia anterior, sem escrita.

Um teste adicional, opt-in `SITES_ONEPAGE_SUPERSESSION_READBACK_CI=1`, exercita casos positivos, replays e negativas no PG18 descartável, sobre os dois snapshots exportados pelo Portal candidato pinado. O CI é exclusivo da PR draft, em PHP 8.3/8.4. Não depende de Cloudflare, VPS, DNS, Asaas ou credenciais reais.

## Limites críticos

Esse *readback* só vincula o recibo às **duas revisões selecionadas**, não atesta qual é a revisão oficialmente mais recente. O Portal ainda não fornece fonte oficial autenticada da aprovação/revisão ao Sites; os recibos e o estado `requires_independent_rereview` são candidatos sintéticos, não assinatura, autorização humana, aceite ou publicação. Persistência de decisão de revisão com autenticação e auditoria independente, transporte privado com CAS, verificação de última revisão, Landing Page, homologação, pagamento e aceite permanecem pendentes.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Manter dez gates operacionais bloqueados. Não publicar nem tratar essas evidências como operação de cliente.