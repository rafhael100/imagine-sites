# F1-50 — Validação de autorização de revisão em PostgreSQL descartável

Complementa a assinatura HMAC F1-45, o replay F1-47 e a interoperabilidade Portal F1-48/Sites F1-49 com um **gateway de leitura SQL de laboratório** para versões/autorizações de propostas de revisão. Nenhuma prova de cliente/contrato real é declarada.

O PostgreSQL 18 efêmero usa o schema F1-05 com empresas e roles sintéticas, e acrescenta `lab_sites.current_review_authorities`, consultado somente por `LabPgCurrentReviewAuthorityGateway`. O adaptador exige PHP CLI, flag `SITES_F50_AUTH_LAB=1`, DB `sites_lab`, driver PostgreSQL, role distinta e RLS. Os campos imutáveis precisam coincidir com o workflow, proposta e SQL atual: empresa, site, workflow, revisão, release e digest SHA-256. A própria role não pode modificar a autoridade.

O trigger de tabela impede diminuição de `workflow_revision`/ `authority_revision`, mudança de release/bytes sem nova revisão e **reativação de uma revisão que já foi revogada**. Uma autorização recuperada só pode se aplicar a uma **nova revisão e novo digest**. Testes PH P 8.3/8.4 comprovam revogação, recusa após restabelecimento de uma revisão antiga, alteração de conteúdo, cross-tenant, role sem permissão SQL e ausência da flag.

**Limite importante:** essas roles e tabelas são fictícias, não são os bancos oficiais do Portal. O teste não implementa IAM, mTLS, entitlement comercial, contrato, cliente, certidão de aceite nem integração remota. O trigger só é monotônico **dentro do banco atual**; restauração total de um backup antigo exige barreira externa independente, como discutido em F1-22. Não usar este esquema em produção.

Sem alteração `main`, não publica, não acessa Leaseweb/Cloudflare/CRM, não utiliza dados reais ou novos fornecedores. Readiness BLOCKED; OP01/03/05, RF-PEND/RV-CT, AppSec/LGPD seguem abertos.
