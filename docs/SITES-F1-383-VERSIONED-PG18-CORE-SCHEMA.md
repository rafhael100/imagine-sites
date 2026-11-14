# F1-383 — Fundação PostgreSQL 18 versionada para o Imagine Sites

**PR de revisão; instalação e rollback testados somente em um PostgreSQL18 efêmero do GitHub. Nenhuma VPS, cliente, contrato ou gateway de pagamento alterado.**

## Por que é uma entrega importante

As F1-360–382 validaram assinatura, nonce, admissão, outbox, prévias visuais e revisão editorial, mas dependiam de **schemas e passwords inteiramente fictícios** para funcionar. Esses scripts não devem ser copiados para uma base operacional. A F1-383 cria a **primeira migration Laravel 13 propriamente versionada** de um candidato de schema, para começar a substituir os fixtures com uma base revisável e controlada por mudanças.

Arquivo: `apps/sites/database/migrations/2026_11_14_000001_create_sites_core_foundation.php`.

As seis tabelas no namespace `sites_core` são:

| Tabela | Finalidade |
|---|---|
| `tenant_accounts` | Organização, id interno UUID e referência opaca do Portal |
| `contract_refs` | Contrato associado à empresa, produto, revisão e evidências de elegibilidade ainda não verificadas |
| `site_projects` | Projeto Onepage/Landing, contrato, pedido Portal, chave idempotente e digest do comando |
| `replay_nonces` | Proteção global contra reutilização de nonce por emissor, inclusive entre empresas |
| `editorial_revisions` | Versões e hashes de artefatos, com estado draft/review/awaiting Portal |
| `site_outbox` | Registro transacional de evento ProjectQueued, sem habilitar worker/publisher |

## Segurança intrínseca

- `ENABLE ROW LEVEL SECURITY` **e** `FORCE ROW LEVEL SECURITY` nas seis tabelas. **Nenhuma política de RLS operacional é criada.** Um serviço que não tiver um vínculo de identidade revisado não pode ler nem gravar dados de clientes. A migration não cria contas, senhas ou privilégios de operador.
- `authority_state='unverified'`, `external_payment_verified=false`, `official_entitlement_verified=false`, `authority_verified=false`, `client_accepted=false` e `publishable=false` são defaults e **CHECK constraints**, portanto uma simples alteração de coluna não libera o produto.
- O outbox nasce e permanece em `delivery_state='blocked'`. Não existe worker, API de cliente, importação ou publicação operacional.
- FKs compostas `(tenant_id,contract_ref)` e `(tenant_id,site_id)` evitam associação acidental de projeto, artefato ou evento a um contrato/site de outra empresa.
- UUIDs são gerados pela aplicação futura, não por uma extensão SQL ou usuário de banco em produção. Identificadores, chaves e status seguem limites de sintaxe.
- Há índices para recuperação de contrato, revisão editorial e outbox; idempotência única por `(tenant_id,operation_ref)` e `(tenant_id,portal_order_ref)`.

## Evidência de CI

O workflow `sites-f1-383-operational-schema.yml` instala o Laravel com `composer.lock`, executa `artisan migrate --force`, verifica versão PostgreSQL18, existência das seis tabelas e FORCE RLS. Um login não privilegiado de teste não consegue ler ou inserir, nem assumir a role de admin. Testes adversariais verificam falhas de:

- produto diferente de Onepage/Landing;
- pagamento fictício marcado como verificado;
- projeto ligado a contrato de outra empresa;
- operação idempotente duplicada;
- publicação ou autoridade marcadas true;
- aceite de cliente atribuído diretamente;
- outbox ativado sem autorização;
- mesmo nonce reutilizado em outra empresa.

Após validar, o CI executa `artisan migrate:rollback --step=1` em **banco vazio e descartável** e confirma que o schema foi retirado.

## Gates operacionais pendentes

Esta migration é **um candidato para revisão**, e não deve ser aplicada à base ativa da Leaseweb. Ainda precisam existir política RLS autenticada, logins SQL e permissões com menor privilégio, origem de contrato/pagamento oficial, antifraude/revogações, secret vault e rotação, HTTPS/S2S, backups/restauração, auditoria LGPD, controle de releases, monitoramento e aprovação DB/AppSec. O rollback é destrutivo: **em qualquer banco persistente requer aprovação e backup validado antes de executar**, não pode ser um comando automático em produção.

O esquema fica deliberadamente impossível de liberar somente com modificações de dados, exigindo futura migration revisada para abrir cada gate quando a integração real estiver pronta.

**DRAFT / NO-GO / sem merge ou deploy.**
