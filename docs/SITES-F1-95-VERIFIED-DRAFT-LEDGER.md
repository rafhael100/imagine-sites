# F1-95 — Persistência de revisões verificadas do site (Laravel + PostgreSQL 18)

## Implementação funcional em ambiente de teste

Esta etapa conecta o projeto admitido na F1-94, os arquivos privados de revisão F1-86/87 e a tabela **real** `sites_drafts` criada pela migration Laravel F1-93. A operação é efetuada pelo serviço interno `LabVerifiedDraftRevisionStore`; nenhuma API HTTP, publicação, preview de cliente ou migração nova foi introduzida.

O método `append(command, draft, directory, expectedRevision)` exige PHP CLI, `APP_ENV=testing`, ambas flags `SITES_F94_PROJECT_ADMISSION_LAB=1` e `SITES_F95_DRAFT_LEDGER_LAB=1`, banco PostgreSQL efêmero de nome fixo e conta restrita sem `SUPERUSER`/`BYPASSRLS`. A autorização `PortalProjectAuthorityGateway` é obrigatória e **nega por padrão**, inclusive em repetições de operação.

A referência do site é derivada da empresa/pedido/contrato pela F1-94 e precisa coincidir com o draft. Antes do banco, o método reabre os **quatro arquivos privados**, valida bytes canônicos, QA e hashes do HTML, release, bundle e dossiê, exige estado de revisão interna pendente e reconstitui o dossiê a partir do draft.

Somente após a autorização sintética explícita abre uma transação, define o contexto de empresa com `SET LOCAL`, adquire um **advisory lock PostgreSQL por empresa/site** e confirma no banco que projeto + admissão persistidos correspondem exatamente a operação, pedido, contrato, revisão, produto e fingerprint autorizados. O lock serializa as escritas feitas **por esse serviço** no laboratório; não protege contra acesso SQL direto, ausência de IAM do Portal ou outras aplicações que ignoram o protocolo.

- A primeira revisão tem número 1; as seguintes exigem sequência 2, 3 etc.
- A tabela preserva cada revisão como fato imutável e recusa mudança/deleção; repetir **apenas a revisão mais recente** com os mesmos hashes, workflow e release retorna resultado idempotente sem reescrever.
- Reutilizar número com outro artefato, pular revisões, reutilizar `releaseRef`, trocar empresa, mudar HTML/dossiê em disco, usar role privilegiada ou revogar a autoridade retorna erro e rollback.
- Antes do INSERT, reabre o pacote no disco para conferir que não mudou durante a verificação. Isso **não** é garantia de snapshot transacional entre filesystem e PostgreSQL.
- O registro armazena **somente identificadores e hashes**, não o conteúdo, a imagem, o briefing ou dados de clientes. O resultado sempre contém `officialClientAcceptance=false` e `publishable=false`.

## Testes automatizados

O workflow já existente `sites-f1-94-project-admission.yml` passa a testar **F1-94 + F1-95** após executar migrations Laravel F1-93/F1-94 em PostgreSQL 18 efêmero, PHP 8.3 e 8.4. `tests/f95-pg-verified-draft-revisions.php` cobre ausência de autorização/projeto, recusa de reenvios incorretos, integridade de dois pacotes privados e suas duas revisões, isolamento entre tenants, arquivo adulterado, sequência, inalterabilidade, rollback, estado de revisão e ausência de vazamento da variável transacional.

## Limitações decisivas

**`imagine_sites.tenant_ref` é uma GUC PostgreSQL que pode ser modificada por qualquer acesso SQL com o login da aplicação. Ela não autentica ninguém.** O RLS só adiciona uma barreira quando um serviço autenticado no Portal controla todas as queries e tokens privados. Um sistema real precisará de confirmação da identidade/autorização por recurso no Portal, eventos/revogação reais, separação de ambientes/roles, storage imutável e privado, política de backup e retenção, revisão independente AppSec e monitoramento de concorrência. Um administrador privilegiado pode desativar triggers ou políticas: append-only não é imutabilidade criptográfica.

Os dez gates operacionais de `config/operational-readiness.json` permanecem **BLOCKED**. Esta etapa não executa em produção, não cria segredo, não usa custo externo, não publica, não fecha aceites de clientes e não altera `dev`/`main`.
