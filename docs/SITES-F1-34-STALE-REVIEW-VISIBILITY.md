# F1-34 — Proteção contra propostas obsoletas antes de envio ao Portal

Este laboratório define uma **view de leitura estrita**, `lab_sites.current_synthetic_review_proposals`, que mostra somente as propostas F1-33 cuja revisão da F1-32 ainda é a **última revisão** do respectivo fluxo e contém o SHA-256 aprovado internamente.

O uso de `WITH (security_invoker=true,security_barrier=true)` é obrigatório para aplicar RLS dos dados subjacentes conforme o papel SQL efetivo, evitando que a view leia como o owner privilegiado. Não concede `UPDATE/DELETE` nem faz dispatch.

## Comportamentos verificados no PostgreSQL 18 descartável

- A proposta no último `internal_approved` aparece para a role fictícia da própria empresa.
- Outra empresa e uma identidade sem concessão não enxergam a proposta.
- Se a produção volta para ajustes, a proposta antiga **desaparece da view**, mas é preservada no histórico append-only.
- Uma nova revisão aprovada com digest diferente expõe somente a proposta atual, sem misturar versões.
- Rejeita atualização indevida da view e, após revogação fictícia, oculta o resultado também para a empresa antes autorizada.

**Limitação decisiva:** a view não é um serviço de entrega nem uma trava de concorrência entre a leitura e o uso. A implementação real terá de realizar checagem transacional/autorização de contrato e revogação imediatamente antes de qualquer envio, revalidar as evidências do Portal e lidar com restore e falhas de rede. O fato de uma proposta aparecer na view não comprova autorização por cliente, pagamento, identidade de serviço ou direito de publicar.

Sem dados pessoais, n8n, API externa, Cloudflare, Leaseweb, DNS, custos ou acesso real a serviços. P0/OP03/OP05/RF-PEND/RV-CT, LGPD/AppSec permanecem abertos; `config/operational-readiness.json` segue BLOCKED. `AGI-11-RB01` desabilitado. A `main` não foi alterada.
