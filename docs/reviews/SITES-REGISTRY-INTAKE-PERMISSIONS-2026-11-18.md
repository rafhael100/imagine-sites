# Imagine Sites — cobertura das permissões do registry após a entrada do Portal

## Lacuna demonstrável no código

A PR [#512](https://github.com/rafhael100/imagine-sites/pull/512), HEAD `9b80387fe1c580436b444a6aad2b63f3baafa37d`, acrescenta `portal_project_intakes` ao schema nativo. O recebimento persiste uma solicitação pendente e um recibo estável, mantendo as autoridades comerciais falsas. A nova tabela passa a ser a décima segunda de `sites_core`.

O guard `release_registry_runtime_safe(text)` ainda percorria uma lista fixa das onze tabelas anteriores em dois pontos: a recusa de privilégios diretos do login e a recusa de permissões indevidas dos definers. A tabela nova ficava fora de ambos. Um grant direto nessa relação podia, portanto, deixar de impedir a chamada de registry, contrariando sua fronteira de permissões.

Isso descreve uma omissão na detecção de ACL indevida. Não é uma demonstração de leitura de recibos: a RLS da tabela permanece ativa e continua sendo uma defesa separada.

A candidata `fix/sites-registry-intake-permissions-2026-11-18` parte do SHA completo da #512. As melhorias de interface das PRs #511, #513 e #514 estão em outra linha de branches; esta correção não depende delas nem as incorpora.

## Correção aditiva

A migration 12 substitui somente o corpo da função existente, por dois contratos SQL pinados. As migrations 5 e 11 permanecem intactas.

Os dois loops passam a enumerar as tabelas comuns e particionadas (`relkind r/p`) de `sites_core`, incluindo o ledger de recebimento e futuras tabelas desse schema. Os predicados de RLS, ownership e permissões são os mesmos do corpo anterior. A presença das onze relações-base continua obrigatória, com o mesmo tipo de relação exigido; trocar uma delas por uma view não a remove silenciosamente da verificação.

A alteração não consulta o schema `sites_publication`. O journal já possui sua própria varredura, e sua dependência do helper do registry permanece.

## Identidade e reversão

Cada direção usa um único `CREATE OR REPLACE FUNCTION`. O PostgreSQL preserva owner e permissões nessa operação; a migration também confere concretamente os atributos antes e depois. [Fonte primária: PostgreSQL 18, CREATE FUNCTION](https://www.postgresql.org/docs/18/sql-createfunction.html).

Antes de executar, a migration exige PostgreSQL 18, assinatura e metadados seguros, owner existente sem capacidade de login/membros, ausência de EXECUTE público e hash exato do corpo de origem. Confere o hash do arquivo SQL e, depois, o corpo resultante. Compara todos os atributos de `pg_proc`, exceto `prosrc`: OID, owner, ACL, configuração e demais propriedades devem permanecer idênticos. Isso preserva também o grant legítimo de EXECUTE ao owner do journal, caso já exista.

| Pin | SHA-256 |
| --- | --- |
| Corpo original | `22049d78c3a820e23f08e6ea348c044a677508ad16e5a5d5281cbc67014e778b` |
| Corpo expandido | `a6707a10197400c20651603949abe6ffe4dd48e52fd40b595c3832b42e1a9753` |
| SQL de aplicação | `95a244d76633605a789f7c413a5f5bae3f7c784a5c5f0c90336f3146d0ecf40c` |
| SQL de reversão | `411999e4d3e1468ce027b8eef5584fc85cfaf60b94a2a840f5d88f7d7800cf27` |

Uma divergência lança exceção dentro da transação. O `down` restaura byte a byte o corpo original; não remove recibos ou histórico. **Restaurar esse corpo também restaura sua limitação de cobertura.** A reversão não é um rollback operacional automático nem mantém a proteção acrescentada pela migration 12.

## Verificação

A revisão independente do produto e da migration não identificou bloqueadores. Os quatro pins foram recalculados, o corpo de reversão foi comparado com o original e a sintaxe PHP/diff check passou.

A prova PostgreSQL usa o laboratório descartável já existente, com identidade, diretório, cluster e TLS verificados antes das escritas. As migrations 11/12 entram antes dos 905 checks anteriores do registry. Um novo leitor sintético da empresa B reutiliza um binding válido criado pelo percurso existente, sem renovar atores revogados ou produzir outro artefato.

A mesma consulta funcionou normalmente e recusou cada desvio abaixo:

| Principal afetado | Desvio introduzido somente no laboratório |
| --- | --- |
| Login do leitor | SELECT na tabela `portal_project_intakes` |
| Login do leitor | SELECT na coluna `command_digest` |
| Definer de leitura | SELECT na coluna com grant option |
| Definer de escrita | INSERT na coluna |

Cada grant foi retirado em `finally`, seguido de uma consulta positiva e conferência de snapshots de dados/catálogo. O `down/up` controlado mostrou que o corpo antigo aceitava a chamada com a ACL direta e que o corpo novo a recusa; a reaplicação ocorre também no caminho de limpeza. O ensaio não apresenta esse resultado como vazamento de dados.

A fixture confirmou **13 verificações novas/918 registry**, mantendo as 905 anteriores. A revisão independente conferiu o ator B elegível, a reprodução do corpo anterior, os quatro negativos com SQLSTATE `42501` e motivo específico, retorno nulo da fonte PDO e recuperação sem mudanças de dados/catálogos. PHP lint, Bash e diff check passaram. YAML/Bash do workflow, matriz, onze opt-ins e restrições exatas de draft/base/head foram conferidos. PHP e runner recusaram uma cadeia de opt-ins incompleta antes de iniciar o bootstrap. O safety guard passou em cópia de 608 fontes, mantendo os dez gates BLOCKED.

A matriz dedicada usa PHP 8.3/8.4 e PostgreSQL 18. O workflow nativo F383 existente também exercitou o recebimento pelo serviço Laravel e a reversão completa das migrations em outro banco descartável. Não houve PostgreSQL local nesta candidata; a execução conectada ocorreu na CI.

### Evidência final da PR #515

O HEAD funcional `1732ad5b0d7b60067154c2a3354cd16b99a6658b` teve **14/14 check-runs SUCCESS** na conferência final.

| Execução | Jobs PHP 8.3 / 8.4 | Resultado |
| --- | --- | --- |
| [Registry 44307367368](https://github.com/rafhael100/imagine-sites/actions/runs/44307367368) | `134812301128` / `134812300748` | 13 verificações novas e 918 totais, com os dois corpos e quatro negativos reais; clusters inativos e nove auxiliares privados limpos. |
| [Schema nativo 44307366977](https://github.com/rafhael100/imagine-sites/actions/runs/44307366977) | `134812298794` / `134812299235` | 63 verificações de recebimento; recorder 18 testes/84 assertions; leitura/editorial anteriores; aplicação e rollback completo das 12 migrations. |
| [Laravel 44307367201](https://github.com/rafhael100/imagine-sites/actions/runs/44307367201) | `134812300330` / `134812300599` | Suíte padrão: 186 testes/1902 assertions/27 skips; opt-in sintético: 186/1914/21. |

A comparação real de `pg_proc` passou antes/depois da substituição, inclusive após a instalação do journal. As consultas e recusas conservaram os snapshots previstos. Os testes desta linha não incluem as alterações da linha de UI #511/#513/#514; os resultados próprios dessas branches estão identificados no STATUS, sem misturar totais.

## Fronteiras

A alteração não cria ou modifica grants, roles, tabelas, identidade de sessão ou autoridades de produto. A solicitação recebida continua pendente, o outbox bloqueado e publicação/aceite sujeitos às fontes próprias. A PR permanece draft; instalação, merge e operações reais continuam fora desta validação. Os dez gates operacionais permanecem BLOCKED.
