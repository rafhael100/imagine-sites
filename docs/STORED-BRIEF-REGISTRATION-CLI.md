# Registro privado a partir de briefing armazenado

**Estado em 18/11/2026:** implementação candidata validada na
[#522](https://github.com/rafhael100/imagine-sites/pull/522), ainda draft, HEAD
`0b735a1794ee3bb965e5a05d5d738083ddac9544`, com **12/12 checks SUCCESS**.
A branch `feature/sites-brief-provenance-registration-2026-11-18` deriva da #521.
A prova usa apenas materiais sintéticos e recursos descartáveis de CI; não
constitui implantação ou autorização operacional.

## Uso e consumidor

[`register-provenance.php`](../ops/release-candidate/register-provenance.php)
oferece planejamento e registro explícitos para a
[composição de briefing armazenado e artefato](STORED-BRIEF-RELEASE-PROVENANCE.md).
O serviço existente lê o snapshot exato, revalida o perfil candidato, gera o
HTML internamente e registra artefato e associação na mesma chamada SQL.
O comando carrega o autoloader sem inicializar a aplicação Laravel.

O plano e os seis campos editoriais são arquivos privados de entrada. O corpo
rico e seu manifesto vêm do armazenamento autorizado, pelos seletores do
plano. Não há entrada de HTML pronto ou sidecar como prova da geração. Não são
criados SQL, migration, tabela, grant, principal, worker ou contrato oficial do
Portal. Revisão editorial, binding, preparação de pacote e publicação conservam
suas etapas e autorizações próprias.

## Comandos

```bash
php ops/release-candidate/register-provenance.php --help
php ops/release-candidate/register-provenance.php plan
```

`--help` não carrega a aplicação nem abre arquivos privados ou conexões. `plan`
aceita somente o nome do comando. Obtido e revisado o compromisso concreto,
a sintaxe da escrita é:

```bash
php ops/release-candidate/register-provenance.php record \
  --commitment-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_COMMITMENT \
  --authorize-record=SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED
```

O placeholder acima deve ser substituído pelo `commitmentSha256` revisado,
com exatamente 64 caracteres hexadecimais minúsculos. As duas opções são
obrigatórias, podem aparecer em qualquer ordem e não podem se repetir. O hash
do plano bruto e as flags de outras CLIs não substituem essa confirmação.
O literal limita a operação solicitada; não concede autoridade SQL ou alçada
operacional.

## Seis arquivos privados

Os caminhos são fixos. Não há override de manifesto, senha, CA, plano,
editorial, HTML ou destino na linha de comando.

| Caminho | Conteúdo e limite |
| --- | --- |
| `/run/secrets/brief_artifact_registration` | Manifesto de execução, de 1 a 16384 bytes. |
| `/run/secrets/brief_artifact_registration_plan` | Plano `sites.brief-render-plan.candidate.v0.1`, de 1 a 32768 bytes. |
| `/run/secrets/brief_artifact_registration_editorial` | Entrada editorial de seis campos aceita pelo renderer existente, de 1 a 32768 bytes. |
| `/run/secrets/sites_brief_artifact_registration_reader` | Senha do reader de snapshots, de 16 a 4096 bytes antes da validação de formato. |
| `/run/secrets/sites_brief_artifact_registration_writer` | Senha do writer composto, de 16 a 4096 bytes antes da validação de formato. |
| `/run/secrets/sites_brief_artifact_registration_ca` | CA PEM revisada, de 1 a 65536 bytes, sem chave privada e com SHA-256 igual ao target. |

Os arquivos precisam ser regulares, com um único link, modo `0400` ou `0600`,
proprietário root ou usuário efetivo da CLI e caminho absoluto canônico sem
symlinks observados. O comando não cria, normaliza, corrige ou substitui esses
arquivos. Nas senhas, remove somente CR/LF finais; depois exige pelo menos
16 bytes e recusa NUL ou quebras de linha internas.

O [exemplo de manifesto](../ops/release-candidate/provenance-registration.example.json)
contém placeholders deliberadamente inválidos. Seus três campos são exatos:

| Campo | Regra |
| --- | --- |
| `schema` | `SITES_BRIEF_ARTIFACT_REGISTRATION_V1`. |
| `target` | Objeto de sete campos que identifica o writer e o banco revisados. |
| `snapshotReaderLogin` | Login válido, distinto de `target.login`, dedicado à leitura de snapshots. |

O target contém exatamente:

| Campo | Conteúdo |
| --- | --- |
| `host` | Host/IP validado para a conexão e a verificação do certificado. |
| `port` | Inteiro de 1 a 65535, igual à porta observada pelo servidor. |
| `database` | Nome do banco conforme o guard compartilhado. |
| `databaseOid` | OID observado do banco, inteiro de 1 a 4294967295. |
| `login` | Login do writer composto. |
| `serverAddress` | Endereço IP exato observado no servidor. |
| `caSha256` | SHA-256 minúsculo dos bytes da CA revisada. |

O target do reader é derivado desse objeto, trocando apenas o login. Endpoint,
banco, OID, endereço, porta e CA permanecem iguais e são conferidos nas duas
conexões. O manifesto não aceita um segundo banco ou referências independentes
de site, release ou snapshot. Esses seletores vêm do plano validado; o tenant
autorizado continua derivado pelos entrypoints SQL.

O parser estrito existente recusa campos extras, duplicatas inclusive com
escapes, tipos coercíveis e inteiros não canônicos. Plano e editorial também
passam pelo parser e renderer existentes antes de abrir qualquer PDO.

## Compromisso e identidade dos bytes

| Campo | Uso |
| --- | --- |
| `commitmentSha256` | Confirma o target canônico do writer e os bytes exatos do plano, editorial e HTML, com domínio próprio e separadores NUL. É o valor exigido por `record`. |
| `planSha256` | SHA-256 dos bytes originais do plano JSON, incluindo espaços e LF. É o seletor utilizado pela consulta histórica. |
| `artifactDigest` | SHA-256 dos bytes do HTML produzido pelo renderer. |

A CLI recebe o compromisso do serviço existente e não duplica seu algoritmo.
O compromisso **não inclui login ou epoch do reader** e não reserva sua
autorização. A identidade física da conexão e o acesso corrente ao snapshot
são verificados separadamente. Também não reserva a autorização futura do
writer, o estado comercial ou o sucesso de uma gravação posterior.

Mudar os bytes do plano ou editorial pode alterar o compromisso mesmo quando
o HTML é igual. Os arquivos originais e os seletores precisam continuar sob
custódia privada; hashes não substituem esse contexto.

## Conexões, custódia e chamada de escrita

Cada execução abre duas PDOs novas, não persistentes, com TLS `verify-full`,
CA pinada, `gssencmode=disable` e timeout de conexão de cinco segundos. Ambas
começam com `default_transaction_read_only=on`, isolamento `read committed`,
UTF-8, `search_path=pg_catalog`, row security e timeouts limitados.

O reader precisa ter exclusivamente
`sites_core.read_portal_brief_snapshot(uuid,uuid,integer,text)`. O writer
precisa ter exclusivamente
`sites_core.record_brief_release_artifact(text,text,text)`. Os guards recusam
grant option, outra capacidade efetiva nos schemas protegidos e runtime
incompatível. A nova enum interna de snapshots admite somente leitura; não
relaxa as capacidades anteriores. As identidades SQL e seus grants devem
existir por provisionamento autorizado separado.

O fluxo de `record` é o seguinte:

1. Valida o comando, o ambiente, o manifesto e os bytes do plano/editorial;
   lê os seis arquivos privados e registra bytes, hashes e identidade.
2. Abre e observa primeiro o reader em modo somente leitura. Só depois abre
   o writer, também somente leitura, e o observa. Confere os targets, TLS,
   PIDs e capacidades exclusivas. O reader usa a PDO exata, sem réplica,
   boot Laravel, query log ou reconexão funcional.
3. Obtém o plano real pelo serviço. Essa chamada lê o snapshot armazenado e
   valida sua geração. Confere o compromisso informado, as conexões e todos
   os seis arquivos novamente.
4. Habilita escrita somente na PDO do writer e chama `record` uma vez. O
   serviço volta a ler o snapshot exato em transação somente leitura, encerra
   essa transação e invoca a função SQL composta.
5. Valida o recibo, reobserva as duas conexões e relê os seis inputs antes de
   emitir a projeção pública.

`plan` conclui sem o passo de escrita: as duas conexões permanecem somente
leitura. Seu sucesso confirma a leitura e a coerência técnica observadas;
não substitui as verificações do principal corrente realizadas pelo SQL na
gravação.

As releituras comparam bytes, SHA-256, dispositivo, inode, modo, proprietário,
grupo, quantidade de links, tamanho, `mtime` e `ctime`. `atime` fica fora porque
a própria leitura pode alterá-lo. Essas são observações limitadas no tempo,
não uma reserva atômica dos paths. A leitura do snapshot termina antes da
escrita; não existe atomicidade da autorização do reader entre as sessões.

## Saída pública

O stdout de sucesso é uma única linha JSON. O plano tem dez campos; o registro
tem os mesmos campos e acrescenta `recordedAt` e `created`, totalizando doze.

| Campo | `plan` | `record` |
| --- | --- | --- |
| `schema` | `SITES_BRIEF_ARTIFACT_REGISTRATION_PLAN_RESULT_V1` | `SITES_BRIEF_ARTIFACT_REGISTRATION_RESULT_V1` |
| `status` | `planned` | `recorded` |
| `commitmentSha256` | Compromisso conferido. | Mesmo compromisso conferido. |
| `planSha256` | Hash dos bytes originais do plano. | Mesmo hash. |
| `artifactDigest` | Digest do HTML gerado. | Mesmo digest. |
| `sizeBytes` | Tamanho do HTML gerado. | Mesmo tamanho. |
| `recordedAt` | Ausente. | Timestamp histórico UTC com seis casas decimais. |
| `created` | Ausente. | Booleano do recibo SQL: criação ou replay exato aceito. |
| `authorityVerified` | `false` | `false` |
| `clientAccepted` | `false` | `false` |
| `canPublish` | `false` | `false` |
| `canAcceptRealOrders` | `false` | `false` |

A CLI valida o recibo interno de vinte campos e emite somente essa projeção,
com schema próprio. Site, release, snapshot, operação, idempotência, autor,
epoch, caminhos, senhas, plano, briefing, editorial e HTML ficam fora de
stdout. Não há arquivo de saída.

O replay conserva as regras do registry: mesma associação e bytes exatos,
autor original e epoch compatível, além da autorização corrente. Outro plano
para o mesmo release é conflito; um artefato preexistente sem associação não
é adotado retroativamente. Uma saída `created=false` não autoriza outra ação.

## Falha e apuração

As recusas tratadas retornam exit 1, stdout vazio e somente
`BRIEF_ARTIFACT_REGISTRATION_DENIED` no stderr. O conflito interno recebe a
mesma projeção pública; não são emitidos diagnósticos SQL ou valores privados.

Uma falha de resposta, custódia ou observação após a chamada SQL pode ocorrer
**depois do commit**. O comando não promete ausência de gravação nem tenta
reparar, desfazer ou repetir a operação.

Para apurar, use a [consulta exata de proveniência](BRIEF-ARTIFACT-INSPECTION.md)
com seu reader corrente e seus três mounts próprios. Preserve `siteRef` e
`releaseRef` do plano original e seu **`planSha256` bruto**, não o compromisso.
A consulta recupera o recibo histórico mesmo quando o writer original já não
pode repetir a gravação. Uma recusa da consulta não demonstra que o registro
está ausente. Qualquer nova tentativa depende de decisão explícita e das
regras correntes do registry; não há retry automático.

O recibo histórico comprova a associação técnica persistida. A API SQL também
admite bytes estruturalmente coerentes fornecidos por um writer autorizado;
por isso a consulta, isoladamente, não certifica execução do renderer PHP.
Nenhum dos resultados verifica pagamento, aceite ou publicação.

## Ambientes e evidência disponível

O laboratório exige simultaneamente `GITHUB_ACTIONS=true`, `APP_ENV=testing`
e `SITES_BRIEF_ARTIFACT_REGISTRATION_CI=1`. Fixa `host=localhost`, porta `55449`,
banco `postgres` e endereço observado `127.0.0.1`. Os writers permitidos no
CI são `bpr_b_writer` e `bsr_b_writer`; os readers são `bpr_b_source_reader`,
`bpr_a_source_reader` e `bsr_b_source_reader`. Os atores `bsr_*` pertencem à
[composição posterior do receptor](reviews/SITES-BRIEF-SNAPSHOT-CLI-CHAIN-2026-11-18.md).
Essa allowlist não concede duty ou acesso a outra empresa; os entrypoints SQL
verificam cada recurso. Staging mantém o contrato anterior.

Fora do CI, o código admite `APP_ENV=staging`, banco `sites_staging`, sem
`GITHUB_ACTIONS=true` nem opt-in de registro igual a `1`. Essa condição técnica
**não autoriza execução em staging nesta entrega**, provisionamento de
credenciais, merge, deploy, uso de dados reais ou publicação.

Validação local observada:

| Prova | Resultado |
| --- | --- |
| [`brief-artifact-registration-cli-offline.php`](../tests/brief-artifact-registration-cli-offline.php) | 95 checks de help e recusas limitadas antes da conexão. |
| [`brief-render-provenance-offline.php`](../tests/brief-render-provenance-offline.php) | 109 checks de regressão do consumidor puro. |
| [`release-registration-offline.php`](../tests/release-registration-offline.php) | 271 checks de regressão do registro anterior. |
| Suíte geral local | 279 testes / 4250 assertions / 27 skips; skips não representam prova PostgreSQL. |
| Lint e revisão independente | Concluídos para o wrapper, enum e teste offline. |
| Integração própria PostgreSQL/TLS da nova CLI | Aprovada em PHP 8.3/8.4, com 27 checks novos e 1094 registry totais; jobs abaixo. |

### Evidência final da #522

| Prova | PHP 8.3 | PHP 8.4 |
| --- | --- | --- |
| [Registry, run 44349683339](https://github.com/rafhael100/imagine-sites/actions/runs/44349683339) | [Job 134961579543](https://github.com/rafhael100/imagine-sites/actions/runs/44349683339/job/134961579543) | [Job 134961581119](https://github.com/rafhael100/imagine-sites/actions/runs/44349683339/job/134961581119) |
| Contagens por job | 95 offline da CLI; 51 do inspetor; 27 novas de registro, 25 de inspeção e 119 da composição; **1094 registry totais**. | Mesmas contagens. |
| Cleanup | Seis arquivos novos e três do inspetor removidos sob recibos de propriedade; cluster próprio inativo. | Mesmo resultado. |
| [Laravel, run 44349683049](https://github.com/rafhael100/imagine-sites/actions/runs/44349683049) | [Job 134961577430](https://github.com/rafhael100/imagine-sites/actions/runs/44349683049/job/134961577430) | [Job 134961576714](https://github.com/rafhael100/imagine-sites/actions/runs/44349683049/job/134961576714) |
| Padrão / sintético | **279/4250/27** e **279/4262/21**. | Mesmas contagens. |

A fixture confirmou um recibo real de doze campos e outro registro com stdout
descartado, recuperado pelo inspetor sem repetir o writer. As duas jornadas
acrescentaram somente dois pares de artefato/associação sobre um snapshot já
existente. Confirmou também a recusa de credencial alterada no mesmo inode
durante uma leitura realmente bloqueada, antes de qualquer append. Não houve
nova execução F383 ou de containers na #522; as provas anteriores mantêm seus
próprios HEADs. A correção posterior do CSS e seus vetores tem validação própria
no [recorte de texto longo](reviews/SITES-RENDERER-LONG-TEXT-2026-11-18.md).

A prova já aprovada da consulta histórica está registrada no
[manual de inspeção](BRIEF-ARTIFACT-INSPECTION.md#validação-isolada). Ela não
substitui a execução da CLI de escrita identificada acima. Os gates comerciais e
AGI-11-RB01 permanecem inalterados.
