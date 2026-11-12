# Revisão independente — autorização de serviços Portal → Sites (pré-homologação)

**Registro da rodada:** 10/11/2026. **Status operacional: BLOQUEADO.** Este documento é uma pauta de revisão, **não** uma revisão AppSec concluída nem autorização de merge/deploy.

## Evidências de laboratório disponíveis

- [PR #151 — F1-115](https://github.com/rafhael100/imagine-sites/pull/151): uma solicitação de criação de projeto **não escolhe sua própria chave de verificação HMAC**. `LabSignedProjectIntake` recebe a chave de fonte injetada e default DENY. Testes com PostgreSQL 18, PHP 8.3/8.4, Docker, Laravel, CI de segurança e testes sintéticos foram aprovados.
- [PR #152 — F1-116](https://github.com/rafhael100/imagine-sites/pull/152): a chave atual é reconsultada no início e no pré-commit da admissão; revogação durante a transação causa rollback de novo projeto+admissão. Os cinco workflows distintos da primeira HEAD do PR terminaram com sucesso.
- [PR #150 — F1-114](https://github.com/rafhael100/imagine-sites/pull/150): a falha do banco de projetos **após** consumo do nonce exige um novo nonce e preserva idempotência.
- [PR #149 — F1-113](https://github.com/rafhael100/imagine-sites/pull/149): a falha **antes** do consumo do nonce deixa o envelope original disponível após recuperação.
- [PR #148 — F1-112](https://github.com/rafhael100/imagine-sites/pull/148): a role e o ambiente de replay são revalidados a cada operação, e transações controladas externamente são negadas.
- [PR #147 — F1-111](https://github.com/rafhael100/imagine-sites/pull/147): prova duas bases PostgreSQL 18 efêmeras, controle de replay entre sessões independentes e admissão idempotente.

Estes PRs são **empilhados**, dependem das etapas anteriores e seguem como drafts. Passar CI de laboratório não comprova integração com o Portal em sua branch corrente e não fecha gates de produção.

## Critérios para o profissional/revisor AppSec independente

1. Confirmar ameaça, fronteira de confiança e gestão de identidade serviço-serviço: emissor, audiência, rota e canal mutuamente autenticado; comparar mTLS, OAuth client credentials e outras alternativas conforme contexto operacional.
2. Revisar a proposta de origem de chaves: vault/secret manager, rotação, revogação, TTL, restrições por ambiente, logs sem segredos, segregação de credenciais de teste e produção e eliminação de chaves passadas por consumidores externos.
3. Verificar autorização por **empresa/recurso/contrato/pedido**, fonte oficial do Portal, revisão monotônica e revogação após restore. O snapshot sintético F1-102/105 e a comparação de chave F1-116 não substituem serviço IAM oficial.
4. Reexecutar testes independentes de replay distribuído, concorrência, indisponibilidade de duas bases, retry com novo nonce, idempotência e consistência do ledger; confirmar tratamento de falhas sem leakage de informações.
5. Examinar PostgreSQL: RLS, privilégios, SQL arbitrário e GUC de tenant falsificável. Contas SQL de laboratório não podem virar credenciais acessíveis a clientes, agentes IA ou browsers.
6. Revisar segurança de entrada HTTP, autenticação end-to-end, esquema OpenAPI/AsyncAPI e integração contra **versão atual** do repositório Portal com credenciais **exclusivamente de homologação**.
7. Emitir relatório independente com achados, severidades, evidências de correção, reteste e **parecer de liberação ou bloqueio**. Um teste automatizado do próprio desenvolvedor **não é parecer independente**.

## Dependências de aceite e promoção

As issues [OP01 #33](https://github.com/rafhael100/imagine-sites/issues/33) (identidade/autorização Portal) e [OP02 #34](https://github.com/rafhael100/imagine-sites/issues/34) (banco/RLS/outbox) já concentram os requisitos operacionais. O parecer do revisor deve ser anexado ali antes de avaliar merge e homologação; não basta aprovar a branch sintética. A liberação de go-live também depende dos demais gates de `config/operational-readiness.json`, que permanecem **10/10 BLOCKED**. Não gerar credenciais reais, contratar serviços ou publicar sites sem aprovações operacionais apropriadas.
