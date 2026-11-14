# F1-382 — Revisão editorial persistente, com papéis separados e versões concorrentes

**Projeto:** Imagine Sites. **Escopo:** PostgreSQL18 descartável do GitHub CI, depois das duas admissões fictícias Portal→Sites e das duas prévias visuais da F1-381. Não existe autenticação de operador, cliente ou publisher de produção nesta entrega.

## Sequência funcional

A F1-381 disponibilizou dois arquivos Onepage/Landing em revisão, com hashes SHA-256 dos bytes HTML gerados. A F1-382 usa **esses hashes concretos** e a função SQL `list_own_review_candidates()`, vinculada à identidade da empresa no PostgreSQL, para executar um fluxo editorial persistente:

1. **Editor Imagine** (role PostgreSQL fictícia e restrita): `register_draft(site,sha256)` cria um rascunho versionado apenas para site já admitido da própria empresa.
2. Editor envia versão: `submit_review(site,revision,sha256)` muda para `internal_review` por compare-and-swap SQL.
3. **Revisor Imagine** (outro login SQL): pode usar `request_rework` para invalidar o hash antigo e devolver o texto à edição, ou `approve_internal` para liberar a revisão interna, com verificação do digest exato e da versão corrente.
4. Após a aprovação da equipe, o estado é somente `awaiting_portal_acceptance`. **Não existe função SQL de aprovação de cliente ou publicação nesta fase.**

O schema `sites_f382` mantém `candidates` e `events`. Cada transição grava um evento associado à revisão na **mesma transação**, com `PRIMARY KEY(tenant,site,revision)`. As tabelas não concedem acesso direto aos logins editor/revisor; apenas funções `SECURITY DEFINER` aprovadas por papel. Cada função amarra o contexto ao `session_user` e ao tenant da tabela privada de operadores. Não confia em `tenantRef` fornecido por formulário.

## Testes adversariais

O CI verifica a revisão simples da Onepage (versões 1→2→3), a devolução de Landing para retrabalho (1→2→3→4→5→6), a diferença de hash entre versões e o registro imutável de **9 eventos**. Testa que:

- O editor não consegue aprovar a própria prévia;
- O revisor não consegue criar ou alterar o rascunho;
- Os papéis não conseguem ler/atualizar diretamente as tabelas, nem usar `SET ROLE` para se passar pelo outro;
- Repetição, conflito de revisão, assinatura/digest diferente ou modificação de uma versão já enviada ao Portal são negados;
- Revogação do operador bloqueia novas ações;
- Um site inexistente ou de outra empresa não pode ser registrado;
- Mesmo após a revisão interna, `client_accepted=false` e `publishable=false` são reforçados por constraints SQL. Nenhum cliente é notificado, nenhuma mensagem de aceite é assinada e nenhum domínio é publicado.

O workflow publica no pacote do GitHub um relatório `editorial-review-evidence.json` sanitizado, com os dois estados, números de revisão e bloqueios de publicação, sem IDs de clientes/empresas, dados reais ou senhas.

## Requisitos para operar

Há muita diferença entre uma role fictícia de PostgreSQL e o **IAM real** dos colaboradores Imagine: é necessário construir o vínculo a usuários autenticados por MFA, políticas de revisão/auditoria LGPD, backup, restore, controle de concorrência da aplicação e armazenamento de bytes aprovados. Depois, integrar aceite explícito do cliente no Portal com identidade e versão verificadas, prova externa de revogação e um serviço de publicação isolado.

**NO-GO/DRAFT:** migrações aqui são fixtures sintéticas de CI, não scripts homologados para bancos reais. Não houve merge, deploy, acesso à VPS, envio ao cliente nem integração com IA ou gateway de pagamento.
