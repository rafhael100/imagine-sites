# P0 — preparação de homologação privada Sites

DRAFT / NO-MERGE / NO-PRODUCTION-DEPLOY / NO-CHARGE / NO-PUBLISH.

## Estado atual — execução REAL na Leaseweb, 16/11/2026 04:34–04:37 UTC

Após o usuário reconectar o console autorizado, o acesso noVNC root funcionou. Este resultado substitui o bloqueio de console relatado no histórico abaixo. SSH continua ausente no executor.

- Inventário fresco: 50 contêineres preexistentes, 15 redes, 43 volumes; 8 vCPU, aproximadamente 22 GiB de RAM disponível, 273 GiB livres e carga baixa. A porta **127.0.0.1:18090 estava livre** e foi escolhida. CRM18081 e todos os demais serviços preservados.
- Projeto **imagine-sites-p0-staging efetivamente iniciado**: Laravel/PHP-FPM e PostgreSQL18 healthy; Nginx running. Apenas o proxy publica **127.0.0.1:18090→8443**. PostgreSQL/FPM e listener mTLS9443 não possuem bind público.
- Redes próprias ingress, web_private, db_private, s2s_private; volume database próprio, prefixados pelo projeto. App sem rota externa; recursos limitados. Build PHP limitado a dois jobs após inventário. Imagens PHP/Nginx/Composer com bases pinadas; postgres18 ainda usa tag, pinagem de digest permanece requisito para release reproduzível.
- Arquivos privados exclusivos em /srv/imagine/sites-p0-private (root0700), sem reutilizar credenciais de outros projetos. APP_ENV=staging/debug=false. Chaves privadas CA não montadas. Nenhum principal/certificado cliente Portal emitido ou inscrito: mapa mTLS permanece negador.
- Migrations001/002/003 aplicadas somente ao novo banco sites_staging. Runtime sem superuser/BYPASSRLS/DDL e sem vínculo de empresa; FORCE RLS e negativas de leitura/DDL comprovadas pelo verificador dentro do app.
- HTTPS com CA/hostname verificados: painel e /up200 com acesso nominal; sem autenticação401; rotas privadas e criação de projeto404 no ingresso de navegador. Nenhum bypass de certificado utilizado.
- Comparação de IDs, running, StartedAt e RestartCount dos **50 contêineres anteriores: inalterados**, tanto após startup quanto após restore.
- Backup real cifrado AES256-CBC/PBKDF2 com HMAC-SHA256 separado, autenticado antes da decifragem. Restaurado no novo banco sites_restore_verification, acesso público revogado: **oito tabelas FORCE RLS preservadas**. Backup local; cópia externa, retenção/RPO/RTO e recuperação de dados de negócio/revogação ainda não homologados. Não houve DROP nem limpeza destrutiva.
- Preflight original leaseweb-readonly-preflight.py --probe-host manteve blocked. Laravel sites:homologation-preflight também executado no app real: database_probe_attempted=true, PostgreSQL18/menor privilégio verificados, status=blocked, can_publish=false e can_accept_real_orders=false. URL técnica confiável, TLS PostgreSQL (prefer não é verify-full), IAM Portal, contrato/pagamento, revogação, backup externo, aceite e publisher continuam bloqueados.

### Evidências e versões

