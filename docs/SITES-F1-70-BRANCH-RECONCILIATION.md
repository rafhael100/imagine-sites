# F1-70 — Reconciliação de branch paralela de segurança

A etapa F1-61 (PR #82) foi desenvolvida como branch irmã das etapas F1-62..69, não estando automaticamente na linhagem que concentra o módulo visual e o novo fluxo de revisão de conteúdo. F1-70 incorpora **sem alterações funcionais** os arquivos de F1-61 (verificador PHP, testes PostgreSQL, workflow de laboratório e documentação), preservando a linha mais recente de edição de conteúdo.

A integração exige novamente os testes de PostgreSQL 18 e PHP 8.3/8.4 em runner descartável, além dos testes gerais existentes. Não se deve confundir esta reconciliação de código com merge formal de #82 ou com autorização para produção. Nenhum banco externo, Portal real, rede de serviços, Cloudflare, VPS ou cliente é acessado. A validação HMAC/nonce é **apenas no mesmo banco de laboratório** e não garante atomicidade distribuída.

Os bloqueios de contratos, identidade operacional, aprovação real do cliente, publicação, CRM, backups e segurança final permanecem explicitamente não atendidos. `main` e `dev` permanecem sem modificação.
