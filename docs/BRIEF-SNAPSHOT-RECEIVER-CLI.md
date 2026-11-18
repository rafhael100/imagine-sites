# Entrada privada candidata: armazenar um snapshot de briefing Onepage

**18/11/2026 — candidata isolada, não operacional.** Este incremento cobre a
lacuna de entrada local para o serviço de armazenamento da #519. Não autentica
o produtor Portal, não cria transporte interserviços e não ativa produção.

## Origem e composição atual

A implementação foi incorporada da [PR #523](https://github.com/rafhael100/imagine-sites/pull/523),
HEAD `751b084fdd7a3bbbd276c649de2caf8c2f555b77`, em nova branch isolada
`integration/sites-brief-snapshot-cli-chain-2026-11-18`. A branch de origem não foi
alterada. A composição parte da #525 e inclui a correção de quebra de texto da
#524 e de linhas vazias Unicode do workspace; essas regras de formulário não
modificam o JSON editorial da CLI.

Os nove negativos offline de target/CA foram corrigidos para afirmar a recusa
fora do `catch`, sem capturar a falha do próprio teste. O subprocesso conserva
somente `LD_LIBRARY_PATH` quando necessário ao loader local. A revisão confirmou
os envelopes e a sessão da implementação original; a nova prova usa PostgreSQL
real e os consumidores existentes. [Recorte, atores e estado da prova](reviews/SITES-BRIEF-SNAPSHOT-CLI-CHAIN-2026-11-18.md).

## Escopo implementado

`ops/release-candidate/receive-brief-snapshot.php` recebe **cinco caminhos
privados fixos**, sem argumentos de arquivo e sem boot completo do Laravel:

| Mount local | Conteúdo e teto |
| --- | --- |
| `/run/secrets/sites_brief_snapshot_receive_target` | Objeto `SITES_BRIEF_SNAPSHOT_RECEIVER_V1`, 16.384 bytes |
| `/run/secrets/sites_brief_snapshot_receive_manifest` | JSON original de snapshot candidato, 32.768 bytes |
| `/run/secrets/sites_brief_snapshot_receive_body` | JSON original do corpo Onepage, 150.000 bytes |
| `/run/secrets/sites_brief_snapshot_receive_password` | Senha do principal receiver, 4.096 bytes |
| `/run/secrets/sites_brief_snapshot_receive_ca` | Certificado CA, 65.536 bytes |

O alvo contém somente `host`, `port`, `database`, `databaseOid`,
`login`, `serverAddress` e `caSha256`, com nomes exatos. O
[exemplo sintético](../ops/release-candidate/brief-snapshot-receive.example.json)
não representa um servidor existente nem contém segredo utilizável.

Os cinco arquivos precisam ser regulares, não links, com um hardlink, dono
aceito e modo 0400/0600. Cada leitura conserva os bytes e compara
identidade, tamanho, horários e SHA-256 antes/depois. A CA real é conferida
pela soma SHA declarada no target, com PostgreSQL `verify-full`; não há
resolução de URLs presentes no briefing.

O parser `BriefSnapshotInput` revalida os JSONs completos, incluindo limites
UTF-16, campos obrigatórios, contexto, hash do corpo, revisão sucessora e
perfil Onepage sem materiais. O runtime SQL exige PostgreSQL 18, identidade
sem superpoderes, `search_path=pg_catalog`, `row_security=on` e o
único EXECUTE `sites_core.record_portal_brief_snapshot(text,text)`.
Não concede ou ativa principal, nem altera migrations.

## Duas operações explícitas

```bash
php ops/release-candidate/receive-brief-snapshot.php plan
php ops/release-candidate/receive-brief-snapshot.php record \
  --commitment-sha256=<64-hex-lowercase> \
  --authorize-store=SITES_BRIEF_SNAPSHOT_CANDIDATE_ONLY_APPROVED
```

`plan` conecta com a identidade receiver em modo somente leitura e
confere o alvo e os cinco arquivos sem chamar o recorder. Devolve dez
campos: schema, status, compromisso, hashes dos dois JSONs, revisão e
quatro capacidades falsas. O compromisso SHA-256 vincula domínio
`sites.brief-snapshot-receiver.v1`, target canônico (incluindo login,
OID e CA) e hashes dos bytes originais. Não é um token de autorização
do Portal, um lock ou um grant; não se confunde com o contador
`sourceVersion`.

`record` exige compromisso igual ao plano recalculado e a confirmação
literal. Só a sessão corrente do receiver é alterada para escrita antes
de uma chamada a `PortalBriefSnapshotRecorder::record`. O serviço
aplica o CAS e o replay exato da migration 13 dentro de transação própria,
sem retry. O resultado público mantém os hashes e a revisão, inclui
`recordedAt` e os quatro booleanos falsos. `recorded` **não** afirma
que a linha foi criada nesta chamada, pois um replay exato conserva o
timestamp anterior.

Uma recusa responde apenas `BRIEF_SNAPSHOT_RECEIVE_DENIED` em stderr,
exit 1 e stdout vazio, inclusive em divergência de compromisso, permissão
ou conexão. Uma falha após chamar o SQL pode ocorrer **depois do commit**:
não repetir automaticamente; apurar a revisão exata com um leitor
independente atualmente autorizado, nunca inferir ausência a partir da
recusa ou de uma resposta perdida.

## Consulta da seleção original após perda da resposta

A candidata [`inspect-brief-snapshot.php inspect`](BRIEF-SNAPSHOT-INSPECTION-CLI.md)
oferece essa apuração pela mesma leitura exata usada pelo consumidor. Conserve
o manifesto original em ambiente privado: dele vêm `siteRef`, `snapshotRef` e
`briefRevision`; o hash de seus bytes completos fornece `manifestSha256`.
Esses quatro seletores e o target de um reader separado formam a configuração
da consulta. O `commitmentSha256` do receiver não substitui o hash do manifesto.

A inspeção mantém a sessão somente leitura e revalida a autoridade corrente do
reader. Retorna dez campos com hashes, revisões e quatro flags falsas, sem os
JSONs ou `recordedAt`. Ela pode confirmar a seleção armazenada mesmo depois da
revogação do receiver original; não identifica qual tentativa a criou, não
reconstrói o timestamp nem repete a escrita. Uma recusa também não comprova
ausência do snapshot.

Essa CLI não muda a prova histórica da #526 abaixo. Sua validação local tem
**56 checks offline e suíte geral 284/4310/27 PASS**; **26 checks novos/1149
registry totais permanecem PENDENTES do CI próprio**. Depois de registrar um
artefato, a [inspeção de proveniência](BRIEF-ARTIFACT-INSPECTION.md) usa os
seletores daquele registro, em uma consulta distinta.

## Controles e testes

A CLI só admite `APP_ENV=staging` fora do GitHub Actions, com banco
`sites_staging`, ou o opt-in de CI `APP_ENV=testing`,
`GITHUB_ACTIONS=true` e `SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI=1`,
com alvo `localhost:55449/postgres`, IP observado `127.0.0.1` e login
exclusivo `bsr_a_receiver` ou `bsr_b_receiver`.
Essas condições não criam login, arquivos ou privilégios.

`tests/brief-snapshot-receiver-cli-offline.php` executa recusas de CLI
em subprocesso sem acesso à aplicação/segredos, além da validação local
do target e do hash da CA. A versão corrigida passou localmente com **74 checks**.
O workflow original da #523 foi preservado com suas condições originais. O
[workflow da composição](../.github/workflows/sites-brief-snapshot-cli-chain.yml)
fixa sua própria base/HEAD, executa PHP 8.3/8.4 e acrescenta a prova real com
PostgreSQL18/TLS. A [#526](https://github.com/rafhael100/imagine-sites/pull/526),
HEAD `2ffbcb5303afe12593e11a11a4a6dd45a175ac3f`, concluiu **12/12 checks SUCCESS**.
O [run 44358994325](https://github.com/rafhael100/imagine-sites/actions/runs/44358994325)
confirmou **29 novos/1123 registry totais** e 74 offline nas duas versões, com
cleanup dos arquivos próprios e clusters inativos. [Jobs e limites da prova](reviews/SITES-BRIEF-SNAPSHOT-CLI-CHAIN-2026-11-18.md).
Sucesso offline continua distinto do recibo efetivamente persistido em banco.

Ficam explicitamente fora deste incremento: origem oficial e exportação
atômica no Portal, credenciais operacionais, deploy VPS, integração n8n,
publicação de site, migração com dados de clientes, pagamento, aceite,
`ENTREGUE` e os dez gates de ativação. O código deve permanecer em
draft para revisão; não realizar merge ou deploy por efeito deste manual.
