# Leitura exata de uma solicitação do diário

## Problema resolvido e alcance

Uma solicitação privada pode chegar ao commit e perder o recibo que contém seu
`operationRef`. O [inspector atual](../ops/publication-candidate/README.md)
precisa desse UUID. Repetir `request_operation` pode encontrar a chave
idempotente, mas exige a capacidade de writer. A nova consulta permite apurar
os cinco seletores originais com uma identidade somente leitura independente.

A candidata `feature/sites-publication-request-reader-2026-11-18` deriva da
[#535](https://github.com/rafhael100/imagine-sites/pull/535), HEAD
`d5ef3a45d45d0db073c5b43d8b23e68e537bec0a`, validada com 12/12 checks SUCCESS.
Este incremento retorna o snapshot privado em memória. CLI, gravação de um novo
recibo e transporte permanecem entradas posteriores; a API não abre arquivos,
não solicita novamente, não cria evento e não chama provedor.

## Seleção e resultado

A [API PHP](../src/Publication/PgPublicationJournal.php) acrescenta:

```php
public function readRequest(
    string $siteId,
    string $releaseRef,
    int $expectedBindingRevision,
    string $portalAcceptanceRef,
    string $idempotencyKey,
): array;
```

| Seletor | Regra |
| --- | --- |
| `siteId` | UUID canônico em minúsculas, como o contrato anterior. |
| `releaseRef` | Referência original da release; até 128 caracteres no alfabeto nativo. |
| `expectedBindingRevision` | Inteiro positivo de 64 bits, correspondente ao `bigint` nativo; não é limitado a int32. |
| `portalAcceptanceRef` | Referência declarada no pedido original; não verifica consentimento. |
| `idempotencyKey` | Chave original, interpretada no tenant da identidade SQL autenticada. |

Os cinco valores precisam coincidir. Não há busca aproximada, listagem, fallback
para a associação atual ou criação quando a seleção está ausente. O índice
único existente por tenant/chave continua sendo usado; nenhuma tabela nova é
necessária. A revisão do binding é a original da solicitação, mesmo quando o
binding posteriormente foi revogado.

O retorno conserva os **24 campos privados** de `PgPublicationJournalGuard`,
incluindo UUID da operação, referências, digests, revisões, estado técnico e
timestamps. Não contém lease token. `canPublish` e `publicationVerified`
permanecem falsos. O snapshot pode estar em `requested`, `dispatched`,
`acknowledged` ou `inconclusive`, incluindo um ACK tardio já persistido. A
consulta não transforma um lease vencido em novo estado nem comprova publicação.

A saída é privada e não deve ser impressa em logs. O UUID recuperado permite
preparar uma inspeção posterior pela API existente com sua própria identidade;
não transfere autorização entre as duas capacidades.

## Conexão e autorização

O caller fornece uma PDO PostgreSQL18 já aberta para o alvo adequado. O método
novo exige PHP64, `ERRMODE_EXCEPTION`, nenhuma transação externa, identidade
nativa sem `SET ROLE`, `READ COMMITTED`, RLS ligado, login restrito,
`default_transaction_read_only=on`, `transaction_read_only=on` e UTF-8.
O wrapper não muda configurações nem abre uma conexão alternativa. Banco,
login e PID, além do contexto somente leitura, são observados antes e depois.
TLS e identidade exata do alvo continuam responsabilidades do caller; a futura
CLI poderá aplicar a custódia e a conexão `verify-full` dos comandos privados.

O [SQL aditivo](../contracts/publication-journal/002-read-request-operation.sql)
instala `sites_publication.read_request_operation(uuid,text,bigint,text,text)`.
A identidade corrente deve ter duty `reader`, estar ativa, não revogada e não
expirada, com EXECUTE exclusivo dessa API. Receber também `read_operation` ou
outra capacidade torna o perfil inválido. O definer NOLOGIN existente é
reutilizado, com `search_path=pg_catalog` e RLS ligado; nenhuma política, principal
ou grant operacional é criado pela migration.

A consulta independe do writer original, de seu epoch histórico ou da elegibilidade
atual do binding. A autorização pertence ao reader corrente. O SQL não interpreta
referências declaradas ou flags do resultado como fonte de autoridade.

## Observação dos dados e revogação

A função é **VOLATILE**, preservando o padrão nativo do diário. Primeiro confere
o principal. Uma única seleção de dados reúne a solicitação imutável e seu
último evento. Depois revalida o principal e seu epoch corrente pelos helpers
existentes, sem `FOR SHARE`. Isso não é uma promessa de autorização mantida até
a resposta nem de um único snapshot para toda a função. Revogação posterior à
última conferência não é impedida por lock.

Essa distinção segue a [semântica PostgreSQL18 de volatilidade](https://www.postgresql.org/docs/18/xfunc-volatility.html):
funções VOLATILE podem observar snapshots novos em consultas internas sucessivas.
O estado retornado corresponde à seleção conjunta dos dados, não a uma reserva
de estado ou autorização futura. As verificações PHP posteriores atestam a
conexão/contexto; não constituem um novo aceite comercial.

## Recusas

Seletores malformados são recusados antes de qualquer chamada à PDO. O PHP
valida a forma completa do resultado e reconfere todos os seletores. Ausência,
divergência, recusa SQL, mudança de conexão e resposta inválida produzem o mesmo
`RuntimeException('Publication journal request unavailable.')`, sem exceção
anexada, parâmetros ou diagnóstico SQL. Até SQLSTATE `40001` permanece recusa,
sem instrução automática de retry. Os métodos anteriores mantêm seus contratos.

## Migration e preservação

A [migration 16](../apps/sites/database/migrations/2026_11_18_000016_add_publication_request_reader.php)
acrescenta uma função e amplia o inventário estrito do guard de 12 para 13
assinaturas. O guard é a única função anterior cujo corpo muda. OID, owner,
ACL e demais atributos, assim como todas as outras funções nativas, são
comparados antes e depois. SQL e corpos esperados têm SHA256 fixados.

O rollback remove somente a API nova e restaura o corpo do guard a partir do
SQL001 original fixado. Não usa CASCADE e não remove operações, eventos,
principais, roles ou políticas. Dependências ou definição inesperada interrompem
a transação. Essa reversibilidade não substitui a revisão de uma migration em
ambiente operacional.

## Validação e CI

Localmente, **49 checks offline** verificaram o protocolo PHP com doubles
explícitos, sem alegar persistência ou autorização SQL. Cobrem os estados,
seletores, faixa bigint, zero PDO para entradas inválidas, contexto RO/UTF8,
identidade, recusa uniforme e ausência de retry. Os **99 checks anteriores do
diário**, **296 testes Laravel/4705 assertions/27 skips** e **32 checks sintéticos**
obrigatórios passaram. O PHP foi revisado independentemente.

O [workflow dedicado](../.github/workflows/sites-publication-request-reader.yml)
usa a cadeia PostgreSQL18/SCRAM/TLS já existente. Instala a migration 16 logo
após a 7, para executar as APIs anteriores sob o guard ampliado. Mantém as
**1208 assertions do predecessor** e acrescenta a fixture de leitura ao fim,
reutilizando seis operações existentes dos dois tenants. A
[fixture nova](../tests/fixtures/publication-request-reader/contract.php) prevê
**33 checks novos /1241 totais**, com os quatro estados, ACK tardio, revogação
histórica, seleção exata, permissões/contexto e down/up/down da migration 16.
Nenhum teste PG local foi executado. A [#536](https://github.com/rafhael100/imagine-sites/pull/536),
HEAD `849636107cc8562f68ad6c42a34bb71c5b7f66e1`, concluiu **14/14 checks SUCCESS**.
O [registry 44394481654](https://github.com/rafhael100/imagine-sites/actions/runs/44394481654),
jobs **135120584998 / 135120584665**, confirmou **49 offline, 33 novas/1241
totais**, rollback16 e cleanup em PHP 8.3/8.4. O [F383 44394481894](https://github.com/rafhael100/imagine-sites/actions/runs/44394481894),
jobs **135120585725 / 135120586140**, percorreu as dezesseis migrations e
confirmou ambos os schemas ausentes após rollback. O [Laravel 44394481500](https://github.com/rafhael100/imagine-sites/actions/runs/44394481500)
confirmou **296/4705/27** e **296/4717/21**.

A [CLI da #538](PUBLICATION-REQUEST-RECOVERY-CLI.md) consome esse leitor e grava
um novo recibo privado. Seus **63 checks offline** passaram nas duas versões;
a evidência SQL do leitor não substitui a prova própria da composição CLI,
cuja [fixture própria](reviews/SITES-PUBLICATION-RECOVERY-COMPOSITION-2026-11-18.md)
prevê 38 novos/1279 totais e permanece pendente de execução PostgreSQL.

Os dez gates continuam BLOCKED e AGI-11-RB01 desabilitado. Histórico técnico,
ACK e recuperação de referência não autorizam envio, publicação, aceite do
cliente, pagamento ou evento ENTREGUE.
