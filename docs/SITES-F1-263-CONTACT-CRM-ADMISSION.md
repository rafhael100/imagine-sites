# F1-263 — Pré-admissão privada de leads para futura integração CRM

Conectada a pré-validação de contato da F1-262 à estrutura tipada de metadados do CRM, incluindo organização, vínculo, formulário, site, destino e UUID de submissão. A classe permanece **diagnóstica e sem efeitos colaterais**.

- Exige que o relatório de privacidade esteja estruturalmente completo, sem honeypot e com intenção de consentimento declarada, mas não a considera prova legal.
- Confere o vínculo por uma fonte independente **injetada e ainda não autenticada**, recusando indisponibilidade, revogação, ausência, site/formulário/organização ou destino incompatíveis.
- Repetição de submissão precisa conservar exatamente o mesmo escopo lógico e contexto; não constitui bloqueio idempotente durável.
- Relatório final contém somente código de avaliação, sem armazenar nome, e-mail, telefone, documento, IP, payload, UUID ou consentimento bruto.
- Mesmo o resultado de preparação positiva é `S2S_REVIEW_CANDIDATE_UNVERIFIED`. Todos os métodos de persistência, aceite, confirmação e entrega retornam falso.
- Testes cobrem 14 situações positivas/negativas em PHP 8.3/8.4, incluindo revogação e troca de tenant, formulário e destino.

**Atenção:** não integra APIs externas, CRM real, assinatura, autenticação, LGPD operacional, T0 durável, outbox, tentativas, retries ou publicação. Os dez gates operacionais continuam `BLOCKED`; apenas prepara a implementação futura com validações explícitas.