Código hospedado no worktree /srv/imagine/sites-staging-prep-20261116 em **04c6a29b428c5a9d388c0144511156a29fb6218a**. Imagens app/proxy construídas de a6877bbaf254f03410ec142a3556c5c5aa0f8eb7; alterações seguintes apenas adicionam/corrigem wrappers host. Startup registrado às04:34:55 UTC com wrapper c3364e94ceb57158bb680653beda0e888c747e97; restore às04:36:15 UTC com04c6a29. Os13 check runs recuperados de04c6a29 passaram (push/PR duplicados, não13 suítes distintas), incluindo [infraestrutura CI43804983643](https://github.com/rafhael100/imagine-sites/actions/runs/43804983643).

Evidências sanitizadas persistem no diretório privado evidence: host-start.json, backup-restore.json e laravel-readiness.json. Segredos, tokens de console e dumps não foram publicados. A inspeção inicial do JSON Laravel por python falhou por aspas na digitação no console; leitura direta do arquivo confirmou o resultado bloqueado, sem repetir migrações ou alterar gates. O wrapper recebeu stdin=DEVNULL para evitar suspensão por entrada de terminal em execução em background; startup retomado em foreground concluiu com êxito.

### Acesso pelo navegador e próximos gates

O endpoint técnico existe somente no loopback da VPS. **Não existe URL pública nova nem fluxo de compra/aceite navegável liberado.** Abrir localhost no computador do cliente não acessa a VPS. É necessário um túnel SSH/VPN já autorizado, confiança na CA privada e entrega segura da credencial nominal; nenhum desses acessos externos foi criado nesta rodada. Não ignorar alertas de certificado, publicar o listener ou compartilhar senhas no chat/GitHub.

Próxima sequência: acesso privado confiável → TLS PostgreSQL verify-full → identidade Portal S2S e autorização por empresa → Asaas Sandbox e fonte independente de liquidação → contrato e aceite humano da versão exata → revogação independente → prévia/editorial/publisher e rollback. Os adaptadores sintéticos do laboratório não foram promovidos. Sem dados de cliente, cobrança, produção, DNS, merge ou publicação de sites.

### Operação dos scripts

prepare-private-host.py exige root e diretório novo; gera somente recursos Sites e rejeita colisões. host-operation.py start revalida inventário/porta imediatamente antes de iniciar, aplica schema só no banco novo e verifica isolamento. host-operation.py restore cria banco de verificação novo e não sobrescreve banco existente. Esses comandos não são receitas para repetir cegamente no ambiente já criado: preservar evidências/volumes, inspecionar estado e não recriar segredos/roles. Política de rollback abaixo continua obrigatória.

## Histórico da preparação anterior (superado pela execução abaixo)

Base Sites481: b7908e37328d645e40e3377cfd4796abe38c0844, DRAFT não merged. Portal759:975bb83a1937906b5b0bf33036410f99a553394f, DRAFT não merged. Preservados o Compose antigo e os projetos CRM/Portal/Performance. Nenhum arquivo desses outros repositórios foi alterado por esta preparação.

A console noVNC previamente autenticada encerrou-se. No retorno desta rodada, não há abas/console ativa nem SSH/agent/config/chaves padrão no executor. Portanto, **não foi possível atualizar o inventário da VPS, escolher uma porta comprovadamente livre ou iniciar serviços nela**. A porta18081 permanece excluída por ser do CRM, conforme evidência anterior da issue38; não foi liberada nem alterada. Não reutilizar token antigo de console nem pedir senha/chave privada pelo chat.

## Configuração preparada

`ops/staging/compose.yaml` é um projeto novo: **imagine-sites-p0-staging**, com app Laravel/PHP-FPM, proxy Nginx e PostgreSQL18. Banco `sites_staging`, volume próprio `imagine-sites-p0-staging_database`; sem porta publicada de PostgreSQL ou FPM. Não há networks/volumes external, container_name, subnets fixas ou Docker socket montado.

Redes próprias: `ingress` somente para o proxy com bind127.0.0.1; `web_private`, `db_private` e `s2s_private` internas. Todos os nomes recebem o namespace Compose. O app não se conecta à rede de ingresso e não deve possuir rota padrão externa. Limites de runtime: app768MiB/1.5CPU; DB1GiB/1.5CPU; proxy128MiB/0.5CPU; logs rotativos; arquivos do app/proxy read-only, capabilities ALL removidas, no-new-privileges, limites de processos. Não existe restart automático.

A porta é obrigatória por variável `SITES_STAGING_PORT`; **nenhum número no exemplo significa porta livre da VPS**. O planner considera18090..18099, exclui18081/18088/18942, inspeciona binds configurados inclusive de contêineres parados, listeners IPv4/IPv6, nomes de contêineres/redes/volumes, memória/disco/carga. Testa bind efêmero apenas no loopback e fecha-o; não reserva a porta nem habilita deploy. Metadados incompletos, colisão de projeto e falta de recursos bloqueiam recomendação. Só imprime contagens/digest e dados finitos, nunca Env, comandos, nomes privados ou segredos.

## Primeiro passo na VPS quando o acesso voltar

Executar no checkout da SHA exata revisada, localmente na VPS autorizada:

```sh
python3 ops/staging/inventory.py --probe-host
python3 ops/homologation/leaseweb-readonly-preflight.py --probe-host
```

O segundo mantém BLOCKED para os gates operacionais: não alterar essas constantes. Guardar evidência sanitizada com timestamp e SHA, confirmar identidade do host e recursos com operador, comparar inventário dos serviços existentes antes/depois. Se o namespace já existir, revisar seu proprietário; não usar down/remove-orphans, renomear nem reaproveitar volumes automaticamente. Revalidar porta imediatamente antes do startup: um snapshot não elimina corrida. Não executar este probe no runner e chamá-lo de inventário Leaseweb.

Os limites não garantem capacidade para compilar imagens em host compartilhado. Preferir imagens de release verificadas/pinadas e construídas fora da VPS; validar margem de CPU/memória/IO antes de qualquer build/pull/start. Não atualizar Docker, reiniciar daemon ou alterar firewall/serviços existentes.

## Segredos e identidades

`SITES_SECRETS_DIR` aponta para montagem própria externa ao Git/imagem, fornecida por mecanismo seguro. Arquivos necessários: app-key, runtime-password, owner-password, server.pem/server.key, access.htpasswd, portal-ca.pem e portal-identities.map. Nenhum contém credencial do CRM/Portal. Arquivo de senha nominal usa hash adequado; revisão de UID/permissões é obrigatória. Nunca copiar chaves de CI ou CA privada para a aplicação.

App recebe só APP_KEY e senha runtime em arquivos. O helper MountedSecret recusa caminho fora de /run/secrets, symlink, permissões graváveis por grupo/outros e valor inválido, sem imprimir conteúdo nem usar fallback após falha. O app não recebe senha bootstrap. A conta bootstrap existe somente no banco Sites isolado e nas tarefas explícitas de migração/provisionamento; não é o runtime HTTP.

Nginx8443 oferece HTTPS + acesso nominal adicional para painel de estado e /up. Todas as APIs, arquivos privados e rotas de laboratório ficam404 nesse listener. Não trocar APP_ENV=staging por local/testing. O listener9443 fica apenas na rede privada S2S, sem bind host; exige certificado cliente confiável e identidade exata issuer+serial em portal-identities.map. CA válida sozinha não autoriza outro cliente. A allowlist deve ser mantida com rotação/revogação operacional e certificados curtos antes do uso real. **mTLS de transporte não é autorização de empresa/contrato/pagamento.**

O health privado autenticado identifica transporte, mas declara autoridade indisponível. Admissão retorna503 mesmo para certificado autorizado. Não existe bypass por flag para admitir pedido. Conectar um novo Portal staging à rede S2S requer plano e revisão próprios; não conectar o Portal/CRM existentes cegamente.

## Banco e migrations

A imagem inclui as três migrations versionadas existentes. Aplicar apenas no banco NOVO/vazio, com proprietário em processo CLI temporário e segredo de owner montado só nesse processo. Não executar migrate:fresh, rollback ou drop contra banco existente.

Após migração revisada, `provision-runtime.php` cria sites_runtime NOSUPERUSER/NOCREATEROLE/NOCREATEDB/NOINHERIT/NOBYPASSRLS. Concede apenas CONNECT, USAGE e EXECUTE da leitura por session_user; nenhuma escrita/DDL/tabela de autoridade. A criação recusa role já existente. SQL com senha não deve aparecer em logs; exceções são sanitizadas e logging de erro da operação de bootstrap é suprimido na transação.

FORCE RLS/default-deny e vínculos ainda inativos são preservados. Sem vínculo nativo autorizado, nem leitura de projeto passa. Não instalar políticas USING(true) para runtime, não usar SET ROLE ou tenant vindo do navegador para simular autorização. SSL do banco está configurado como prefer exclusivamente na rede interna: **não é prova de TLS PostgreSQL operacional**; endurecer para verify-full com CA e hostname próprios antes de habilitar autoridades externas. O preflight continua bloqueado nesse ponto.

## Backup e rollback

O CI ensaia pg_dump cifrado e restore em segundo banco descartável. Não comprova backup da VPS nem cópia externa. Antes de iniciar fluxo persistente, definir retenção, cifra autenticada (ou cifra+MAC), chave separada no cofre, destino externo aprovado sem despesa nova, monitoramento, RPO/RTO e restauração verificada. Nenhum diretório de backup de outro produto será lido/reutilizado.

Reversão: fechar acesso ao Sites, parar somente proxy/app do projeto exato, voltar à imagem anterior pinada, manter volume; se schema incompatível, restaurar em volume/banco NOVO e validar RLS/nonce/revogação antes de apontar app. Reaplicar revogações e descartar sessões recuperadas de backup antigo. Não rodar down -v na VPS nem reduzir gates para aceitar restore. Go-live/publicação/rollback de sites permanecem desabilitados até revisão específica.

## Fluxos que NÃO estão operacionais

- Contrato e confirmação financeira: falta credencial/provedor Asaas Sandbox conectado à fonte de liquidação do Portal. Nenhuma cobrança foi criada ou sandbox consultado nesta rodada.
- Provisionamento autorizado, geração de prévia e revisão editorial: código anterior de laboratório continua separado. A infraestrutura não transforma mocks em autoridade operacional.
- Aceite humano: falta conexão autenticada do cliente Portal ao pedido/release/digest e registro persistente operacional.
- Revogação: falta testemunha operacional independente e política de atualização/restore. A allowlist mTLS não substitui revogação do aceite.
- Publicação e rollback: não houve DNS, Cloudflare, publicação de cliente ou liberação de publisher.

Navegador, após futura implantação privada autorizada: somente painel técnico e /up. Eles NÃO são o fluxo de compra ou revisão de cliente. Não existe URL nova implantada nesta rodada.

## Evidências

Testes novos verificam resolução de conflitos/default-deny e a composição de infraestrutura (não repetem como novidade as provas sintéticas de negócio já aprovadas). CI constrói stack próprio descartável, migra core, verifica runtime/RLS, HTTPS/acesso adicional, mTLS/identidade e restore. Resultados e SHAs serão registrados na PR e na issue38. Todos os gates de operação continuam pendentes até execução/evidência no ambiente apropriado.


## Resultado anterior no CI — 16/11/2026

Código funcional verificado: **e4647169948448db90fdb11a4118c71dd20dfb99**. Os13 check runs recuperados concluíram success (incluem push e pull_request; não são13 suítes distintas).

- Infraestrutura própria: [CI43801276171](https://github.com/rafhael100/imagine-sites/actions/runs/43801276171), build, migrations001/002/003, role runtime, FORCE RLS, negativas de leitura/DDL, HTTPS401 sem acesso nominal e200 com acesso, painel técnico e Laravel/up, rotas privadas/CI bloqueadas404, mTLS privado200 para identidade autorizada e403 para outra identidade da mesma CA, ausência de certificado400, admissão503 mesmo para identidade autorizada. Cifra+restore em banco separado preservaram FORCE RLS. Projeto e segredos descartáveis removidos ao final.
- Planner:7 testes novos aprovados, cobrindo CRM18081 excluído, binds de contêiner parado/listeners, corrida de bind, colisão de namespace, recursos/metadados ausentes e sigilo de nomes/dados extras.
- [Laravel PHP8.3/8.4](https://github.com/rafhael100/imagine-sites/actions/runs/43801276149): execução padrão23 testes aprovados/116 assertions e27 skipped; opt-in29 aprovados/128 assertions e21 skipped, por versão. São cenários distintos; não somar como cobertura exclusiva. Os skips mantêm os laboratórios SQL/fixtures que não pertencem a essa suíte desligados. Os dois testes novos MountedSecret foram executados, com10 assertions. Criar .env.example temporário no runner corrigiu os avisos anteriores de arquivo de ambiente ausente; não silenciou falhas de teste.
- Regressões de segurança/domínio, composição antiga e TLS PG de laboratório aprovadas. Não constituem nova evidência operacional de negócio nem TLS do banco da VPS.

Porta18097 foi usada apenas no runner descartável e não é uma escolha validada na Leaseweb. Redes/volume listados acima estão preparados no Compose, não criados no host contratado nesta rodada. A console autenticada encerrada/SSH ausente continua sendo o bloqueio para inventariar, selecionar porta e iniciar serviços reais. Nenhum gate operacional da issue38 foi fechado.

Falhas corrigidas nesta preparação: contexto runner.temp indisponível no env do job, caminhos temporários de módulos Nginx em raiz read-only, bucket do mapa Nginx pequeno para issuer+serial e descoberta da suíte Unit no PHPUnit. A versão funcional acima passou com as proteções preservadas. O commit posterior acrescenta somente esta evidência documental.
