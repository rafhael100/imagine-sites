# F1-132 — Paridade da idempotência e das evidências de entrega Sites↔CRM

## Objetivo

A F1-131 validou os oito metadados do envelope sintético de lead contra o código real de inspeção da F3-11 do CRM. A F1-132 usa os mesmos metadados e a **mesma revisão pública fixada** do CRM, `a2f29c8e19185e3451fe953f6fe9c9c9bcbbe3ef` (CRM [PR #49](https://github.com/rafhael100/imagine-crm/pull/49)), para testar a compatibilidade com os contratos F3-12 de idempotência e F3-07 de evidências de entrega, sem criar envio ou ACK.

## Código Sites

A classe `SyntheticCrmLeadLedgerIntentV1` recebe um `SyntheticCrmLeadEnvelopeV1Draft` imutável e expõe:

- `sameLogicalScopeAs`: compara **organização + submissão**, nunca conteúdo de contato ou rota de ingresso.
- `samePinnedContextAs`: além do escopo lógico, exige o mesmo vínculo, site, formulário e **destino CRM original**.
- `syntheticLedgerFingerprint`: um SHA-256 determinístico e **não secreto**, baseado apenas em prefixo técnico + organização + submissão, para comparação contra a classe independente `SyntheticIdempotencyClaim` do CRM.
- `canCommitReservation`, `canAcknowledgeVisitor` e `canDeliverToCrm`: sempre `false`.

O hash não autentica organização, não reserva submissão, não faz deduplicação entre processos e nunca deve ser tratado como token ou segredo.

## Teste cruzado real

O workflow `sites-f1-131-crm-lead-parity.yml` executa, além da F1-131, `tests/f132-crm-ledger-evidence-cross-repo.php`, em PHP 8.3 e 8.4, obtendo a classe F3-12 e F3-07 do código real do CRM. A matriz prova:

1. O fingerprint calculado **independentemente** pelo Sites coincide com o da `SyntheticIdempotencyClaim` do CRM.
2. Nova tentativa com a mesma organização e submissão, mas vínculo/site/formulário/destino CRM diferentes, é **conflito**, e não uma nova entrega. Mudar organização ou submissão é outro escopo lógico.
3. Duas classificações concorrentes com o mesmo snapshot `Open` devolvem `ATOMIC_STORE_REQUIRED` e a mesma sugestão de versão, mas **nenhuma possui permissão de reservar**. Somente uma operação CAS transacional em armazenamento durável e compartilhado poderia escolher um vencedor.
4. Versão antiga, negativa, overflow, operações manuais não suportadas, estado reservado, resultado desconhecido ou conclusão apenas alegada não permitem retry/reserva.
5. Até os relatos `201/201` dos dois recebedores, com UUID de registro no CRM, continuam `TRUSTED_EVIDENCE_REQUIRED`; sem prova externa de persistência não podem confirmar ao visitante, marcar entregue ou fazer retry automático.
6. Resposta incerta, erro, `202`, ausência de ID e vínculo divergente sempre negam a conclusão; avaliações não divulgam os identificadores originais.

## Limites operacionais

**Este PR não entrega nem persiste leads.** O contrato CRM F3-12 é classificador **sem side effects**: não existe CAS real, base compartilhada/atomicidade entre duas captações, recibo durável, autenticação de vínculo, ACK de CRM, integração HTTP ou autorização de envio. O fingerprint sintético também não equivale ao `deliveryFingerprint` da F1-55 (que tem outro escopo e um digest do payload); um protocolo real precisa negociar essa diferença antes da integração.

A OP04 permanece P0: implementação operacional de duas origens fisicamente independentes, armazenamento e relógio T0 confiáveis, prazo imutável de 432h, autorização e idempotência global, prova de confirmação no CRM, LGPD (minimização/criptografia/retenção/descarte), auditoria e revisão SEG/DBA/AppSec. **Não ativar formulário ou dados pessoais**, mesmo com CI aprovado.

PR draft empilhado sobre F1-131; sem merge em dev/main, deploy, DNS, VPS, Cloudflare ou novas despesas. **10/10 gates de produção BLOCKED.**
