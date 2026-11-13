# F1-130 — Revisão independente do vínculo Sites↔CRM antes de reenvio de lead

## Contexto P0 / OP04

O pré-teste `SyntheticCrmLeadRetryPreflight` da F1-55 confirma que dois registros de vínculo, obtidos de fontes distintas do Imagine Sites e do Imagine CRM, concordam com o recibo de entrada **imutável**: empresa, site, destino CRM original, revisão do vínculo, estado ativo e frescor. O prazo permanece 72 horas iniciais + 360 de recuperação = **432 horas contadas do T0 original**.

Uma fonte, porém, poderia revogar e restaurar o vínculo com **exatamente os mesmos campos** enquanto o pré-teste acontece (A→B→A). Comparar apenas os snapshots permite perder o evento.

## Implementação candidata

Criado `LeadBindingEpochSource::currentEpochFor(tenantRef,siteRef)`, com implementação `DenyingLeadBindingEpochSource` (default DENY). A classe `SyntheticCrmLeadRetryEpochFencedPreflight` injeta o pré-teste F1-55 e **duas fontes de geração independentes**, uma do Sites e outra do CRM, sem aceitar os valores como campos do lead.

Fluxo, sem enviar dados:

1. Recibo já entregue/expirado ou fora da janela original → DENY, **sem consultar fontes**.
2. Consulta duas gerações positivas, obtidas independentemente de Sites e CRM. Ausência/erro → DENY.
3. Executa a verificação F1-55 de vínculo ativo, empresa/site, destino CRM original, revisão e snapshots recentes.
4. Consulta novamente as **duas gerações**. A divergência entre antes/depois, mesmo com vínculo restaurado aos mesmos campos, resulta `crm_binding_epoch_changed_during_preflight` e DENY.
5. Apenas se tudo passar, retorna `PolicyDecision(true, [])` **exclusivamente como pré-condição sintética**. Não envia, confirma, marca entregue, modifica o T0 nem redireciona o CRM original.

As épocas das duas fontes podem ter **números diferentes**; o requisito é que cada geração permaneça estável em sua própria autoridade.

## Testes

`tests/f130-crm-binding-epoch-preflight.php`, executado pelo workflow `synthetic-php.yml` em PHP 8.3/8.4, valida:
- padrões default DENY e duas épocas independentes diferentes, mas estáveis;
- mudança A→B→A detectada tanto no vínculo Sites quanto no CRM com os mesmos snapshots;
- indisponibilidade ou ausência de época antes e depois da checagem;
- destino CRM divergente continua bloqueado, apesar de épocas consistentes;
- recibo entregue ou janela de 432 horas expirada não consulta nenhuma fonte;
- restabelecimento possível até o último minuto da janela original quando os dois vínculos são frescos;
- referência de destino CRM e T0 imutáveis no recibo fictício.

## Limitações e próximos gates

As fontes da suíte são **variáveis sintéticas em memória**. Não demonstram fonte monotônica real, consistência entre datacenters, recepção/criptografia de PII, idempotência global, persistência T0, entrega SMTP/HTTP, confirmação do CRM, dois endpoints físicos ou retenção LGPD. A checagem não captura uma revogação que ocorra **após a segunda consulta** e não autoriza transmissão por si só.

A OP04 permanece P0: antes de aceitar leads reais, implementar identidades mutuamente autenticadas e duas fontes de vínculo/revisão independentes, dois ingressos físicos e armazenamento redundante, dedup global, confirmação no CRM, retenção/eliminação com auditoria e homologação AppSec/LGPD. **Nenhum formulário público, cliente ou dado pessoal é processado nesta entrega.**

PR draft/empilhado, sem merge `dev/main`, deploy, DNS, Cloudflare, VPS, novas despesas ou autorização de publicação. **10/10 gates de produção BLOCKED.**
