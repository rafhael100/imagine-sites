# Recebimento nativo de solicitação de projeto do Portal

Base: Sites #510, `61a8d1db212d298051e6f64f8b923e545a4c6bb3`.

## Efeito do serviço

`App\Integration\PortalProjectIntakeRecorder::record(SiteProjectCommand $command)`
registra uma solicitação pendente em `sites_core`, usando a conexão PostgreSQL
própria do Sites. Não usa `sites_f360`, o workspace demonstrativo, nem um receptor
implementado no teste. Não há rota HTTP nova ou ativação de ambiente.

A migration adiciona `db_login_bindings.can_receive_project`, inicialmente
`false`. O login precisa ser restrito, estar ativo, ter essa capacidade e receber
explicitamente EXECUTE na função. `can_read` não concede recebimento. A migration
não cria LOGIN, vínculo ativo ou grant para uma identidade operacional.

`tenantRef` é **a referência da organização no Portal**. A função deriva o UUID
interno do Sites pelo `session_user` e compara `tenantRef` a `portal_org_ref`.
Pedido, contrato, operação e chave continuam referências distintas. Não há
`serviceRef` no comando v1; o Portal deverá conservar seu vínculo próprio entre pedido,
serviço e `siteRef` retornado. O chamador nunca escolhe o UUID do site.

## Atomicidade, repetição e autorização

Contrato referenciado, projeto, outbox bloqueado e recibo são gravados na mesma
transação. A primeira gravação é exclusivamente uma observação da solicitação:
não confirma que o contrato ou pagamento sejam oficiais. As constraints de
autoridade e publicação falsas continuam intactas.

A função serializa chamadas da mesma empresa e conserva unicidades por pedido,
operação, chave e site. Replay exato retorna os mesmos campos; reutilizar qualquer
seletor com conteúdo diferente causa conflito. `operationId` e `idempotencyKey`
são armazenados separadamente. O digest é recalculado pelo Sites com a semântica
do comando v1; não é uma prova financeira nem uma assinatura de autorização.

Vínculo do login e mapeamento da organização ficam sob `FOR SHARE` até commit.
Revogação já confirmada quando a função obtém o lock impede a solicitação;
revogação posterior espera a transação corrente terminar. Os grants UPDATE de
colunas do definer servem apenas a esses locks: as políticas de UPDATE têm
`WITH CHECK(false)` e negam alterações. O definer é NOLOGIN e não pode ter membros.

O serviço recusa transações externas, inclusive uma transação aberta diretamente
no PDO. Valida os campos e tipos do retorno SQL antes de commit, não faz retry
automático e só devolve o recibo depois que a transação conclui. Falha de commit
pode ser ambígua: repetir **o mesmo comando** recupera o registro, sem criar nova
identidade. Recibo perdido não autoriza nova operação para o mesmo pedido.

## Contrato de retorno

O schema está em `contracts/portal-sites/v1/project-intake-receipt.schema.json`.
São exatamente quinze campos, na ordem abaixo:

```text
schemaVersion = sites.project-intake.receipt.v1
tenantRef, orderRef, contractRef, contractRevision, productCode
operationId, idempotencyKey, siteRef, commandDigest
status = recorded
state = awaiting_official_authority
authorityVerified = false
clientAccepted = false
canPublish = false
```

`recorded` não distingue uma gravação nova de replay. O recibo descreve o
recebimento histórico pendente e não é uma consulta do estado posterior do
projeto. Não significa produção iniciada, briefing liberado, publicação ou
evento oficial `ENTREGUE`. Recibo e projeto são recuperáveis por suas referências
persistidas; o Portal deve validar os quinze campos antes de conservar o vínculo.

## Verificação e limites

A candidata aditiva oferece também a [consulta exata do recibo por leitor
independente](PROJECT-INTAKE-RECEIPT-READER.md). Usa o comando original e a
autorização atual de leitura para recuperar o siteRef persistido, inclusive
depois da revogação do receptor. Preserva este recorder, seu SQL e o contrato
de quinze campos. Sua prova PostgreSQL permanece identificada separadamente
da evidência histórica abaixo.
A [CLI privada de recuperação](PROJECT-INTAKE-RECOVERY-CLI.md) pode conservar
esse resultado em um novo arquivo exclusivo usando leitor próprio. Mantém SQL001,
recorder, comando e schema do recibo originais, sem ativar transporte Portal.
A [prova dedicada com o Portal nativo](reviews/SITES-PORTAL-INTAKE-RECOVERY-2026-11-18.md)
consome o arquivo recuperado usando o encoder, validador, ledger e leitura do
comprador fixados na #954. A implementação fica no repositório Sites e executa
cada aplicação em seu próprio processo e banco; seu CI permanece identificado
separadamente da composição da #532.

O workflow de migrations existente (`sites-f1-383-operational-schema.yml`) passa
a executar o serviço real com o schema nativo e identidades fictícias restritas.
Não é criado um novo serviço ou laboratório de integração. Os testes incluem
snapshot integral em falhas, leitor sem capacidade, empresa B positiva, conflito
de seletores, resposta perdida após commit, dois processos concorrentes e as
duas ordens de revogação observadas por PIDs/locks reais do PostgreSQL.

Validação local em PHP8.4.27/PostgreSQL18.7: **63 verificações reais aprovadas**,
18 testes/84 assertions novos e suíte completa com 186 testes/1902 assertions/27
skips explícitos. Down/reapply preservou os projetos, não inventou recibos e
manteve a capacidade novamente false. O passo exato `migrate:rollback --batch=1`
também passou após executar todos os seeds e casos nativos: as onze migrations
foram revertidas e `sites_core` deixou de existir, sem CASCADE ou limpeza que
contornasse constraints. Os 32 checks de domínio, sintaxe, pins SQL, JSON/YAML e
safety guard passaram. A revisão independente do SQL, migration e serviço foi
concluída sem bloqueadores funcionais. A CI remota foi posteriormente conferida
no HEAD `9b80387fe1c580436b444a6aad2b63f3baafa37d` da PR #512: 14 checks SUCCESS.
O [run 44303070110](https://github.com/rafhael100/imagine-sites/actions/runs/44303070110)
confirmou as 63 verificações nativas, recorder 18/84 e rollback completo em PHP
8.3/8.4.

A [correção aditiva de cobertura do registry](reviews/SITES-REGISTRY-INTAKE-PERMISSIONS-2026-11-18.md)
inclui a nova tabela na inspeção de ACLs desse consumidor. O
[run 44307366977](https://github.com/rafhael100/imagine-sites/actions/runs/44307366977)
repetiu a prova de recebimento com a migration 12 e confirmou o rollback das
doze migrations em PHP 8.3/8.4. Isso não modifica o recibo ou sua autoridade.

Para a prova pareada, o adaptador do Portal precisa chamar este serviço e validar
seu recibo. Uma fixture que apenas reproduza o retorno não demonstra essa ligação.
O transporte privado, fontes oficiais de contrato/pagamento/revogação, consumo de
briefing, geração e publicação conservam suas fronteiras e evidências próprias.
Nenhum dos dez gates operacionais é aberto por esta entrega.

O `down` remove exclusivamente a API, capacidade e ledger adicionados, sem
CASCADE e sem apagar os projetos existentes. A remoção de recibos é destrutiva:
em ambiente operacional exige revisão e backup independentes, como o rollback da
fundação. Rollback/reaplicação não inventa recibos para projetos preexistentes;
uma colisão é recusada até recuperação explícita das evidências.
