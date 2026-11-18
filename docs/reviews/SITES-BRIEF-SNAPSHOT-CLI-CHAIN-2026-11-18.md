# Da entrada privada do briefing à proveniência do artefato

## Estado e origem

Candidata `integration/sites-brief-snapshot-cli-chain-2026-11-18`, baseada na
[#525](https://github.com/rafhael100/imagine-sites/pull/525), HEAD
`e5c30cd7157b97aabedaec009e1fca366b0d0335`, **14/14 checks SUCCESS**, incluindo
451 HTTP e Laravel 284/4280/27 e 284/4292/21 nas duas versões PHP. Incorpora a entrada da
[#523](https://github.com/rafhael100/imagine-sites/pull/523), fixada em
`751b084fdd7a3bbbd276c649de2caf8c2f555b77`, preservando a branch de origem.
A prova PostgreSQL18/TLS desta composição passou em PHP 8.3/8.4, com os runs
e jobs próprios registrados abaixo.

O receptor existente oferecia uma entrada local para o armazenamento nativo.
Faltava exercitá-lo com banco real e acompanhar os mesmos bytes até os
consumidores de produção candidatos. Esta composição usa os serviços e APIs
existentes; não acrescenta SQL, migration, provider ou transporte Portal.

## Contrato preservado

[`receive-brief-snapshot.php`](../../ops/release-candidate/receive-brief-snapshot.php)
valida o manifesto e o corpo completos por `BriefSnapshotInput`, antes de abrir
conexão. Target, manifesto, corpo, senha e CA vêm de cinco mounts fixos privados,
com limites e custódia revalidados antes da conexão, da escrita e do stdout.

O plano observa uma PDO própria em modo somente leitura, com TLS `verify-full`,
identidade de banco/servidor e a capacidade exclusiva
`sites_core.record_portal_brief_snapshot(text,text)`. Não consulta ou reserva
o principal privado, o CAS ou sua epoch. O compromisso vincula domínio, alvo
canônico e os hashes dos dois JSONs originais.

O registro exige o compromisso recalculado e a confirmação literal. Somente a
sessão corrente passa a escrita; o recorder recebe uma única chamada e mantém
sua transação, mesma PDO, CAS e replay. A CLI não repete a operação em falhas.

| Resultado público | Campos | Interpretação |
| --- | ---: | --- |
| Plano | 10 | Schema/status, compromisso, hashes dos dois JSONs, revisão e quatro flags falsas. |
| Registro | 11 | Os mesmos dados técnicos, com status/schema de registro e `recordedAt`. |

O resultado não tem `created`: um replay exato conserva o timestamp anterior.
Não expõe referências privadas, texto do briefing, login, epoch ou credenciais.
Uma falha posterior pode seguir commit. A apuração usa seletores privados exatos
e um leitor corrente, sem inferir ausência pela perda de resposta.

## Ajustes limitados da integração

Nove negativos do teste offline original tinham a asserção dentro do `try` e
capturavam sua própria falha. Agora capturam somente a chamada do validador e
afirmam a recusa fora do `catch`. Isso corrige a prova; não demonstra que o guard
original aceitava entradas inválidas. O harness conserva apenas `LD_LIBRARY_PATH`
quando necessário ao loader PHP local, sem herdar flags de autorização.

Os receivers `bsr_a_receiver` e `bsr_b_receiver` são os únicos nomes do ramo CI
novo. O registro composto admite ainda `bsr_b_writer` e `bsr_b_source_reader` no
seu ramo CI, mantendo os nomes anteriores. Os quatro atores são próprios desta
fixture, com funções exclusivas e separadas. A allowlist não concede recursos;
a autorização corrente continua no SQL. Nenhum ator revogado é reativado.

Staging, formatos, limites e os seis mounts do registro composto permanecem
iguais. O inspetor `bai_b_reader` ainda autorizado é reutilizado. A enumeração
de conexão adiciona um caso fechado; as quatro capacidades anteriores mantêm
suas APIs exatas e suas exigências de leitura/escrita.

## Prova integrada executada

A [fixture](../../tests/fixtures/brief-snapshot-receiver/contract.php) roda após
o registro privado da #522 e antes do down da migration 14. Reutiliza os intakes
e projetos nativos existentes, sem outra recepção de pedido ou outbox.

O percurso executado foi:

1. Plano do receiver sem mudança de linhas ou catálogo, seguido do recebimento
   da revisão 2 de B, com versão de origem 13 e recibo de onze campos.
2. Recebimento da revisão 3/versão de origem 14 com stdout descartado. O leitor
   exato recupera e revalida os dois JSONs antes de qualquer registro de artefato.
3. Plano e registro pela CLI composta, usando leitor e writer separados; um
   único par artefato/associação referencia a revisão 3 recém-recebida.
4. Consulta pelo inspetor histórico atual, conferindo o digest e o plano exato.
5. Replay da revisão 2 após head 3 e alteração da epoch do receiver, mantendo
   bytes e timestamp. CAS concorrente obsoleto, alteração dos bytes originais,
   tenant divergente e receiver revogado são recusados sem novo append.

O controle positivo de A usa replay de sua própria revisão antes da tentativa
contra B. As recusas de alvo, capacidade, compromisso e custódia são verificadas
com entradas elegíveis. Comparações integrais limitam o delta a dois snapshots
e um par de artefato/associação; alterações dos quatro principais próprios são
explicitamente controladas. As entradas de registro e inspeção são restauradas
com a identidade dos arquivos preservada, também em `finally`.

O [helper](../../tests/fixtures/brief-snapshot-receiver/cli-paths.py) reserva
somente cinco mounts novos, recusa paths preexistentes e registra sua posse.
Antes de qualquer remoção, o cleanup valida o inventário inteiro. A negativa de
identidade conserva e restaura o arquivo pelo mesmo descritor. O runner remove
esses mounts antes dos de registro/inspeção e mantém a verificação de cluster
próprio encerrado.

## Validação e limites

**74 checks offline PASS**, lint PHP e revisão independente da CLI/observer
concluídos. A suíte geral local passou com **284 testes/4280 assertions/27 skips**,
usando `--do-not-cache-result`; os grupos de regressão passaram com **95 checks**
do registro privado, **271** do registro anterior e **32** do laboratório sintético.
O helper foi validado sintaticamente sem executar ações privilegiadas
locais. A execução real confirmou **29 novas assertions**, somadas às
**1094 anteriores**, totalizando **1123** em cada versão PHP.

O [workflow próprio](../../.github/workflows/sites-brief-snapshot-cli-chain.yml)
fixa draft, repositório, branch, base e checkout do HEAD. Preserva quinze opt-ins
da base e acrescenta o do receiver, dependente da composição completa; executa
PHP 8.3/8.4 com PostgreSQL18/TLS descartável e cleanup obrigatório.
O workflow importado da #523 continua limitado à sua branch/base originais;
a mesma restrição de base também foi aplicada ao filtro do evento para evitar
uma execução inaplicável na nova PR.

## Evidência final da #526

A [PR #526](https://github.com/rafhael100/imagine-sites/pull/526) concluiu **12/12
checks SUCCESS** no HEAD `2ffbcb5303afe12593e11a11a4a6dd45a175ac3f`.

| Execução | PHP 8.3 | PHP 8.4 |
| --- | --- | --- |
| [Registry 44358994325](https://github.com/rafhael100/imagine-sites/actions/runs/44358994325) | Job **134994843518**: 74 offline; **29 novos/1123 registry totais**. | Job **134994844208**: mesmas contagens. |
| [Laravel 44358994106](https://github.com/rafhael100/imagine-sites/actions/runs/44358994106) | Job **134994842923**: padrão **284/4280/27**, sintético **284/4292/21**. | Job **134994842386**: mesmas contagens. |

O pipeline confirmou ainda os 27 checks do registro privado, 25 de inspeção,
119 de composição e 923 anteriores. A composição manteve seu rollback/reapply
ordenado da migration14. Cleanup removeu os cinco mounts novos, os seis do
registro e os três da inspeção, além dos recursos anteriores; ambos os clusters
próprios ficaram inativos. Não houve novo workflow F383, de containers ou HTTP
nesta PR; essas provas permanecem atribuídas à base #525.

O corpo rico continua candidato e a entrada editorial é escolhida separadamente;
o renderer não extrai nem inventa conteúdo do briefing. Recebimento técnico,
associação e consulta não verificam aprovação oficial do Portal, pagamento,
aceite ou publicação. Os dez gates continuam BLOCKED. Exportação consistente,
produtor, transporte e materiais operacionais permanecem integrações próprias.
