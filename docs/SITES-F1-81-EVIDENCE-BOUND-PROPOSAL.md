# F1-81 — Proposta informativa vinculada à decisão persistida (laboratório)

A F1-81 conecta o rascunho da F1-71 ao dossiê F1-77, à decisão editorial F1-78 e ao banco PostgreSQL 18 descartável F1-79/F1-80. Antes da criação de uma **proposta meramente informativa** ao Portal, exige: decisão interna sintética de aprovação (não retrabalho), snapshot da versão exata, mesmo tenant/site/workflow/release e hashes de HTML, pacote e checklist; releitura do registro efetivamente persistido no banco sob a role restrita correspondente ao tenant.

`LabEvidenceBoundReviewProposal::prepare` verifica os metadados locais e a evidência persistida, e então cria um candidato informativo a partir do fluxo já revisado. Os campos `externalPortalAuthorityStillRequired=true`, `syntheticOnly=true`, `officialClientAcceptance=false` e `publishable=false` não podem ser revertidos pelo código desta etapa. A saída não contém URL de publicação, endpoint ou credencial.

O teste do PG18, em PHP 8.3/8.4, rejeita decisões não persistidas, leitura por tenant errado/administrador, dossiê ou rascunho de outro site, decisão de retrabalho, ausência da flag de laboratório e proposta antiga após mudança de revisão. Executa como terceiro script da matriz de PostgreSQL da F1-79, após a inserção e a conferência do ledger.

**Limites:** não há integração via rede com Portal, nem identidade/IAM, revogação operacional, assinatura de origem, entrega ou aceite expresso do cliente. A revisão técnica e as integrações reais continuam necessárias. Os dez gates operacionais seguem BLOCKED. Não é um endpoint público nem um comando de aprovação.
