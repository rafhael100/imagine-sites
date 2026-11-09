# F1-35 — Confirmação única de elegibilidade de proposta (laboratório SQL)

A cadeia F1-32..34 fornece o histórico imutável, a fila de propostas e uma visão de propostas atuais. Esta etapa acrescenta **uma reivindicação (claim) abstrata, não envio real**: `claim_synthetic_review(...)` registra no Postgres descartável que um trabalhador *fictício* selecionou uma proposta **atual**.

- `SECURITY INVOKER` mantém o usuário SQL sujeito às políticas RLS.
- Uma única linha `(tenant_id, proposal_ref)` impede dois trabalhadores de reivindicarem a mesma proposta. Repetição retorna falso, sem alterar histórico.
- A proposta precisa ser visível na view F1-34, na revisão atual, com o digest SHA-256 exato. Após retorno para ajustes, a proposta antiga não pode receber novo claim; os claims antigos permanecem auditáveis.
- ACL de teste permite apenas `INSERT` em colunas explícitas, `SELECT` sujeito a RLS e execução da função; não permite editar o marcador `claimed_at`, nem atualizar/excluir claims.
- Testes negativos cobrem versão/digest incorretos, cross-tenant, revogação, replay e tentativa de forjar timestamp ou entrega.

**Limitações críticas:** claim NÃO é uma entrega ao Portal, aceite do cliente, lease com timeout, autenticação do executor ou autorização. Um claim antigo continua no histórico após revisão posterior e não autoriza processamento. O real consumidor precisará de identidade e autorização oficiais, checagem transacional de versão e revogação no instante do envio, recuperação/idempotência/reconciliação e provas duráveis de recebimento. O `ON CONFLICT` só protege unicidade de claim neste protótipo; não elimina corridas com outra transação que adiciona revisões.

Sem alteração de configuração operacional, custos, AI/n8n, Cloudflare, domínio ou dados reais. Postgres 18 efêmero, nenhuma migration Laravel, `main` inalterada; OP01/OP03/OP05/P0/RF-PEND/AppSec/LGPD permanecem BLOQUEADOS.
