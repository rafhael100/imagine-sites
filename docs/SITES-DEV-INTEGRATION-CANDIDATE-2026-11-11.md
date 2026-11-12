# Imagine Sites — candidato de integração para revisão (11/11/2026)

**Objetivo:** dar visibilidade ao grande conjunto de alterações acumuladas em branches isoladas sem promover automaticamente código para `dev`, `main` ou produção. Este documento não aprova deploy nem libera gates operacionais.

## Baseline e rastreabilidade

- `dev` no início da consolidação: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`.
- Origem congelada para revisão: `5ae63db64c19e9c460bd7695db55fdf715fb598f` (F1-231, PR #265).
- Comparação `dev...origem` em 11/11: **883 commits à frente, 0 atrás**. A API compare exibiu até 300 caminhos; a contagem de arquivos exibida **não é o total confiável** do diff.
- A branch deste pacote é apenas **candidata**; a abertura de PR não faz merge nem modifica `dev`.

## Sequência sugerida de revisão, sem perder evidências

| Lote | Área de código | Evidência mínima antes de considerar merge |
| --- | --- | --- |
| A | `src/Production`, prévias visuais, HTML estático, QA de acessibilidade e revisão editorial | Testes sintéticos PHP 8.3/8.4, Laravel 8.3/8.4, Chrome/contraste, ausência de execução externa |
| B | Dossiês e pacotes privados de revisão, digests, escrita/leitura segura e isolamento | Versões exatas, hashes de quatro arquivos, negativos para adulteração, `noindex`, revogação e permissão de arquivos |
| C | `labs/postgres` e `src/Security`: RLS, replay, autoridade, nonce, clocks e leases | PostgreSQL efêmero, concorrência, fail-closed, negação cross-tenant e de operações obsoletas |
| D | Contratos e integração candidata Portal↔Sites | Paridade cross-repo com SHA fixo, identidade e aceite **não** presumidos |
| E | Preparação de captação Sites→CRM | T0 original, janela até 432h sem reset, fila de contingência, RLS, idempotência e **sem** ACK fictício como entrega |
| F | SEO privado F1-215–231 | Comparação `head` sem alterar corpo, mesmo escopo por par, evidências distintas para canais distintos; PHP e Laravel sem publicação |

## Pontos de controle

- PR #260 corrige regressões nos comparadores e no isolamento de empresas.
- PRs #261, #262, #264, #265 ampliam evidências de SEO privado, CLI, interface Laravel e proveniência.
- A versão candidata deve passar por todos os checks relevantes após a abertura deste PR. Sucesso em um PR anterior não prova o estado de todos os workflows desta branch.
- A lista de arquivos e os requisitos precisam de revisão humana de diff, dependências e segredos antes de qualquer merge.
- Não forçar atualização de branch nem invalidar trabalhos paralelos; atualizar a base e rever se `dev` for alterada por outra pessoa.

## Gates permanecem fechados

`config/operational-readiness.json`: `status=BLOCKED`, `non_operational=true`. São exigidas evidências independentes de contratos, IAM Portal e revogação, PostgreSQL de produção e backups, geração IA real com controle de custos, publicação observada, confirmação CRM/captação física, aceite imutável, LGPD/AppSec, DNS/domínio autorizado e autorização de go-live. Issues #33–#38 acompanham bloqueadores; issue #263 acompanha a consolidação.

**Nenhuma operação financeira, publicação Cloudflare, acesso à VPS, mudança de DNS, uso de credenciais, cliente real, migração ou promoção de `main` faz parte deste PR.** AGI-11-RB01 permanece desabilitado. Regras do `AGENTS.md` têm precedência sobre qualquer interpretação deste documento.
