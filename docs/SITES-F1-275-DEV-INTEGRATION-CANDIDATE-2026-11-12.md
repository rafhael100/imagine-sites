# Imagine Sites — candidato de integração F1-275 (12/11/2026)

**DRAFT em `dev`: não mesclar, publicar, implantar ou ativar clientes sem as autorizações e revisões independentes.**

## Estado verificado

- Base `dev` `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`, `main` `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`, ambas ainda sem branch protection observada.
- A origem F1-275 tinha 1.171 commits à frente de `dev` e zero atrás antes desta documentação.
- Candidato anterior #322 / F1-274: **106/106 workflows PASS**, inventário F1-240 **697 arquivos/196 caminhos sensíveis**, seis pacotes F1-241. Esses números não são reaproveitáveis no novo HEAD.

## Entregas novas

- F1-270–273 (#316–#319): FAQ HTML `<details>/<summary>`, criação de revisão editorial, comparador privado Laravel, Chrome real com Enter/Espaço/Tab e layouts responsivos.
- F1-274 (#321): audita todos os pares de tags e exige que os itens de FAQ estejam na seção certa para impedir empacotamento por manifesto rehash.
- F1-275 (#323): exige a **estrutura completa e exata** da seção de FAQ. Blocos extras, wrappers, novas classes, linhas incomuns e alterações do heading são recusados mesmo após hash recalculado. Testes específicos PHP passaram; conferir todas as matrizes finais no SHA.

## Gates necessários neste HEAD

1. Rodar novamente todas as suítes PHP/Laravel/PG18/Docker/Chromium/Node/segurança neste SHA exato.
2. Recalcular o inventário F1-240 em Git local completo, não via API compare truncada.
3. Regenerar seis pacotes F1-241 e organizar revisão independente AppSec, DBA, Portal, CRM, UX/acessibilidade e governança.
4. Continuar com **dez gates `BLOCKED`**, sem qualquer liberação por CI ou teste sintético.
5. Configurar branch protection administrativa `dev/main` e revisores externos (issue #281), sem merge automático.

## Limites operacionais

Não inclui dados de clientes, formulários de captação reais, consentimento, OAuth/IAM, CRM ativo, publicação Workers, DNS/TLS, VPS, custos, segredos, aprovação do cliente ou evento ENTREGUE. Todos os artefatos permanecem privados, fictícios e `noindex`.