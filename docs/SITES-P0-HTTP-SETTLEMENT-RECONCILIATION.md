# Imagine Sites P0 — reconciliar linha Portal→Sites HTTP PG18 e gateway comercial independente

## Diagnóstico reproduzível

A linha de desenvolvimento do Sites em [#469](https://github.com/rafhael100/imagine-sites/pull/469) contém migrations PostgreSQL18 versionadas, leitura tenant, editor/revisor e interface de autorização comercial independente **default-deny**. A linha paralela [#463](https://github.com/rafhael100/imagine-sites/pull/463) testa a passagem de pedidos fictícios já persistidos no PostgreSQL do Portal pela rota HTTP Laravel/Nginx até PostgreSQL18 Sites, com certificados efêmeros e validação TLS verify-full.

Antes desta branch, a comparação das duas HEADs tinha **8 commits exclusivos da linha HTTP, ausentes na linha de gateway comercial**. Não era correto promover o Sites como integrado usando somente os resultados de CI de cada linha em separado.

## A reconciliação desta candidata (sem tocar nas bases)

- Mantém a branch #469 como base e não usa merge automático em `dev`/`main`.
- Restaura **somente** os componentes de testes HTTP faltantes da linha #463: autoridade de teste `CIHttpPortalPurchaseAuthority`, overlay Compose guardado por opt-in, teste de compras persistidas `f381-portal-paid-briefed-db-to-sites-http.php`, documentação e workflow próprio.
- Faz a escolha da autoridade na rota `/_ci/sites/v1/admit` **somente** dentro do gate existente `PHP_SAPI=fpm-fcgi`, `APP_ENV=testing`, `GITHUB_ACTIONS=true`, `SITES_F377_CI_HTTP_LAB=1` e assinatura de teste. Sem flags, caminho não é registrado em staging comum nem produção. O novo flag `SITES_F381_PURCHASE_HTTP_LAB` nunca é uma autorização real.
- Executa o fluxo da linha #463 **contra o código reconciliado**, mantendo os testes do gateway Ed25519 independente e das migrations versionadas na mesma HEAD. Esta etapa valida compatibilidade **de código e testes**, não assinatura comercial operacional.
- Não cria contas ou projetos reais, não usa credenciais operacionais, não altera DNS, Leaseweb, PSP, `main` ou `dev`.

## Acompanhamento dos testes na HEAD

A aprovação técnica exige que todos os workflows da HEAD exata passem, inclusive:

1. `Sites F1-381 Portal PG18 purchase to Sites HTTP and verified TLS (CI only)` — confirmação de duas admissões fictícias, outbox, replay e cliente/tenant adversarial;
2. `Sites F1-386 Portal Ed25519 proof + independent PSP settlement/revocation gate` — três fontes independentes em **stubs artificiais**, sem chamadas reais;
3. `Sites F1-383 versioned operational PG18 schema - safe default-deny only` — migrations, RLS e rollback no banco efêmero;
4. guard operacional, Laravel sintético e motor de testes.

**As 8 diferenças de ancestralidade permanecem como commits de origem não mesclados.** A reconciliação funcional por arquivos não concede histórico de merge: um revisor deve comparar os patches, conflitos e cobertura antes de aprovar eventual união de linhas. Nunca reduzir contagem de commits ausentes fingindo que houve merge.

## Gates pendentes para conclusão operacional

- Adaptadores reais de fontes independentes Portal, liquidação PSP, revogação e autenticação mútua do serviço; nenhum deles é substituído pelo mock HTTP ou pelo Ed25519 de CI.
- Eventos da fila com operador/worker, consentimento, contrato vigente, aceite expresso e provisionamento monitorado.
- Testes na VPS Leaseweb com TLS de verdade, segredos separados, restore/rollback, logs e identidade de usuários reais.
- Revisão de AppSec/DBA/LGPD e decisão explícita de promoção de mudanças.

**DRAFT / NO-GO / sem merges automáticos ou deploy.**
