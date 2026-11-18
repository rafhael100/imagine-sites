# Consumo do recibo recuperado pelo Portal nativo

## Objetivo e fonte fixada

O recibo recuperado pelo Sites precisa continuar utilizável pelo consumidor
nativo do Portal. Esta candidata comprova o percurso por implementações reais:
pedido aceito, comando, recebimento, perda da resposta pública, desativação do
receiver, recuperação independente e conservação do vínculo no Portal. Acrescenta
somente testes, runner, workflow e documentação ao Sites.

Base Sites: [#532](https://github.com/rafhael100/imagine-sites/pull/532),
`6da154611d082f7f7fe4aab87f17c9857403ed02`, com **12/12 checks SUCCESS** e prova
própria **30 novas/1208 registry totais** em PHP 8.3/8.4. A nova prova é separada
da composição e não altera esse total ou os opt-ins anteriores.

O Portal vem do commit
[`d1aaab523848baf80325a023f3ebbf4998a3607f`](https://github.com/rafhael100/imagine-portal/commit/d1aaab523848baf80325a023f3ebbf4998a3607f)
da #954. O checkout inteiro permanece inalterado. Não se atribui a esta candidata
qualquer CI da PR de origem. O
[manifesto de fontes](../../tests/fixtures/portal-intake-recovery/portal-source-pins.json)
registra 31 blobs críticos do Portal: dependências, bootstrap, teste nativo,
assertions de pagamento, encoder, validador, stores, nove migrations de identidade
e quatro arquivos SQL de segurança. O
[verificador](../../tests/fixtures/portal-intake-recovery/verify-portal-source.py)
confere HEAD, árvore rastreada inalterada e Git blob SHA dos arquivos reais.
Confere também os quatro SHA256 dos contratos Sites já consumidos pelo Portal.

## Separação dos produtos

| Parte | Processo e dados do laboratório |
| --- | --- |
| Portal | Seu Composer, bootstrap e PHPUnit; serviço Docker PostgreSQL 18, banco `portal_native_orders_ci`, porta de loopback 5432, endereço privado observado pelo Docker e diretório exato do serviço nativo. |
| Sites | Seu Composer e Laravel em subprocessos; cluster PostgreSQL 18 próprio, banco `postgres`, porta de loopback 55449, SCRAM e TLS `verify-full` com CA efêmera. |
| Coordenação | Fixture do Sites escreve o comando fictício no mount privado existente e fornece o recibo recuperado pelo arquivo privado fixo. Não carrega classes dos dois produtos no mesmo processo. |

O serviço Portal conserva exatamente o contrato de autenticação do seu teste
nativo descartável. Isso não muda a conexão TLS do Sites ou a configuração de
produto. A fixture não copia SQL Portal, não compartilha banco e não acrescenta
rota, worker ou transporte de serviço. Cada Composer usa o lock próprio sem
scripts ou plugins. `.env`, `.env.testing` e cache de configuração são recusados.

## Jornada exercitada

1. O teste nativo Portal prepara sua identidade e suas tabelas, autentica o
   comprador fictício, publica os termos da fixture e aceita um pedido Onepage.
   O hook recebe o pedido realmente armazenado e a sessão desse comprador.
2. `PostgresPortalSiteContractSource` e `SitesCreateProjectEncoder` produzem os
   oito campos do comando original. O orderRef é o UUID nativo; revisão 1 e
   companyReference `native-order-company` pertencem à fixture existente.
3. O bridge do Sites instala as quinze migrations reais em um único laboratório
   vazio, cria um tenant fictício e as identidades restritas de receiver e leitor.
   Executa `receive-project-intake.php plan` e uma chamada `record`, com stdout
   desse registro efetivamente direcionado a `/dev/null`.
4. O bridge confere o intake, projeto, outbox e arquivo nativo gravados. Desativa
   o binding do receiver. Executa `recover-project-intake-receipt.php plan` e
   `recover` pela identidade independente, mantendo a conexão de recuperação
   somente leitura. Confere o recibo recuperado e zero alteração de linhas ou
   catálogo nessa leitura, conservando o arquivo original.
5. O Portal lê exclusivamente o novo arquivo 0600 de link único sob seu pai
   privado 0700, com tamanho limitado, identidade estável e SHA256/tamanho iguais
   à projeção pública da CLI. A validação é feita contra o comando que seu encoder
   produziu. Os quinze campos continuam no arquivo; não são enviados ao log.
6. `PostgresOnepageSiteReceiptWriter`, por uma PDO restrita do Portal, grava o
   recibo validado. `findOwnedSiteIntake` retorna ao comprador os seis campos
   pendentes previstos. Replay exato conserva uma única linha no ledger.
7. O hook retorna ao teste original, que continua suas verificações de pagamento,
   outro comprador da mesma empresa, outra empresa e contrato revogado. Ao fim,
   Sites confere que seu estado permaneceu idêntico à base após a recuperação.
   A revogação posterior no Portal não é propagada automaticamente entre bancos.

O comando e o native15 vêm dos produtores reais. O objeto público de dez campos
da recuperação é apenas uma confirmação técnica com hashes e tamanho. A fixture
rejeita seu uso como se fosse o recibo privado.

## Negativos e preservação de estado

Antes de escrever no Portal, o teste entrega ao validador a projeção pública,
um operationId diferente, uma idempotencyKey diferente e uma promoção de
`authorityVerified`. Cada tentativa deve ser recusada e conservar o ledger vazio
e as demais linhas do comércio. Operação e chave são conferidas separadamente,
mesmo sem alterar o fingerprint semântico dos seis campos.

Após a gravação, outro siteRef válido para o mesmo comando satisfaz a forma do
recibo, mas o writer deve recusar a substituição. O ledger original e a leitura
do comprador permanecem intactos. Um replay exato do aceite já exercitado pelo
pai confirma `pending_payment`, `payment_requested=false` e
`site_provisioned=false`, sem modificar as tabelas do comércio. Esse replay não
cria um novo pedido ou uma nova decisão de aceite.

O native15 conserva `authorityVerified=false`, `clientAccepted=false` e
`canPublish=false`. Essas flags não são fonte de autorização. A recuperação usa
binding atual e EXECUTE restrito do leitor, mesmo com o receiver original
desativado. A autorização STABLE do leitor continua sendo a do início da consulta.

## Execução e limpeza

O [workflow dedicado](../../.github/workflows/sites-portal-intake-recovery.yml)
limita a execução à branch draft, base Sites exata e duas versões PHP. O opt-in
`SITES_PORTAL_INTAKE_RECOVERY_CI=1`, GitHub Actions e ambiente de teste são
obrigatórios antes de qualquer preparação. A subclass confere seu autoload e
cwd Portal antes de permitir que o teste herdado prepare o banco.

O [runner](../../tests/run-portal-intake-recovery-contract.sh) usa ferramentas
PG18 extraídas de pacotes com assinaturas e hashes verificados. O
[bridge](../../tests/fixtures/portal-intake-recovery/sites-bridge.php) confere
login, cluster, diretório, banco, porta, endereço e TLS do laboratório próprio.
Os helpers de custódia do receiver e da recuperação permanecem inalterados e
recebem somente seus flags exigidos.

Os logs completos ficam no laboratório privado; a saída pública contém
marcadores, contagens e diagnósticos limitados. Não há upload de comando, recibo,
senha, CA ou dumps de banco. O runner preserva o código de falha inicial e tenta
reverter a parte da instalação que ele próprio registrou. No caminho de sucesso,
exige rollback das quinze migrations e ausência dos três schemas próprios.
Depois interrompe seu cluster e limpa os recursos declarados pela recuperação
antes dos do receiver. O serviço Portal é removido pelo post-job do GitHub.

## Evidência e alcance

**Estado inicial desta candidata:** revisão estática independente sem bloqueador;
CI PostgreSQL pareado ainda pendente. O bridge prevê **13 verificações**, e o
hook Portal, **20**, além do teste herdado completo. Os **32 checks sintéticos**
obrigatórios passaram. Guard em **730 arquivos**, YAML sem chaves repetidas,
cinco blocos Bash e runner, **161 links locais** e diffcheck PASS. Bridge e
subclass recusaram execução sem opt-in antes do acesso SQL ou do teste herdado.
O verifier local confirmou os 31 blobs Portal,
os quatro contratos Sites e os dois checkouts exatos, além de recusar a ausência
do opt-in. Essa execução verifica fontes e não simula sucesso de banco.

### Primeira execução e ajuste de conexão da fixture

A [#533](https://github.com/rafhael100/imagine-sites/pull/533), HEAD inicial
`e3c7a824bb4de2f94f52b0a70314ed6072354b23`, executou o
[run 44386505656](https://github.com/rafhael100/imagine-sites/actions/runs/44386505656),
jobs **135092412719 / 135092412273**. Nas duas versões PHP, fontes, dependências,
preflight TLS e custódia dos helpers passaram, mas o setup parou antes da primeira
migration. O trap confirmou rollback de zero migrations, schemas ausentes,
cluster Sites inativo e cleanup 19→18; serviço Portal e rede foram removidos.
Isso não comprovou a jornada de consumo.

A causa foi localizada no metadado da conexão criada pela própria fixture:
`Migrator::runMethod()` usa `getNameWithReadWriteType()` como default temporário
antes de chamar `up()`. Sem `config['name']`, o valor era NULL e a migration não
resolvia a extensão vinculada à PDO atestada. O bridge agora fornece
`name=portal_intake_recovery_lab` e confere os dois getters e o default. O ajuste
preserva a PDO, o tracking, as transações e as contagens 13/20; não altera produto
ou migrations. Lint e diffcheck passaram. A execução CI seguinte deve confirmar
a jornada completa.

### Segunda execução e representação do ambiente

No HEAD `03cc9fc7e011d404dcda05e6b0946d492f6e7437`, o
[run 44387631593](https://github.com/rafhael100/imagine-sites/actions/runs/44387631593),
jobs **135096400547 / 135096399924**, confirmou `schema-up15` e rollback15 nas
duas versões, mas `recover` foi recusado no gate inicial do subprocesso. O XML
Portal força `APP_DEBUG=false` sem `verbatim`; PHPUnit 12.5.43 converte esse valor
em booleano e `PhpHandler` o interpola como string vazia em `putenv`. A fixture
repassava esse valor, enquanto Sites exige literalmente `false`.

A montagem do ambiente agora confere o valor herdado e `config('app.debug') ===
false`, depois serializa explicitamente `APP_DEBUG=false`. Preserva o XML Portal,
os opt-ins e o gate estrito do bridge. A conversão foi confirmada localmente com
Loader e PhpHandler reais sobre o XML fixado, sem bootstrap da aplicação ou SQL;
lint e diffcheck passaram. A execução seguinte deve concluir a jornada. Os dois
jobs anteriores reverteram as quinze migrations próprias e removeram os três schemas, interromperam o
cluster Sites, removeram os helpers na ordem correta e encerraram o serviço
Portal com sua rede. Não houve consumo de recibo confirmado nessa tentativa.

### Terceira execução e inventário nativo das tabelas

O [run 44388532734](https://github.com/rafhael100/imagine-sites/actions/runs/44388532734),
HEAD `dcafa152cee70a8387b7e1d1fcc54e5355f2b28a`, jobs **135099575441 /
135099576110**, passou pelo comando nativo do Portal e recusou o inventário
inicial da fixture. A regra genérica exigia RLS nas 23 tabelas, mas o contrato
[do preview](../../contracts/private-preview/001-workspace.sql) conserva
`sites_preview.principals` como diretório global privado por grants; apenas as
quatro tabelas de recursos desse schema têm RLS habilitada e forçada.

A fixture agora compara o mapa exato das 23 relações, seus nomes, tipo de tabela
e dois flags: quinze core, três journal e quatro preview com `true/true`, e
somente `sites_preview.principals` com `false/false`. Relações extras, ausentes
ou substituídas continuam recusadas. SQL, grants e políticas permanecem
inalterados, assim como o total13. Lint e diffcheck passaram. Os dois jobs
confirmaram rollback15, ausência dos schemas e cleanup dos dois ambientes;
não chegaram a confirmar recebimento e recuperação do recibo.

## Limites da conclusão

A prova cobre o pedido Onepage nativo de revisão 1 e este consumidor fixado.
Não amplia o ledger Portal para Landing Pages ou para todo o intervalo int32.
Não fornece autenticação de transporte, atomicidade entre os dois bancos,
produtor oficial do briefing ou autoridade comercial. Aceite oficial, pagamento,
produção, publicação, evento ENTREGUE e dez gates operacionais conservam suas
evidências próprias. AGI-11-RB01 permanece desabilitado.
