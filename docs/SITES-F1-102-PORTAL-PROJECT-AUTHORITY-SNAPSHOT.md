# F1-102 — Snapshot candidato de autorização Portal → Sites para criação de projeto

## Implementação

O contrato \`SiteProjectCommand\` (empresa, operação, pedido, contrato/revisão, produto e idempotência) agora pode ser conferido em um **snapshot imutável de laboratório**: \`CurrentPortalProjectAuthorizationSnapshot\`. Exige autorização com revisão positiva, status \`eligible\`, instante de conferência não futuro e validade de no máximo 30 segundos. Vínculos são comparados **campo por campo**, incluindo operação e idempotencyKey, que não fazem parte do fingerprint semântico.

\`SnapshotBoundPortalProjectAuthorityGateway\` implementa o contrato candidato \`PortalProjectAuthorityGateway\`, mas está desabilitado por padrão e funciona somente quando \`PHP_SAPI=cli\`, \`APP_ENV=testing\` e \`SITES_F102_PROJECT_SNAPSHOT_LAB=1\`. Sua fonte \`CurrentPortalProjectAuthorizationSource\` retorna null por padrão (\`DenyingCurrentPortalProjectAuthorizationSource\`). Quando o snapshot está ausente, expirado, futuro, suspenso, revogado ou diferente, nega a operação. Uma falha da fonte também nega.

Nenhuma fonte Portal operacional foi conectada: os cenários usam exclusivamente fonte falsa criada em memória.

## Testes e integração

\`tests/f102-portal-project-snapshot.php\` cobre: desativação por padrão, fonte default DENY, elegibilidade e blockers, troca de operação, empresa, pedido, contrato, revisão, produto e idempotência, revogação, suspensão, prazo vencido, instante futuro, TTL excessivo, revisão inválida, fonte indisponível/erro e renovação da evidência.

O workflow \`.github/workflows/synthetic-php.yml\` executa esse teste com PHP 8.3 e 8.4 sem banco nem rede. O gateway é interoperável com \`SiteProjectAdmission\`, mas ainda não está habilitado como fonte real de \`LabAuthorizedProjectAdmissionStore\`.

## Limitações e gates

Um objeto de snapshot em memória **não prova origem, identidade do operador, assinatura, revogação em tempo real, vínculo de contrato de verdade ou direito de publicação**. O futuro adapter do Portal exigirá autenticação de serviço, fonte independente, revisão/epoch de revogação, autorização por recurso, trilha de auditoria e testes de integração reais. Esta etapa não armazena PII, não abre HTTP, não conecta CRM, não altera Cloudflare/VPS, não realiza merge nem go-live. Os **10 gates de produção permanecem BLOCKED**.
