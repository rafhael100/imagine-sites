# F1-36 — Reserva sintética de créditos de IA em PostgreSQL (sem fornecedor)

O módulo anterior F1-30 possui apenas contadores em memória. Esta etapa ensaia persistência no PostgreSQL 18 **descartável** do GitHub Actions, com limites de créditos abstratos por empresa fictícia e uma tabela de reservas append-only.

## Comportamento ensaiado
- Orçamento mantido em tabela acessível apenas ao proprietário do banco fictício; runtime pode ler as **próprias reservas**, não editar saldos ou histórico.
- `reserve_synthetic_credits()` atua como função SQL `SECURITY INVOKER`; RLS confere empresa ativa e concede visibilidade somente à role de laboratório válida. Revogação remove a visibilidade e bloqueia nova reserva.
- Uma chave `(tenant_id,operation_ref)` garante idempotência. Repetição com mesmos workflow/créditos retorna sucesso sem nova cobrança fictícia. Mesma chave com valor ou workflow diferente retorna falso.
- Um trigger de laboratório, executado com privilégio de proprietário SOMENTE para consultar orçamentos e reservas, adquire `pg_advisory_xact_lock` por tenant. Depois soma os créditos existentes e rejeita despesas fictícias que excedam o limite, mesmo com conexões SQL concorrentes.
- CI realiza testes negativos de invasão de tenant, mudança de data, UPDATE/DELETE, quota, revogação, e uma corrida entre **duas conexões independentes** tentando consumir a totalidade de 3 créditos disponíveis: somente uma pode receber sucesso.

## Limitações e segurança

**Isto não é medição, aprovação de crédito comercial, saldo financeiro, cobrança ou rate limiting de um provedor real.** Ninguém conectou APIs, n8n, modelos pagos ou dados de clientes. O trigger `SECURITY DEFINER` é uma simplificação sintética do laboratório, não um mecanismo pronto para produção; requer revisão de segurança, privilégios mínimos, migração/controlador transacional aprovados, observabilidade, trilha de uso e reconciliação com respostas reais do provedor.

Os números do laboratório são inteiramente fictícios e não representam preços da Imagine. Não cria gasto. Não publica site nem conclui OP05. `main`/Cloudflare/Leaseweb/CRM não alterados; P0, RF-PEND/RV-CT, AppSec/LGPD e `config/operational-readiness.json` seguem BLOQUEADOS.
