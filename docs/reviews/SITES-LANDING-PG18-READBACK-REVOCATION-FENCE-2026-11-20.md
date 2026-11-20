# Landing PG18 — proteção de leitura contra revogação concorrente

## Escopo e rastreabilidade

Continuação isolada de [Sites #567](https://github.com/rafhael100/imagine-sites/pull/567), base fixa `5c2d40b8bd16cf3e4c1935b1789ede054d6ef29d`. Retoma a cadeia de integração [#541](https://github.com/rafhael100/imagine-sites/pull/541)/[#542](https://github.com/rafhael100/imagine-sites/pull/542) sem modificar branches existentes. Integração Portal Landing fixada em [#1019](https://github.com/rafhael100/imagine-portal/pull/1019), commit `4a5e1e06376188f85c90bee3d10056ab3ce2302a`.

## Lacuna encontrada

A função `read_current_review_observation` verificava permissões `active=true` em snapshot, mas não mantinha bloqueios de linha até o COMMIT do adaptador. Uma outra sessão podia revogar o leitor, site, autor ou revisor enquanto a leitura privada ocorria, permitindo devolver uma observação que já não tinha admissão válida ao final da transação.

## Correção candidata

- Converter a função de `STABLE` para `VOLATILE` e aplicar `FOR SHARE` à admissão do leitor, e às linhas de site, autor e revisor referenciadas pela observação custodiada. Essas linhas ficam protegidas até COMMIT/ROLLBACK do leitor.
- Retirar `SET TRANSACTION READ ONLY` do adaptador PHP: PostgreSQL não permite row locks em transações READ ONLY. A função permanece sem instruções de escrita, e a role de leitor continua sem acesso `SELECT` direto às tabelas privadas ou `INSERT/UPDATE/DELETE`.
- Provar a recusa segura com quatro sessões de revogação simulada, bloqueios `FOR UPDATE` independentes e `lock_timeout=300ms`. Cada recusa deve terminar sem mutação de auditoria e com transação encerrada; após liberar o lock, a leitura exata volta a ser possível.

## Limites e gates

O PostgreSQL 18 e as roles usadas na CI são descartáveis e **sintéticas**. Não existe identidade humana autenticada, fonte oficial de aprovação e versão do Portal, transporte S2S homologado, migration operacional, role real, revisão formal, publicação técnica ou aceite do cliente. O resultado da consulta é **somente observação privada** (`observations_recorded` / `changes_requested`); não aprova artefato, release, publicação, pagamento ou entrega. Revogação Portal posterior ao COMMIT não é resolvida por lock local: o sistema deverá revalidar antes de qualquer ação crítica.

**Dez gates permanecem BLOCKED**. Sem merge, deploy, alterações em `main`, VPS, produção, Cloudflare/DNS, credenciais, Asaas ou gastos.

## Evidências

Workflow candidato `.github/workflows/sites-landing-pg18-readback-revocation-fence.yml` com PHP 8.3/8.4, PostgreSQL 18 e pinned Portal. CI deve ser observado na HEAD final antes de registrar sucesso.
