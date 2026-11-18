# Entrada privada candidata: armazenar um snapshot de briefing Onepage

**18/11/2026 — candidata isolada, não operacional.** Este incremento cobre a
lacuna de entrada local para o serviço de armazenamento da #519. Não autentica
o produtor Portal, não cria transporte interserviços e não ativa produção.

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

## Controles e testes

A CLI só admite `APP_ENV=staging` fora do GitHub Actions, com banco
`sites_staging`, ou o opt-in de CI `APP_ENV=testing`,
`GITHUB_ACTIONS=true` e `SITES_BRIEF_SNAPSHOT_RECEIVER_CLI_CI=1`,
com alvo `localhost:55449/postgres` e IP observado `127.0.0.1`.
Essas condições não criam login, arquivos ou privilégios.

`tests/brief-snapshot-receiver-cli-offline.php` executa recusas de CLI
em subprocesso sem acesso à aplicação/segredos, além da validação local
do target e do hash da CA. O workflow dedicado verifica PHP 8.3/8.4
somente em PR draft e com base exata. **O ensaio de gravação real em
PostgreSQL 18/TLS e revisão de produto ainda são necessários** antes
de considerar esta candidata homologada. Sucesso offline não equivale
a recibo de banco.

Ficam explicitamente fora deste incremento: origem oficial e exportação
atômica no Portal, credenciais operacionais, deploy VPS, integração n8n,
publicação de site, migração com dados de clientes, pagamento, aceite,
`ENTREGUE` e os dez gates de ativação. O código deve permanecer em
draft para revisão; não realizar merge ou deploy por efeito deste manual.
