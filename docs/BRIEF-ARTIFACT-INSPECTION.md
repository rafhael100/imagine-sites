# Consulta privada do recibo de proveniência

**Estado em 18/11/2026:** implementação candidata validada na
[#521](https://github.com/rafhael100/imagine-sites/pull/521), ainda draft, com
**12/12 checks SUCCESS**, HEAD `73d610513c777c5f3a1709fcb19263d6073139c6`.
A branch `feature/sites-brief-provenance-inspection-2026-11-18` deriva da
[#520](https://github.com/rafhael100/imagine-sites/pull/520). Há prova própria
PostgreSQL/TLS da consulta; não existe ativação operacional.

## Para que serve

Depois de uma tentativa de registro composto, a resposta pode se perder mesmo
que o banco tenha concluído a gravação. Esta consulta recupera o recibo da
associação exata por meio de um reader atualmente autorizado. Não é necessário
repetir o writer ou reativar a identidade do autor original.

O comando consome o serviço
[`StoredBriefReleaseReceiptReader`](../apps/sites/app/Integration/StoredBriefReleaseReceiptReader.php)
e a API SQL já definidos na [composição de briefing e artefato](STORED-BRIEF-RELEASE-PROVENANCE.md).
Não acrescenta tabela, migração, parser JSON, contrato de transporte ou caminho
de escrita. A consulta confirma uma associação técnica persistida; não
comprova execução do renderer, elegibilidade atual do pacote, aprovação do
cliente ou permissão para publicar.

A [CLI de registro a partir do snapshot armazenado](STORED-BRIEF-REGISTRATION-CLI.md)
usa essa consulta como caminho de apuração após uma resposta perdida. A escrita
tem seis mounts, reader de snapshot e writer distintos; esta inspeção conserva
seus três mounts e um reader histórico separado. Não reutiliza credencial do
writer, não chama a função de escrita e não depende de seu replay continuar
autorizado.

## Comandos e entradas

```bash
php ops/release-candidate/inspect-provenance.php --help
php ops/release-candidate/inspect-provenance.php inspect
```

`--help` é informativo e não acessa banco ou arquivos privados. `inspect` exige
ambiente admitido e três arquivos privados montados nos caminhos fixos abaixo.
Não recebe seletores, senha, SQL, DSN ou caminho alternativo na linha de comando.

| Arquivo | Conteúdo |
| --- | --- |
| `/run/secrets/brief_artifact_inspection` | Manifesto privado da consulta, até 16384 bytes. |
| `/run/secrets/sites_brief_artifact_inspection_reader` | Senha do reader, entre 16 e 4096 bytes antes da validação de formato. |
| `/run/secrets/sites_brief_artifact_inspection_ca` | Certificado CA esperado, até 65536 bytes, sem chave privada. |

O manifesto contém exatamente cinco campos:

| Campo | Regra |
| --- | --- |
| `schema` | `SITES_BRIEF_ARTIFACT_INSPECTION_V1`. |
| `target` | Exatamente `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress`, `caSha256`, conforme o guard compartilhado. |
| `siteRef` | UUID canônico do site conhecido. |
| `releaseRef` | Referência opaca do release conhecido. |
| `planSha256` | SHA-256 dos bytes originais do plano que originou a associação. |

`planSha256` não é o `commitmentSha256` retornado pelo planejamento da escrita.
O compromisso inclui target, plano, editorial e HTML; a seleção histórica usa
o hash do plano original. A consulta não tenta reconstruir o target ou a
autorização daquela escrita.

Os seletores precisam vir do contexto privado já conhecido pelo chamador.
Não há listagem, descoberta por prefixo ou troca pela revisão mais recente.
Um plano com bytes diferentes, mesmo que gere o mesmo HTML, seleciona outro
hash e não encontra silenciosamente o registro anterior.

Para uma tentativa de `register-provenance.php`, conserve em ambiente privado
o plano original e os campos `siteRef` e `releaseRef` nele contidos. Use o
`planSha256` público conferido naquela execução; não use `commitmentSha256`
como seletor nem altere o plano para investigar uma resposta perdida.

## Custódia e conexão

A leitura usa a proteção de arquivos existente e observa bytes, hash e
identidade de cada arquivo, incluindo inode, proprietário, modo, quantidade de
links, tamanho e tempos de modificação. Recusa arquivo inseguro, substituído
ou alterado. A comparação final dos três inputs acontece antes de liberar a
resposta. Essa observação não equivale a reservar a identidade dos arquivos
indefinidamente depois do último check.

O CLI abre uma PDO nova, não persistente, com `sslmode=verify-full`, CA pinada e
timeout de conexão. A sessão é configurada somente para leitura, UTF-8,
`read committed`, `search_path=pg_catalog` e timeouts limitados. O serviço
confere a identidade efetiva do banco/login/endereço e a capacidade exclusiva
de leitura antes e depois de sua única chamada SQL. Não aceita transação do
chamador e não faz retry automático.

Em CI, exige `GITHUB_ACTIONS=true`, `APP_ENV=testing` e
`SITES_BRIEF_ARTIFACT_INSPECTION_CI=1`; o alvo fica limitado ao cluster
descartável `localhost:55449`, banco `postgres`, endereço `127.0.0.1` e aos
readers sintéticos `bai_a_reader` ou `bai_b_reader`. O runner exige também a
cadeia anterior de opt-ins. Fora do CI, o código exige o modo `staging`, banco
`sites_staging` e ausência do opt-in de laboratório. Esses limites não
constituem autorização para criar ou usar credenciais de staging nesta tarefa.

## Resultado e erro

O stdout contém um único objeto JSON de dez campos:

| Campo | Valor |
| --- | --- |
| `schema` | `SITES_BRIEF_ARTIFACT_INSPECTION_RESULT_V1` |
| `status` | `inspected` |
| `planSha256` | Hash exato consultado e conferido no recibo. |
| `artifactDigest` | SHA-256 dos bytes de HTML associados. |
| `sizeBytes` | Tamanho do artefato associado. |
| `recordedAt` | Timestamp histórico UTC com seis casas decimais. |
| `authorityVerified` | `false` |
| `clientAccepted` | `false` |
| `canPublish` | `false` |
| `canAcceptRealOrders` | `false` |

O recibo interno de 18 campos é validado pelo serviço existente. O CLI libera
somente a projeção acima. Referências de site/release/snapshot, operação,
idempotência, autor, epoch, corpo do briefing, editorial, plano e HTML não são
emitidos. Não é escrito arquivo de saída.

Todas as recusas terminam com stdout vazio, código de saída não zero e somente
`BRIEF_ARTIFACT_INSPECTION_DENIED` no stderr. Seleção desconhecida, empresa
diversa, reader revogado, privilégio incompatível, falha de TLS, formato ou
custódia não revelam diagnósticos SQL nem caminhos/credenciais.

## Interpretação da evidência

Um novo snapshot não altera a associação histórica. Uma mudança de epoch ou
revogação do writer impede seu replay conforme o registry, mas não apaga a
evidência disponível para um reader atualmente autorizado. Revogar o reader
impede sua consulta seguinte. Um resultado anterior não reserva autorização
futura.

O SQL também pode conservar HTML fornecido diretamente por um writer
autorizado, desde que seus bytes e vínculos sejam coerentes. Consultar esse
registro não transforma o recibo em prova de execução PHP. A fixture inclui
essa origem estrutural, além dos registros feitos pelo consumidor de
renderização, para preservar a distinção.

Não encontrar ou não conseguir consultar uma seleção não é prova genérica de
que a tentativa de escrita falhou. Primeiro é necessário manter os seletores
originais e a autorização corrente da consulta; o erro público deliberadamente
não distingue a existência de dados fora desse escopo.

## Validação isolada

O ensaio reaproveita as associações elegíveis da fixture da #520 antes do
rollback da migration 14. Ele cria somente leitores sintéticos próprios e
exercita a CLI real com os três mounts privados, certificado e PostgreSQL 18.
Os casos cobrem recuperação de resposta descartada, autor com epoch alterado
ou revogado, outra empresa elegível, seleção divergente e revogação do reader.

Os snapshots de linhas e catálogo devem permanecer idênticos durante cada
consulta. O helper registra os recursos que criou, prova recusa de inode
divergente e limita o cleanup aos seus três arquivos. O cluster, demais mounts
e serviços existentes são preservados fora do laboratório próprio.

**Validação local observada:** 51 checks offline de ajuda e recusas antes de
conexão, lint PHP, AST Python e revisão independente dos contratos/custódia.
O teste offline não simula consulta SQL bem-sucedida.

No HEAD final `73d610513c777c5f3a1709fcb19263d6073139c6`, a execução própria
em PostgreSQL/TLS foi aprovada nas duas versões PHP:

| Evidência | PHP 8.3 | PHP 8.4 |
| --- | --- | --- |
| [Registry, run 44346085528](https://github.com/rafhael100/imagine-sites/actions/runs/44346085528) | [Job 134948726218](https://github.com/rafhael100/imagine-sites/actions/runs/44346085528/job/134948726218) | [Job 134948724966](https://github.com/rafhael100/imagine-sites/actions/runs/44346085528/job/134948724966) |
| Contagens registry por job | 51 offline; 25 novas de inspeção; 119 da composição; 1067 totais. | Mesmas contagens. |
| Cleanup por job | Três arquivos próprios removidos; cluster próprio inativo. | Mesmo resultado. |
| [Laravel, run 44346084649](https://github.com/rafhael100/imagine-sites/actions/runs/44346084649) | [Job 134948727283](https://github.com/rafhael100/imagine-sites/actions/runs/44346084649/job/134948727283) | [Job 134948726466](https://github.com/rafhael100/imagine-sites/actions/runs/44346084649/job/134948726466) |
| Suíte padrão | 279 testes / 4250 assertions / 27 skips. | Mesmo resultado. |
| Modo sintético | 279 testes / 4262 assertions / 21 skips. | Mesmo resultado. |

A #521 não possui nova prova F383 ou de containers: essas evidências pertencem
à base #520. Os resultados acima também não provam a execução da nova CLI de
registro, que possui validação própria pendente. Nenhum teste autoriza merge,
deploy ou dados reais. Os dez gates de prontidão continuam BLOCKED e
AGI-11-RB01 permanece desativado.
