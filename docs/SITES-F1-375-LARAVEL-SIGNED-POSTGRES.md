# F1-375 — Laravel 13 conectado a PostgreSQL 18: assinatura, nonce, admissão e outbox

**Situação: integração técnica de CI; NÃO é criação de projetos operacional.**

## O que funciona

O novo serviço `apps/sites/app/Labs/LaravelSignedAdmissionLab.php` executa, dentro de **uma transação Laravel**, a verificação da assinatura de comando de laboratório, o consumo de nonce por função SQL `remember_nonce`, a avaliação do gateway de autorização e a chamada a `admit_project`, que insere o comando e outbox em uma única operação PostgreSQL18.

A verificação SQL confere `current_database()` e `session_user` para exigir a sessão de banco **realmente autenticada** como login `sites_f360_b`; o tenant assinado precisa corresponder ao vínculo registrado no banco, e alteração de associação/revogação derruba todas as tentativas. O esquema F1-360/361/363, chaves, contas e vínculos são **inteiramente fictícios**; a importação acontece apenas em contêiner PostgreSQL descartável do GitHub Actions. O usuário SQL não recebe permissões diretas de INSERT/UPDATE nas tabelas protegidas.

As condições de execução exigem `PHP_SAPI=cli`, ambiente `testing`, flag CI explícita, `GITHUB_ACTIONS=true`, hostname/porta e usuário SQL fictícios. O gateway oficial do Portal é **negador por padrão**. A suíte usa uma classe de aprovação fictícia *exclusivamente no próprio teste* e jamais registra essa classe no container de serviços para HTTP. Não existe rota HTTP para admitir projetos.

## Critérios automáticos

- Admissão Onepage e Landing Page com nonce persistente e outbox no mesmo commit
- Replay com o mesmo nonce negado; nova assinatura com nonce diferente deduplicada sem criar outro projeto
- Conflito de conteúdo sob idempotency key existente negado por PostgreSQL; nonce novo não é consumido
- Falha de gravação do outbox desfaz admissão e nonce, permitindo retry idêntico
- Assinatura HMAC com payload adulterado não produz efeito
- Comando assinado para outro tenant é negado pela role de banco autenticada, mesmo com aprovação fictícia no gateway
- Revogação do binding SQL impede escrita e preserva rollback do nonce
- Ausência da autoridade Portal, flag de CI e conexão admin impedem uso
- As rotas de produção/contratação continuam ausentes

Execução CI: `.github/workflows/sites-f1-375-laravel-signed-pg18.yml` provisiona PostgreSQL 18 descartável, importa os esquemas de laboratório e executa PHPUnit Laravel 13 em PHP 8.3 e 8.4.

## O que ainda não está integrado

É **vetado converter essa fixture em API pública ou migração produtiva**. Ainda faltam contratos/pagamentos oficiais do Portal, identidade S2S real, proof de revogação de autoridade independente, chaves no vault/rotação, contratos multiempresa operacionais, TLS PostgreSQL, política de backup externa, LGPD, revisão de segurança, releases/publisher e credenciais de produção. Este serviço apenas valida a integração de camadas Laravel→SQL, sob guardas explícitas, **não processa uma contratação real**.

PR continua DRAFT/NO-GO; sem merge, custo, VPS, Cloudflare, DNS, dados pessoais ou servidor público.
