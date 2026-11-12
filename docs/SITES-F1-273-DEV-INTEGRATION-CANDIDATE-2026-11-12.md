# Imagine Sites — candidato integrado F1-273 para revisão de `dev`

**DRAFT para revisão, sem merge, sem deploy e sem autorização de produção. 12/11/2026.**

## Base

- `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; `main` SHA `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.
- Origem `feat/sites-f1-273-faq-keyboard-chrome-2026-11-12`, inicialmente 1.152 commits à frente de `dev`, zero atrás, antes de adicionar este relatório.
- Candidato anterior #315: 100/100 workflows aprovados; 678 arquivos, 191 caminhos sensíveis, seis pacotes F1-241; **esses números são históricos e NÃO valem para este novo HEAD**.

## Quatro novas entregas

| Fase | PR | Resultado |
| --- | --- | --- |
| F1-270 | #316 | FAQ expansível nativo com `<details>/<summary>`, sem JS, conteúdo escapado, 24 variações, nova verificação DOM, opções antigas byte-a-byte preservadas — **26/26 workflows PASS** |
| F1-271 | #317 | Operação editorial separada obriga nova revisão e novo release ao alternar FAQ, nega agente, replay ou modo idêntico — **10/10 workflows PASS** |
| F1-272 | #318 | Tela Laravel de comparação FAQ tradicional/expansível, 24 combinações e inspeção de quatro arquivos privados de cada modo — **26/26 workflows PASS** |
| F1-273 | #319 | Teste Chromium real em 2 produtos x 3 larguras, Tab/Enter/Espaço e largura de toque sem overflow — **6/6 workflows PASS** |

## Gate de integração desta branch

1. Executar todas as matrizes PHP 8.3/8.4, Laravel, PostgreSQL 18, Chromium, Docker, Node/Worker, segurança de workflows e integração privada.
2. Recalcular F1-240 **com diff Git completo contra a base fixada**, sem limite de 300 arquivos da API.
3. Regerar os seis pacotes F1-241 sobre o SHA exato e encaminhar para revisão independente AppSec, DBA, Portal, CRM, frontend/acessibilidade e governança.
4. Manter os dez gates de `config/operational-readiness.json` falsos e `status=BLOCKED`.
5. Exigir administrador para ativar proteção verificável de `dev/main` (issue #281), além de code review. Não promover código diretamente.

## Limitações preservadas

Não há cliente self-service, consentimento real, domínio/DNS, emissão de certificado, CRM de produção, publicação Cloudflare, VPS, API paga, chaves reais, dados pessoais ou autorizações operacionais. A FAQ em HTML é apenas componente de preview privado. Aprovação do cliente e evento ENTREGUE continuam exclusivos do Portal.

**Não executar merge ou deploy automático.** Revisões independentes de 100% dos caminhos sensíveis continuam necessárias mesmo com CI verde.