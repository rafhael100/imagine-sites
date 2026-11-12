# F1-281 — Revalidação da vinculação CRM antes de cada nova tentativa

Esta etapa acrescenta um novo controle à simulação de reenvios do Imagine Sites: **mesmo depois de respeitar os horários do T0 e manter o mesmo destino, a vinculação corrente precisa ser reconsultada**.

- A submissão deve preservar organização, identificador de envio, site, formulário, vínculo e destino CRM da tentativa original (F1-280).
- A janela de espera e validade total de T0 tem que estar temporalmente elegível (F1-279), caso contrário a fonte de vínculo nem é chamada.
- A fonte sintética de autoridade existente F1-139 é consultada duas vezes. Uma primeira ou segunda leitura com vínculo ausente, inativo/revogado, destino divergente ou revisão alterada bloqueia a tentativa.
- Falha da fonte, mudança de empresa ou submissão, HTTP 202 pendente e T0 expirado resultam em negação explícita.
- Mesmo com duas leituras idênticas e ativas, o retorno é somente `LAB_CANDIDATE_STILL_REQUIRES_AUTHENTICATED_AUTHORITY`; nunca autoriza envio, nonce persistente, confirmação de recebimento ou mudança de vínculo.

**Segurança:** duas leituras de uma fonte injetada não comprovam identidade nem atomicidade. A versão operacional ainda exige token S2S Portal/CRM, RLS e controle transacional da revisão atual. Sem esses gates, o código permanece offline. Os dez gates de go-live continuam `BLOCKED`.