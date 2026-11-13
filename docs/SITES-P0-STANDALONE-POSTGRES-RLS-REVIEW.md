# P0 — revisão independente do isolamento PostgreSQL do Imagine Sites

**Subconjunto autocontido contra `dev`. Objetivo: oferecer análise de DBA/AppSec sem aprovar todos os commits do motor Sites. NO-GO produção.**

Este PR pequeno combina um único schema Laravel real, a proteção contra aplicar migration em banco errado, testes PostgreSQL 18 por PHP 8.3/8.4 e documentação. O fluxo de prova é propositalmente sequencial no **mesmo banco descartável**:

1. **F1-93/232** aplica a migration `sites_projects/sites_drafts` apenas sob ambiente `APP_ENV=testing`, `GITHUB_ACTIONS=true`, flag de laboratório e endpoint `127.0.0.1:5432/sites_f93_lab` exato; verifica login de aplicação sem DDL, RLS ativo, restrições e trigger append-only. `down()` destrutivo segue negado.
2. **F1-346** comprova a vulnerabilidade de RLS baseado apenas em `SET LOCAL imagine_sites.tenant_ref` sob usuário SQL com poder de enviar comandos arbitrários. Insere apenas uma linha fictícia em transação revertida.
3. **F1-347** cria, **somente no PG18 descartável**, duas roles independentes e um vínculo privado `session_user`→empresa. Políticas RLS alternativas em laboratório ignoram a GUC, recusam leitura e INSERT cruzados e reconhecem revogação.
4. **F1-349** utiliza `SELECT … FOR SHARE` e comprova via timeout a ordenação de revogação enquanto uma transação que usou a associação ainda está aberta.
5. **F1-348** adiciona trigger de revogação unidirecional e de revisão monotônica, recusando reativação, remapeamento de tenant, regressão de revisão e apagamento do vínculo.

### Limites e riscos

Nenhuma das alternativas de login/trigger é incorporada à migração oficial. As roles por empresa não são uma arquitetura de pooling SaaS homologada; o cenário não autentica usuários do Portal, não distribui autorização para serviços, não gere senhas reais nem cobre todos os modos de falha de concorrência. O experimento só valida conceitos de SQL em PG18 e não deve ser transportado diretamente para produção.

Os checks de banco **não habilitam** os dez gates de `config/operational-readiness.json`. A identidade/revogação Portal #33, banco isolado/backup/RLS #34, governança #281 e OP03–OP05 seguem abertos. Sem Cloudflare, VPS, publicação, dados de clientes, credenciais reais, merges ou custos.

### Revisão recomendada

O DBA deve conferir a função `SECURITY DEFINER`, `search_path`, role e privilégios, RLS FORCE/USING/WITH CHECK, efeitos de `session_user`, lock `FOR SHARE`, tratamento de revogação e limites de pool multiempresa. AppSec deve validar que dados HTTP/Portal jamais controlam a autoridade SQL e que não existem caminhos de bypass por credencial ou service role. Somente após esses pareceres caberá decidir uma implementação realmente operacional.
