# Imagine Sites — candidato F1-287 + ADR-015 para revisão em dev

**12/11/2026. PR DRAFT. Sem merge, deploy, alteração de DNS, Cloudflare, VPS, CRM real, cliente ou custos.**

## Conteúdo consolidado
- Origem: PR #346 (A11Y foco efetivo e T0 pós-vínculo CRM), SHA 0848256a8795b96f0d5dd66264858189287aca86.
- F1-287: PR #348, proposta imutável e não operacional de domínio apex, segurança de host e preservação dos legados, com testes PHP 8.3/8.4.
- ADR-015: PR #347, decisão do titular de padronizar sem www novos sites públicos; hosts legados não sofrem migração silenciosa.
- Novas matrizes em .github/workflows/sites-f1-287-apex-canonical.yml e no synthetic-php.yml existente.

## Requisitos de integração
1. Confirmar **todos** os GitHub Actions no HEAD deste candidato; resultados de #346/#347/#348 não substituem testes próprios.
2. Rodar F1-240 inventário Git integral contra dev, sem truncamento de API, e F1-241 em seis pacotes vinculados ao SHA exato.
3. Revisão independente de segurança, banco, Portal/CRM, frontend/acessibilidade, DNS/SEO e infraestrutura, inclusive 301/308 (somente em ambiente de homologação, quando aprovado).
4. Configurar branch protection e revisores obrigatórios em dev/main conforme issue #281. Não mesclar até ambas as exigências.
5. Conservar config/operational-readiness.json com dez gates BLOCKED: política/aprovação editorial não comprovam publicação e dados reais.

## Ponto de entrada operacional
Para publicar, o Portal precisa autenticar contrato/aceite oficial, Sites validar domínio/certificados e release, Cloudflare comprovar conteúdo servido, banco/backup/CRM serem efetivos, e revisão de privacidade/custos/rollback concluída. Até lá todos os componentes seguem laboratório não operacional.

**Gate de decisão: NO-GO produção.**
