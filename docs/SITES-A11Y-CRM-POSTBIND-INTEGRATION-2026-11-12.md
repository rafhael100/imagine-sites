# Imagine Sites — candidato consolidado de revisão A11Y + CRM pós-vínculo

**Data: 12/11/2026. PR DRAFT, sem merge, sem deploy, sem clientes reais.**

## Referência e dependências

- Base `dev`: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. `main`: `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`. Em ambas, API reportou `protected:false` na revisão de 12/11/2026.
- Parte da cadeia mais avançada F1-286, candidato [PR #338](https://github.com/rafhael100/imagine-sites/pull/338). Evita duplicar a outra cadeia de recursos de teclado.
- [PR #344](https://github.com/rafhael100/imagine-sites/pull/344): foco **efetivo** no `<main>` após Enter no link de salto, além da mudança do hash. **28/28 workflows aprovados**. Testes PHP, Laravel e Chrome real.
- [PR #345](https://github.com/rafhael100/imagine-sites/pull/345): verificação sintética final do prazo T0+432 horas após as leituras de vínculo CRM, para detectar vencimento durante uma operação lenta; teste dedicado PHP 8.3/8.4 aprovado. O CI global deve ser refeito no SHA desta integração.
- A origem da integração tinha **1.262 commits à frente e zero atrás de `dev`** antes desta documentação. Esse número não significa aprovação, merge ou entrega operacional.

## Auditoria obrigatória

1. Rodar novamente o inventário F1-240 de todo o diff Git contra a base fixa, sem usar o limite de 300 arquivos da API compare.
2. Gerar seis pacotes F1-241 com SHA vinculado ao mesmo HEAD, inventariando todos os arquivos sensíveis.
3. Reexecutar todas as matrizes PHP 8.3/8.4, Laravel, PostgreSQL 18, workflows/infra, Docker, Node, contraste, QA de Chrome e testes de binding/TTL.
4. Solicitar revisão independente de AppSec, DBA, integrações Portal/CRM, frontend/acessibilidade e infraestrutura, inclusive o teste novo de T0 pós-vínculo.
5. Manter `config/operational-readiness.json` em `BLOCKED` com dez gates falsos, e solicitar branch protection administrativa na issue #281 antes de qualquer promoção.

## Fronteiras

O registro sintético de assinatura, a comparação de snapshots e a leitura de tempo não autenticam cliente, organização ou provedor. Uma futura fila de envio real exige relógio confiável e verificação atômica de T0, vinculação e nonce na base durável. Nenhum CRM real, PII, cliente, Cloudflare Workers, DNS/TLS, VPS, credencial operacional, API paga, deploy ou merge é autorizado neste candidato.

**Esta integração é um pacote de revisão técnica e não uma autorização de publicação.**