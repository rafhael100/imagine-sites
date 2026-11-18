# Briefing armazenado e artefato: registro composto e recibo histórico

**Estado em 18/11/2026:** implementação candidata na branch
`feature/sites-brief-artifact-provenance-2026-11-18`, derivada da
[#519](https://github.com/rafhael100/imagine-sites/pull/519), no commit
`cdfad4464d65355962d487396911fe06092dfde5`. A prova PostgreSQL desta composição
será obtida no CI isolado da própria branch; a prova da base não a substitui.

## Resultado e limite

O consumidor interno lê um snapshot exato, valida o corpo rico, produz o HTML
com o renderer existente e registra **artefato e associação na mesma chamada
SQL**. O registry passa a conservar qual snapshot, plano e entrada editorial
originaram aquele registro. Uma consulta separada recupera somente o recibo
histórico, inclusive quando a resposta da primeira gravação foi perdida.

Esta etapa usa o armazenamento da [migration 13](PORTAL-BRIEF-SNAPSHOTS.md) e o
[contrato candidato de produção](SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md).
Não autentica a aprovação declarada pelo Portal nem verifica pagamento ou aceite.
Os quatro campos `authorityVerified`, `clientAccepted`, `canPublish` e
`canAcceptRealOrders` permanecem falsos em todos os resultados PHP.

Não há endpoint, comando, provider, transporte, dispatcher ou ativação de
principal operacional nesta entrega. O produtor oficial do Portal, transporte
autenticado, materiais e aceite comercial continuam como integrações próprias.
O fluxo de revisão, associação comercial, preparação e verificação de pacote
existente é exercitado pela fixture; não recebe um atalho por existir proveniência.

## Componentes e chamadas internas

| Componente | Responsabilidade |
| --- | --- |
| [`BriefRenderPlanInput`](../src/Production/BriefRenderPlanInput.php) | Parser único do plano, seletores exatos, bytes originais e validação da entrada editorial/HTML esperado. |
| [`StoredBriefReleaseRegistration`](../apps/sites/app/Integration/StoredBriefReleaseRegistration.php) | Planeja e executa a leitura/renderização interna antes da chamada SQL composta. |
| [`StoredBriefReleaseReceiptReader`](../apps/sites/app/Integration/StoredBriefReleaseReceiptReader.php) | Consulta histórica exata por site, release e hash do plano, usando reader corrente separado. |
| [`BriefReleaseReceipt`](../apps/sites/app/Integration/BriefReleaseReceipt.php) | Valida projeções, tipos, hashes, UUIDs e timestamp UTC com seis casas e calendário válido. |
| [`ReleaseArtifactConnection`](../src/Publication/ReleaseArtifactConnection.php) | Compartilha a observação de target e runtime com o registro anterior, usando três capacidades enumeradas. |
| [`005-brief-artifact-provenance.sql`](../contracts/release-registry/005-brief-artifact-provenance.sql) e [migration 14](../apps/sites/database/migrations/2026_11_18_000014_add_brief_artifact_provenance.php) | Associação persistente, escrita atômica, consulta restrita, guard e rollback explícito. |

Exemplo de composição, com conexões dedicadas previamente configuradas por
código confiável:

```php
$rendering = new StoredBriefRendering(new StoredBriefSnapshotReader($snapshotReader));
$planning = new StoredBriefReleaseRegistration($rendering, $planningPdo);
$plan = $planning->plan($writerTarget, $planJson, $editorialJson, $observedCaSha256);

$registration = new StoredBriefReleaseRegistration($rendering, $writerPdo);
$receipt = $registration->record(
    $writerTarget, $planJson, $editorialJson, $observedCaSha256,
    $plan['commitmentSha256'],
);

$history = (new StoredBriefReleaseReceiptReader($receiptReaderPdo))->readExact(
    $readerTarget, $siteRef, $releaseRef, $plan['planSha256'], $observedReaderCaSha256,
);
```

`$snapshotReader` é a conexão Laravel de leitura do snapshot. As demais são
PDOs PostgreSQL dedicadas, não persistentes e construídas com `sslmode=verify-full`
e CA conferida. A PDO de planejamento e a de recibo já estão em modo somente
leitura; a de gravação já está em modo de escrita. O serviço não reconfigura a
conexão, concede permissões ou aceita uma transação externa. Planejamento e
registro usam o mesmo target de writer em sessões distintas; o reader histórico
tem seu próprio login e target.

Cada target contém exatamente `host`, `port`, `database`, `databaseOid`, `login`,
`serverAddress` e `caSha256`. Esses campos identificam o endpoint revisado;
tenant e dever vêm do principal SQL. A observação exige identidade do banco,
login, endereço/porta, modo da sessão, UTF-8, TLS e a única capacidade esperada,
antes e depois da operação. O hash da CA observado é conferido contra o target.
O chamador confiável é responsável por construir a conexão com verificação
completa; um valor de hash fornecido isoladamente não comprova essa construção.

## Três identidades distintas

| Campo | Conteúdo comprometido | Uso |
| --- | --- | --- |
| `planSha256` | SHA-256 dos bytes originais do plano JSON, incluindo LF/espaços. | Seleção exata e persistência do histórico. |
| `commitmentSha256` | Domínio `SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_V1`, target canônico, plano original, entrada editorial original e HTML, separados por NUL. | Confirmação do plano completo antes da gravação naquele target. |
| `artifactDigest` | SHA-256 dos bytes do HTML gerado. | Identidade do artefato no registry e verificação do pacote. |

Plano e entrada editorial têm limite de 32768 bytes cada; o HTML tem limite de
150000 bytes. Os JSONs persistidos conservam seus bytes originais. Alterar
somente a formatação de um plano ou editorial muda sua identidade, mesmo que
o HTML permaneça igual. O hash de compromisso não é persistido como uma
identidade de autoridade: a consulta histórica não tenta reconstruir um target
antigo e não devolve esse campo.

O parser e a renderização pura conferem entrada, target, CA, HTML esperado e
compromisso antes de tocar uma PDO. Depois, o consumidor chama o reader real,
que revalida o perfil completo e os quatro seletores do snapshot. O serviço
produz o artefato internamente; não aceita um `ReleaseArtifact` ou sidecar do
chamador como prova dessa execução.

## Persistência e isolamento

A nova tabela `sites_core.release_artifact_provenance` tem ENABLE e FORCE RLS,
chave por tenant/site/release e unicidades por operação e idempotência no mesmo
tenant/site. Conserva os JSONs do plano/editorial, seus hashes, a seleção do
snapshot, digest/tamanho do artefato e timestamp. Não duplica o HTML nem o corpo
rico do briefing. Os triggers recusam UPDATE, DELETE e TRUNCATE.

Duas FKs ligam a linha às identidades nativas:

- Artefato: tenant, site, release e digest.
- Snapshot: tenant, site, UUID do snapshot, revisão, hash do manifesto e
  organização Portal. A constraint `brief_snapshots_provenance_identity`
  fornece essa chave completa na tabela de snapshots.

O tenant é derivado do principal SQL; `tenantRef` precisa coincidir com a
organização armazenada no snapshot. A FK verifica a identidade no próprio
banco da gravação. Uma seleção proveniente de outro banco não basta só porque
alguns UUIDs coincidem. Essa verificação não requer SELECT, privilégio de coluna
ou REFERENCES sobre o corpo de snapshots para os novos definers.

As três roles internas `sites_core_provenance_owner`,
`sites_core_provenance_writer` e `sites_core_provenance_reader` não têm LOGIN,
herança, membership ou poderes administrativos. O writer interno recebe apenas
a leitura do artefato e SELECT/INSERT da associação, além dos helpers nativos
necessários; o reader interno lê a associação. O runtime externo não recebe
acesso direto a tabelas. Nenhum grant EXECUTE operacional é criado pela migration.

O guard confere RLS, owners, ACLs, policies, FKs, funções e triggers esperados.
Os triggers de imutabilidade não podem ser condicionados por `WHEN`, argumentos,
seleção de colunas ou adiamento. Os corpos de imutabilidade usados e os helpers
nativos relevantes são pinados. A migration pina também o SQL completo e os
corpos das novas funções.

## Criação, replay e consulta

| Situação | Comportamento |
| --- | --- |
| Primeira chamada válida | A função chama `record_release_artifact` e insere a associação na mesma transação; retorna `created=true`. |
| Mesmo par exato | Confere plano/editorial/seletores originais e chama novamente a API nativa, preservando autor/epoch; retorna `created=false`. |
| Mesmo release com outro plano, editorial, operação ou snapshot | Conflito; não substitui a associação. |
| Artefato existente sem associação | Conflito mesmo com HTML, autor e epoch iguais; não há adoção retroativa. |
| Resposta perdida após commit | Consulta com reader corrente e seletores exatos; não exige repetir a gravação. |
| Autor original revogado ou epoch alterado | Não habilita replay pelo writer; um reader corrente pode consultar o histórico. |

A função usa o advisory lock nativo por tenant/site e as verificações correntes
do principal. Falha na inserção da associação reverte também o artefato criado
pela chamada. Replays de snapshots e de artefatos têm regras diferentes:
o armazenamento de snapshot permite receiver corrente distinto; o registry
continua exigindo o autor e epoch originais para seu replay.

A API antiga `record_release_artifact(uuid,text,text)` permanece disponível e
com corpo/OID preservados. Um runtime pode ter a capacidade antiga ou a composta,
exclusivamente. A mesma exclusividade vale para o reader antigo e o reader de
recibo. O estado sem capacidade de API usado pela validação interna do journal
continua permitido pelo guard comum; cada novo entrypoint exige sua capacidade
exata. Isso não concede API ao journal nem amplia seus privilégios.

## Projeções e falhas

| Resultado | Schema/status | Projeção |
| --- | --- | --- |
| Planejamento | `sites.brief-artifact-registration.plan.candidate.v0.1` / `planned` | 10 campos: schema/status, compromisso, hash do plano, digest/tamanho e quatro flags falsas. |
| Registro | `sites.brief-artifact-registration.receipt.candidate.v0.1` / `recorded` | 20 campos: schema/status, 13 campos SQL, quatro flags falsas e compromisso. |
| Consulta | `sites.brief-artifact-registration.inspection.candidate.v0.1` / `inspected` | 18 campos: schema/status, 12 campos históricos e quatro flags falsas. Sem `created` ou compromisso. |

Os 12 campos históricos são `siteRef`, `releaseRef`, `operationId`,
`idempotencyKey`, `snapshotRef`, `briefRevision`, `manifestSha256`, `planSha256`,
`editorialSha256`, `artifactDigest`, `sizeBytes` e `recordedAt`. A gravação
acrescenta `created`. Os resultados não expõem JSON privado ou login SQL.

O PHP apresenta `BRIEF_ARTIFACT_PROVENANCE_DENIED` sem causa interna; somente
o SQLSTATE `40001` da chamada de registro vira
`BRIEF_ARTIFACT_PROVENANCE_CONFLICT`. Seleção inexistente ou cruzada, receipt
inválido, revogação e incompatibilidade de runtime não revelam bytes privados.

Há uma tentativa, sem retry automático. A transação de leitura do snapshot
termina antes da escrita composta: não existe atomicidade de autorização entre
essas sessões nem reserva futura de autorização durante `plan()`. Uma falha
de resposta ou de observação após a chamada SQL pode acontecer depois do commit.
Esse erro não demonstra ausência de gravação; a recuperação consulta o histórico.

## Garantias diferentes de SQL e PHP

SQL verifica estrutura, bytes, escopo e vínculo persistente. Uma chamada SQL
autorizada com HTML alternativo e hashes/tamanhos coerentes pode registrar esses
bytes. Isso não demonstra que o renderer PHP foi executado. A fixture contém
um caso separado que aceita esse registro estrutural em SQL e mostra que o
consumidor PHP recusa o mesmo plano por divergir do HTML determinístico real.

O consumidor PHP acrescenta a leitura real e a validação integral do snapshot,
entrada editorial e geração. Seu recibo continua técnico: nem essa execução
nem um recibo histórico substituem elegibilidade comercial corrente, revisão
independente, binding válido ou a decisão de publicação.

## Rollback e provas

O `down()` da migration 14 remove a associação e seus objetos, restaura o corpo
pinado do guard 12 e preserva as linhas de artefatos e snapshots. Essa reversão
é destrutiva para o histórico da nova associação; não é recuperação operacional
automática. Reaplicar cria a relação vazia e não restaura grants de LOGIN. Como
não há adoção retroativa, artefatos preservados sem associação não podem receber
uma associação nova por esse caminho; uma nova produção usa outro release.

A ordem de dependência é explícita: remover a migration 13 com a FK da 14
instalada deve recusar com `2BP01`, sem mudar dados ou catálogo. O ensaio de
schema remove 14 antes de testar down/up de 13 e depois reaplica 14. A reversão
do batch completo segue a ordem inversa das 14 migrations.

As provas locais focadas registradas são 39 testes/1172 assertions
(28 testes novos e 11 Unit existentes), além de 109 checks de proveniência e
271 do registro anterior. A suíte geral local em PHP 8.4.27 completou
**279 testes / 4250 assertions / 27 skips**, com `--do-not-cache-result`.
Os skips não são apresentados como provas PostgreSQL; os números do CI da
própria branch serão registrados após seus resultados observados.

A [fixture composta](../tests/fixtures/brief-artifact-provenance/contract.php)
roda em PostgreSQL 18 com TLS no cluster descartável já verificado. Primeiro o
runner conclui **923 assertions da base**, ainda sem a migration 14; depois
instala a 14 e executa criação, conflitos, limites de camada, concorrência,
resposta descartada, revogação, pacote existente e rollback/reaplicação. Essa
ordem não significa que toda a cadeia antiga foi executada sob o guard novo;
a fixture posterior possui suas próprias provas de compatibilidade.

O [workflow dedicado](../.github/workflows/sites-brief-artifact-provenance.yml)
restringe repositório, draft, head/base exatos e PHP 8.3/8.4; exige todos os
13 opt-ins anteriores/atuais. O ensaio F383 confere 15 tabelas core, 45 policies
e o rollback completo. Logs de sucesso, contagens efetivas e teardown do
ambiente próprio são necessários antes de declarar essas provas aprovadas.

AGI-11-RB01 permanece desativado e os dez gates de prontidão continuam BLOCKED.
