# Imagine Sites — consulta exata da revisão editorial corrente

## Necessidade e recorte

A CLI de transição editorial pode perder a resposta depois de uma revisão já ter sido gravada. Seu manual exige apurar o estado antes de uma nova decisão. A leitura anterior de projetos retorna apenas os 100 mais recentes e não inclui o digest editorial, portanto não atende essa consulta para qualquer site conhecido.

A branch `feature/sites-editorial-inspection-2026-11-18` parte da PR #506 / `23f52d8ef6c542109f6271f33d06b218fbcd5d1a`. Ela acrescenta uma leitura exata por UUID conhecido, usando o vínculo de leitura da sessão SQL. A empresa não vem do manifesto. A listagem existente e a API de transição conservam seus contratos.

## Contrato de leitura

`sites_core.read_my_site_editorial_head(uuid)` devolve uma única linha para um projeto autorizado. O projeto sem histórico editorial tem revisão, estado e digest nulos em conjunto. Site inexistente e site de outra empresa não produzem uma linha; a CLI apresenta a mesma recusa limitada para esses casos e para falta de autorização.

A função usa o `sites_core_read_definer` já existente, sem LOGIN, herança ou privilégios administrativos. Confere a identidade do leitor, sua capacidade exclusiva, os privilégios diretos, a propriedade dos objetos e as políticas/FORCE RLS das três tabelas existentes. O SELECT do projeto e de sua última revisão forma uma observação coerente. Antes de liberar a linha, outra consulta exige novamente o mesmo vínculo ativo, com leitura e empresa correspondentes.

O corpo contém somente SELECT, mas a função é `VOLATILE` para obter uma observação nova nessa rechecagem sob `READ COMMITTED`. A [documentação do PostgreSQL 18](https://www.postgresql.org/docs/18/xfunc-volatility.html) explica que funções `STABLE` reutilizam o snapshot da consulta chamadora, enquanto `VOLATILE` obtém um novo a cada consulta interna. Isso não cria bloqueio, reserva de revisão ou garantia contra uma revogação posterior à última observação.

A migration `2026_11_18_000009_add_editorial_head_read_api.php` valida a fundação existente, cria somente a função, fixa seu proprietário e revoga EXECUTE de PUBLIC. Não cria tabela, coluna, política, role, vínculo ou grant para LOGIN. O `down` verifica a definição/hash/configuração esperados e remove somente essa função com a restrição padrão de dependências; não usa CASCADE. A concessão ao leitor é uma operação de provisionamento separada.

## Serviço e comando privado

`PrivateEditorialInspection` usa PDO diretamente e não inicializa Laravel. O manifesto contém exatamente `schema`, `target` e `siteRef`; o alvo tem os sete campos usados pelos candidatos privados. Chaves repetidas, campos extras, coerção de inteiros e coordenadas impróprias são recusados. O serviço fixa o hash do corpo SQL esperado e verifica o runtime antes e depois da consulta: PostgreSQL 18, TLS, banco/OID/login/endereço/porta, sessão somente leitura, `pg_catalog`, isolamento e ausência de transação herdada.

`ops/editorial-candidate/inspect.php inspect` lê os três mounts privados fixos descritos no [manual](../../ops/editorial-candidate/README.md). A sessão usa TLS `verify-full`, CA conferida, conexão não persistente e limites de tempo. Os bytes e a identidade dos três arquivos são relidos após a consulta; uma mudança observada impede liberar o resultado. `--help` funciona antes do autoload. Não há plano, flag de escrita, caminho de saída ou criação de arquivo.

O sucesso público contém exatamente sete campos:

| Campo | Significado |
| --- | --- |
| `schema` | `SITES_EDITORIAL_INSPECTION_RESULT_V1` |
| `status` | `inspected` |
| `revision` | Revisão positiva corrente, ou `null` sem histórico |
| `state` | Estado editorial corrente, ou `null` sem histórico |
| `artifactDigest` | Digest registrado nessa revisão, ou `null` sem histórico |
| `clientAccepted` | Sempre `false` |
| `canPublish` | Sempre `false` |

O serviço valida internamente a identidade do site e a projeção SQL de seis campos, sem expor IDs, empresa, ator, credenciais, referências comerciais, HTML ou diagnóstico privado. Falha produz somente `EDITORIAL_INSPECTION_DENIED`, sem cadeia de exceção.

## Interpretação depois de uma resposta perdida

A consulta informa o head atual, mas a tabela editorial não registra ação, ator ou identificador da chamada que originou cada revisão. Igualdade de revisão/estado/digest não atribui uma escrita a uma tentativa específica; uma revisão posterior pode incluir trabalho de outro operador. A leitura não autoriza retry nem incremento automático da revisão esperada. Uma nova transição continua exigindo decisão explícita, plano revisto e validação SQL de sua revisão corrente.

O digest observado também não comprova presença do HTML no registry, associação corrente de uma release, pagamento, aceite do cliente ou publicação. A consulta não transforma revisão interna em autoridade comercial.

## Verificação

Os testes locais do serviço passaram em **166 assertions**, cobrindo entradas, hash da definição e recusas padrão sem simular autorização SQL positiva. A CLI passou em **45 checks** de help e recusas limitadas, com autoload e materiais privados inacessíveis. A suíte completa da aplicação também passou em **152 testes/1624 assertions/27 skips**. Sintaxe, revisão cruzada do serviço/comando, configuração do workflow e safety guard de fontes foram aprovados.

O workflow dedicado fixa draft, repositório, branch e SHA da base, com PHP 8.3/8.4. O nono opt-in da fixture acrescenta a consulta editorial à cadeia existente e exige seu marcador de sucesso. O ensaio PostgreSQL/TLS prevê leitura com e sem head, site fora da listagem dos 100, isolamento, revogação, privilégios indevidos, consulta após resposta descartada, ausência de mutação e migração reversível. O caso concorrente observa a chamada bloqueada em uma relação, revoga o leitor e exige recusa após liberar o bloqueio. O guard de catálogo pode bloquear antes do primeiro SELECT do vínculo: esse ensaio não afirma que alcançou a rechecagem final. A identidade da espera e seu limite de tempo evitam aceitar um simples timeout como prova da revogação. A fixture e sua revisão independente estão concluídas, com **53 checks novos/850 registry previstos**, preservando os 797 anteriores e o contrato separado de 386 checks do workspace. **Esses totais SQL ainda estão pendentes de execução no CI.** O ambiente local não executou PostgreSQL ou Docker.

## Limites preservados

Esta candidata não instala a função nem provisiona leitores no staging. Não altera APIs públicas, revisão, registry, diário ou fonte oficial do briefing. O workspace permanece `demonstration_only`, os dez gates permanecem `BLOCKED`, e o Portal conserva contrato, pagamento, aceite expresso e `ENTREGUE`.
