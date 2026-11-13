# F1-277 — Planejamento temporal de reenvio de lead sem reiniciar T0

Foi criado um componente de domínio para calcular **quando uma nova tentativa poderia ser considerada**, assumindo que o recebimento original durável T0 foi verificado externamente.

- Preserva a janela inicial de 72 horas e a janela total de 432 horas de `LeadDeadline`, ambas a partir do mesmo T0 imutável em UTC.
- Usa espera crescente de 5 min, 15 min, 30 min, 1 h, 2 h, 4 h, 8 h, 12 h e 24 h (teto). A espera usa o horário da última tentativa e nunca o instante de consulta, evitando reset incidental.
- Rejeita falhas permanentes, tentativas futuras ou anteriores a T0, histórico ausente, tentativa nova além do vencimento original e consultas a partir de T0+432h.
- Devolve apenas códigos e uma data candidata: `mayDispatchToCrm`, `mayPersistLead`, `mayAcknowledgeVisitor` e `mayRebaseOriginalT0` são sempre falsos.
- Os testes cobrem horário local versus UTC, limites exatos das duas janelas, sequência de espera, relógio adiantado, erros definitivos e impedimento de prolongar prazo.

**Importante:** a classe não contém fila durável, lease, verificação de vínculo CRM, assinatura, consentimento, dado pessoal ou permissão de envio. A integração real exige storage próprio autenticado e política de tentativas revisada. Dez gates operacionais continuam `BLOCKED`.