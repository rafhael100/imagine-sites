# STATUS-ATUAL — Imagine Sites Engine

**Referência:** 08/11/2026. **Recorte atual:** F1-02 (branch filha de F1-01). **Sem deploy.**

## F1-01
- Núcleo PHP sintético de manifesto/digest, critérios de publicação, entrega técnica consultiva, T0+72h/432h, idempotência em memória, documentação e CI.
- 32 verificações sintéticas locais PASS (PHP 8.4.24).
- PR #1 do novo repositório continua draft sem merge. GitHub CI F1-01: duas execuções completadas com sucesso no SHA 101e416b081a9f0a1d825a635df934527bc21ddd.

## F1-02
- Adiciona gateway de autoridade tipado com implementação padrão que **sempre nega**, e PublicationPreflightService que exige decisão do gateway antes de qualquer elegibilidade.
- 8 verificações sintéticas locais PASS (PHP 8.4.24), somente mocks; CI remoto desta branch deve ser conferido.
- Ainda não existem APIs, autenticação criptográfica, serviços externos, banco, RLS, Cloudflare, contratos reais, publisher ou deploy.

## Gates abertos
P0-DOC01..14, RF-PEND-01, RF-PEND-04, AUT-P01..10 como norma e RV-CT01/02 como diretriz não homologada, RV-CT03..08 pendentes, AGI-11-RB01 DESABILITADO. Não usar flags/test fixtures como provas de autoridade real.

## Próxima etapa
F1-03: plano de fundação Laravel/PostgreSQL sem execução de migrations reais e sem provedor/credencial até os gates pertinentes. Novo PR filho, sem merge/deploy automático.
