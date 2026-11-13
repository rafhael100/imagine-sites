# F1-104 — Autoridade atual na admissão e no replay de projeto

## Mudança

\`LabAuthorizedProjectAdmissionStore::admit()\` já consultava o gateway \`PortalProjectAuthorityGateway\` antes de qualquer operação no banco, mas a autoridade poderia ser revogada durante a transação. A F1-104 exige uma **segunda avaliação independente** dentro da transação, imediatamente antes do \`COMMIT\`, tanto na inserção transacional de \`sites_projects\` e \`sites_project_admissions\` quanto no replay idempotente existente.

Uma negativa ou exceção na segunda avaliação aciona \`lab_project_admission_final_authority_denied\` e rollback. O caminho de criação deve desfazer **as duas linhas**; o caminho de replay não pode retornar sucesso sob revogação.

## Testes

O teste PostgreSQL18 \`tests/f94-pg-authorized-project-admission.php\` injeta revogação entre as duas chamadas ao gateway Portal fictício e verifica ausência de projeto e admissão após rollback. Repete para a operação já admitida, exige negativa sem alteração da trilha, e confirma que uma concessão fictícia corrente permite novo replay exato. As matrizes PHP8.3/8.4 são executadas pelo workflow F1-94. A F1-103 cobre, adicionalmente, a implementação candidata de snapshot F1-102 ligada ao mesmo serviço.

## Segurança e limites

O gateway usado continua fictício (default DENY), a flag CLI/testing continua obrigatória, e uma segunda checagem não evita revogação após o commit nem cria identidade ou autorização reais. O contexto PostgreSQL \`imagine_sites.tenant_ref\` é manipulável com SQL direto; nenhum agente/cliente recebe credencial do banco. Sem IAM operacional, assinatura, aceitação, publicação, VPS, DNS, custo ou merge. **10/10 gates operacionais BLOCKED.**
