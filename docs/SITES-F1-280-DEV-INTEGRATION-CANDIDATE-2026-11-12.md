# Imagine Sites — candidato F1-280 para revisão independente em dev

**DRAFT, 12/11/2026 — nenhuma mesclagem, publicação, custo, credencial ou cliente real.**

## Branches protegidas por procedimento (não por regra do GitHub)

- Base `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3` e `main` SHA `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.
- Ambas ainda reportam `protected:false` na API. A issue #281 permanece bloqueadora.
- Origem F1-280 à frente de `dev` em 1.200 commits e zero atrás, antes desta documentação; novo SHA exige novo CI.

## Entregas F1-276 a F1-280

| Entrega | PR | Comportamento |
| --- | --- | --- |
| F1-276 | #325 | Chrome CDP retry único somente ao falhar port de bootstrap, nunca em QA ou layout |
| F1-277 | #326 | Espera exponencial a partir do T0 durável externo, 72h/432h imutáveis |
| F1-278 | #327 | Respostas 202/409 não são recibos; Retry-After numérico estrito não concede envio |
| F1-279 | #328 | Combina Retry-After com backoff e limite 432h sem reiniciar relógio |
| F1-280 | #329 | Reenvios não podem mudar organização, submissão, binding, site, form ou destino CRM |

Todos os componentes CRM são **simulações inertes de metadados**: não contêm contatos, não persistem fila e não substituem autorização.

## Verificações obrigatórias

1. Revalidar todas as matrizes PHP 8.3/8.4, Laravel, PostgreSQL 18, Docker, Chromium, Worker e segurança GitHub no SHA deste novo candidato.
2. Gerar inventário completo F1-240 do diff Git contra a base fixa e seis pacotes F1-241 (sem reutilizar contagens anteriores).
3. Inspecionar todos os caminhos sensíveis AppSec, DBA, integração Portal/CRM, infraestrutura e auditoria humana independente.
4. Manter 10 gates operacionais `status=BLOCKED` — nenhum botão de CI é um gate de go-live.
5. Exigir branch protection administrativa em dev/main (issue #281) e revisão independente antes de considerar merge.

## Limitações reais

Não habilita CRM ativo, APIs, IAM, nonce durável, consentimento, RLS, outbox, entrega a visitante, publicação Cloudflare, DNS, VPS, segredos nem uso de dados reais. O Portal continua autoridade sobre aceite e entrega. Um status HTTP ou teste temporal jamais confirma lead no CRM.