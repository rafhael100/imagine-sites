# F1-94 — Admissão de projetos com idempotência persistente e autorização obrigatória

Esta etapa transforma o contrato candidato `SiteProjectCommand` em uma primeira **gravação transacional real** de projeto no Laravel/PostgreSQL 18 descartável. Não é um webhook do Portal, API pública, lançamento de site ou autorização operacional.

## Componentes

A segunda migração `2026_11_10_000002_create_sites_project_admissions.php` cria a tabela `sites_project_admissions` com empresa, site, operação, chave de idempotência, pedido, contrato, revisão do contrato, produto e hash semântico. O projeto da mesma empresa é referenciado por chave estrangeira composta; existem índices únicos por empresa+operação, empresa+chave de idempotência e empresa+site. `ENABLE/FORCE RLS` e trigger contra UPDATE/DELETE impedem alterações comuns no ledger. Um operador de banco com privilégios de superusuário ainda pode alterar mecanismos de segurança: a tabela **não equivale a armazenamento imutável criptograficamente**.

`LabAuthorizedProjectAdmissionStore::admit` recebe o comando tipado, o identificador de site gerado internamente e um `PortalProjectAuthorityGateway` independente. Seu gateway padrão nega todas as chamadas. O processo exige `APP_ENV=testing`, CLI e `SITES_F94_PROJECT_ADMISSION_LAB=1`, banco de nome fixo `sites_f94_lab` e login restrito `sites_f94_app`, sem superusuário ou `BYPASSRLS`. Uma decisão negativa interrompe o procedimento **antes de qualquer consulta ou transação**.

Após autorização sintética explícita, a operação cria a transação, define o contexto do tenant com `set_config(..., true)` somente nessa transação e pesquisa a chave de idempotência sob RLS. Se houver registro anterior, ela exige correspondência exata de empresa, site, operação, pedido, contrato, revisão, produto e fingerprint; somente assim retorna uma resposta idempotente, sem gravar outro registro. Se não houver, insere projeto e admissão na **mesma transação**. Conflitos de chaves, falhas ou operações concorrentes não são convertidas em sucesso: o banco faz rollback e o chamador pode tentar novamente com a **mesma operação autorizada**, sujeita a nova checagem de autoridade.

O resultado é sempre informativo, com `labOnly=true`, `portalIdentityOperational=false`, `officialClientAcceptance=false` e `publishable=false`. Nenhum endpoint HTTP foi adicionado.

## Verificação

O workflow `sites-f1-94-project-admission.yml` executa as duas migrações Laravel em PostgreSQL 18 efêmero, testa a negativa por falta de opt-in e repete o teste com PHP 8.3/8.4. Após criar uma conta com `SELECT/INSERT` apenas, valida o bloqueio por padrão, falta de autorização, criação transacional, repetição exata, conflitos por chave/operação/site, empresa cruzada, revogação do gateway antes de repetição, role administrador recusada, rollback, contexto de banco sem vazamento e proibição de rollback destrutivo. A base é descartada no fim do CI.

## Limitação crítica de segurança

A variável `imagine_sites.tenant_ref` é um parâmetro customizado do PostgreSQL: **quem possui acesso SQL à conta da aplicação consegue alterá-la**, inclusive para outra empresa. RLS baseado nessa variável não autentica clientes e **não pode ser exposto** a navegador, clientes, agentes IA, fornecedores ou SQL arbitrário. O teste demonstra expressamente esse limite para impedir que a F1-94 seja interpretada como prova de isolamento operacional por si só. Um adaptador real deve autenticar a origem do Portal, validar pedido/contrato e revogação atuais por recurso, usar conexões privadas com autenticação de serviço e restringir rigidamente o acesso a consultas SQL.

Faltam integração confiável com Portal, plano financeiro/cobrança, aceites expressos, armazenagem e publicação Cloudflare, CRM real, backup e restore operacional, revisão AppSec/LGPD, observabilidade, domínio, Leaseweb e gates legais. O `config/operational-readiness.json` permanece com **10/10 requisitos BLOCKED**. Nenhuma migração em produção, despesa, merge ou deploy é autorizada.
