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

### F1-350 / F1-351 — verificações adicionais de privilégio mínimo (13/11/2026)

Na fixture descartável F1-347, dois logins SQL independentes agora exercitam os seguintes cenários negativos:

- **F1-350:** SELECT/INSERT permitidos, UPDATE/DELETE e CREATE negados; `SET SESSION AUTHORIZATION` entre empresas negado.
- **F1-351:** login de empresa não pode assumir a role `postgres`, desabilitar RLS, excluir a policy isoladora, remover a função `SECURITY DEFINER` nem conceder acesso ao mapping privado; modificar `search_path` da sessão não altera o vínculo de tenant da função com caminho seguro.
- Tudo permanece dentro de `tests/f347-pg-login-scoped-core-rls.php`, invocado na matriz PostgreSQL 18 / PHP 8.3 e PHP 8.4. Sem endpoints reais, credenciais operacionais ou dados de clientes.

**Evidência concluída:** no commit `1f507a77e95c2efb75cad2c0e53f7fc41e1a7b2f`, os jobs PostgreSQL 18 / PHP 8.3 e 8.4 e o guard anti-deploy concluíram com `success`: [run PG18 #43474548661](https://github.com/rafhael100/imagine-sites/actions/runs/43474548661), [guard #43474548582](https://github.com/rafhael100/imagine-sites/actions/runs/43474548582).

**Evidência F1-351 confirmada:** commit `a9b59b03f51fedd30ae96c2789d4603e64e263d6`, [run #43477143147](https://github.com/rafhael100/imagine-sites/actions/runs/43477143147), PostgreSQL 18 PHP 8.3/8.4 `success`, incluindo F1-350/351, F1-349 e F1-348. O [guard anti-deploy #43477143187](https://github.com/rafhael100/imagine-sites/actions/runs/43477143187) também terminou em `success`. O commit posterior de documentação não modifica os testes e não substitui uma execução CI para o código que vier a ser integrado.

**Riscos ainda abertos:** RLS por GUC no schema Laravel oficial continua falsificável por executor SQL arbitrário; roles individuais são somente um desenho de laboratório; o controle real Portal↔Sites, secrets, restauração/revogação cross-service, pooling, parecer DBA/AppSec e proteção das branches continuam pendentes. **NO-GO:** nenhuma migração destas políticas alternativas para produção sem projeto aprovado e homologação operacional.

### F1-352 — impedir shadowing via tabelas temporárias (13/11/2026)

Além de revogar CREATE no schema público, o laboratório agora revoga `TEMPORARY` de `PUBLIC` no banco descartável. Dois novos testes negativos tentam criar tabelas temporárias com o mesmo nome de `sites_projects` e do mapping privado. A finalidade é evitar que `pg_temp` encubra relações confiáveis quando uma aplicação usa nome SQL não qualificado. **Escopo:** somente o banco CI `sites_f93_lab`, sem migração operacional. Alteração de código: `da3f98e1e7975aa63f9fce4815df24eb62b51942`; **aprovada na matriz PG18 PHP 8.3/8.4**, [run #43478011772](https://github.com/rafhael100/imagine-sites/actions/runs/43478011772), commit de documentação `31d0153` contendo o teste F1-352.

### F1-353 — autenticação SQL sem acesso implícito ao banco (13/11/2026)

O mesmo banco descartável de CI agora revoga `CONNECT` de `PUBLIC` e concede acesso explícito apenas aos dois logins fictícios associados, preservando privilégio do administrador da fixture. Um terceiro login fictício `sites_f353_unbound`, criado sem vínculo, não obtém acesso nem com senha válida. O teste verifica tanto `has_database_privilege(...,'CONNECT')` como a recusa real da conexão.

O objetivo é reduzir os caminhos de acesso por logins não cadastrados além das garantias de isolamento RLS. É apenas **defesa em profundidade para PG18 descartável**: não provisiona roles reais por tenant, não implementa identidade Portal↔Sites, não valida pooling ou rotação de credenciais. Alteração `8da405f32efeb266d53c3559de67cf3c668a7ee1`; **PG18 PHP 8.3/8.4 aprovado** no [run #43480169102](https://github.com/rafhael100/imagine-sites/actions/runs/43480169102). **NO-GO operacional mantido.**

### F1-354 — prova negativa de revogação após restauração do mesmo banco (13/11/2026)

O trigger F1-348 nega UPDATE e DELETE de vínculo revogado, mas **não impede TRUNCATE + reintrodução da versão antiga por um administrador/restore com privilégios superiores**. O teste em `tests/f348-pg-monotonic-login-revocation.php` agora demonstra, em uma transação de banco descartável, que uma associação B revogada pode ser reconstruída localmente como `active=true, revision=1` por operação equivalente a restauro de tabela. Faz ROLLBACK antes do final e confirma que ambos os logins permanecem revogados. Assim a prova não persiste nenhuma reativação.

**Consequência arquitetural P0:** a fonte de revogação deve ficar **fora do mesmo domínio de restauração dos dados do Sites**, com epoch/revision verificada a cada admissão, bloqueio de tokens e sessões antigas e teste de restore integral. Não se pode tratar o trigger de laboratório como proteção suficiente. Este teste é propositalmente negativo e **não permite go-live**. Commit inicial `8835e118c3a7d988564a638516e0072257fb79e6`; requer CI no SHA atualizado.
