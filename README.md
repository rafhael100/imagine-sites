# Imagine Sites Engine

Motor independente de criação, versionamento, publicação e manutenção de **Onepages e Landing Pages produzidas e administradas pela Imagine**. O cliente continua contratando, enviando briefing, aprovando e acompanhando pelo Imagine Portal; não haverá editor livre para o cliente.

**F1-01 = laboratório de regras sintéticas, NÃO motor operacional.** Sem APIs reais, banco, aplicativo Laravel, CRM, Cloudflare, AI Agents ou deploy. Os testes não comprovam identidade real, criptografia, isolamento multiempresa, disponibilidade ou publicação em produção.

Autoridades: Portal controla contratos, aceite expresso e evento oficial ENTREGUE; Sites controla release técnica, artefatos e verificação de publicação; CRM controla confirmação de leads; AI Agents executam apenas tarefas autorizadas.

## Verificações locais (PHP 8.3+, sem dependências e sem rede)

- Executar: php tests/run.php
- Verificar sintaxe de todos os arquivos src/Domain/*.php e tests/*.php: php -l.

## Documentos
- docs/SITES-ARQ-02-BOUNDARIES.md — autoridades e topologia
- docs/SITES-CT-01-PORTAL-SITES-v0.1.md — contratos candidatos
- docs/SITES-SEC-01-GATES.md — segurança e pendências
- docs/SITES-MIG-01-INVENTARIO.md — rastreamento de migração
- STATUS-ATUAL.md e AGENTS.md

Referência principal: https://github.com/rafhael100/imagine-portal/pull/94

Alvo tecnológico candidato: Laravel e PostgreSQL próprios, APIs/eventos autenticados, GitHub para versionamento e Cloudflare Workers Static Assets. **Não homologado**. Sem merge/deploy automático, dados reais, despesas ou configuração de infraestrutura neste recorte.
