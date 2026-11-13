# F1-103 — Snapshot candidato Portal vinculado à admissão transacional PostgreSQL

## Integração de laboratório

A F1-102 introduziu uma decisão de autorização candidata e de curta duração. A F1-103 executa esse gateway **contra o serviço existente** \`LabAuthorizedProjectAdmissionStore\`, que grava projeto e admissão idempotente nas tabelas Laravel de um PostgreSQL 18 descartável.

O novo cenário é integrado ao teste \`tests/f94-pg-authorized-project-admission.php\` e utiliza uma fonte de snapshots estritamente em memória. Ao testar \`tenant-f103-c\` isoladamente, comprova que:

- sem flag de laboratório ou sem snapshot, a admissão é negada e nenhuma linha é criada;
- snapshot com tenant, operação, pedido, contrato/revisão, produto, idempotência, status \`eligible\` e validade corrente permite exatamente uma admissão;
- snapshot posteriormente \`revoked\` impede até mesmo replay idempotente;
- snapshot expirado impede replay, apesar da linha existente;
- manter tenant/pedido e alterar operação não concede autorização;
- restabelecer snapshot corrente exato permite replay sem duplicar registros.

Todos os resultados continuam \`portalIdentityOperational=false\`, \`officialClientAcceptance=false\` e \`publishable=false\`.

## Escopo e limites

A prova usa PostgreSQL18 efêmero, login não privilegiado \`sites_f94_app\`, RLS com o contexto privado local e fonte Portal falsa. O workflow existente \`sites-f1-94-project-admission.yml\` executa os testes PHP8.3/8.4. A GUC de tenant é forjável se a conta SQL puder receber consultas arbitrárias: este teste NÃO autentica empresa ou uma pessoa, não executa HTTP, não constitui aceite e não se conecta ao Portal real. O uso futuro requer adaptador autenticamente Portal, rede segregada, revisão AppSec, contrato assinado, revogação, auditoria e homologação independente.

Sem merge, deploy, clientes, segredos, DNS ou custos. **10/10 gates de produção permanecem BLOCKED.**
