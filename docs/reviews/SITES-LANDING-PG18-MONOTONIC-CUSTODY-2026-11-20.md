# Sites Landing PG18 — monotonicidade de custódia e serialização da revisão (draft)

Continuidade das candidatas [Sites #541](https://github.com/rafhael100/imagine-sites/pull/541)/[#542](https://github.com/rafhael100/imagine-sites/pull/542) até [#564](https://github.com/rafhael100/imagine-sites/pull/564), com base imutável `0f524fb53d1a6d6630ea8b42399cc56a290e7ce3`. Portal permanece fixado em `4a5e1e06376188f85c90bee3d10056ab3ce2302a`. Esta entrega não altera o Portal.

## Lacuna corrigida

A função candidata `record_pending` validava o hash, o tenant e a operação, porém aceitava a criação **nova** de uma revisão antiga depois de uma revisão mais recente, desde que a entrada antiga tivesse JSON e SHA-256 válidos. E a função `record_review_observation` podia consultar o artefato corrente sem serializar essa leitura com outra gravação de custódia.

- As duas funções passam a bloquear a mesma linha `admitted_sites` da empresa/site por `FOR UPDATE`, mantendo o bloqueio transacional até o commit/rollback do cliente.
- `record_pending` recusa novas operações cujos pares `(source_version, approval_epoch)` sejam inferiores a algum par já custodiado no site; replays exatos de operações anteriores continuam idempotentes, sem criar linha.
- `record_review_observation` lê o head custodiado depois de adquirir o mesmo bloqueio, recusando tentativa de observação com head supersedido.
- Prova adversarial acrescenta submissão direta de payload antigo com hash válido depois de uma versão nova, além do replay histórico exato.

## Escopo e condições de segurança

Fixtures de PG18 e identidades sintéticas; não é migration de produção, não há homologação do ator humano, transportes S2S, aprovação oficial do briefing ou publicação. As revisões continuam **observações privadas**, não aprovação editorial nem autorização comercial. O Portal continua sendo dono do aceite expresso do cliente; Sites detém a publicação técnica. Os dez gates permanecem bloqueados, sem merge/deploy/pagamentos.

Teste no CI draft-only: `php tests/landing-page-pg18-review-readback.php` (invoca custódia, diário e leitor em PG18 real descartável) com `SITES_LANDING_CUSTODY_CI=1`, `SITES_LANDING_REVIEW_CI=1`, `SITES_LANDING_READBACK_CI=1` e `GITHUB_ACTIONS=true`. Nenhum resultado é declarado até o workflow executar.

## Pendências operacionais

Fonte oficial transacional do Portal com briefing aprovado/versionado e auditoria de identidade autenticada; políticas RLS e permissões reais, comunicação autenticada com prova de revogação, revisão formal humana, publicação técnica homologada e aceite. Sem promover CI sintético a evidência de operação.
