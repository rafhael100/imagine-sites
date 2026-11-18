# Imagine Sites — candidata unificada do workspace e recebimento nativo

## Objetivo

As melhorias de interface e o recebimento persistente do Portal avançaram em duas linhas a partir da #510. A [PR #516](https://github.com/rafhael100/imagine-sites/pull/516), branch `integration/sites-workspace-native-intake-2026-11-18`, reúne esse código num único ponto de revisão e valida sua coexistência. O HEAD funcional é `a92b365a789b3d09638feb95becc5e2dc1d384ca`.

A base é a [#515](https://github.com/rafhael100/imagine-sites/pull/515), HEAD documental `a260f9be5dbe11208e287386890169ff9c0fd1cb`, que já inclui o recebimento nativo da #512 e a correção de permissões do registry. O delta de interface foi extraído da [#514](https://github.com/rafhael100/imagine-sites/pull/514), HEAD `c5df3ee02f040028cd2655b963c7a7dced07ab7c`, contra a base comum `61a8d1db212d298051e6f64f8b923e545a4c6bb3`.

## Código incorporado

| Frente | Comportamento reunido |
| --- | --- |
| Recebimento nativo #512 | Solicitação de projeto, referências, outbox bloqueado e recibo estável em uma transação; replay e isolamento preservados. |
| Permissões #515 | A inspeção do registry cobre todas as tabelas comuns/particionadas de `sites_core`, inclusive o ledger novo; migration 12 preserva identidade e ACL da função. |
| Pareceres #511 | Consulta própria por versão, vinte pareceres por página e continuação por âncora, com busca, fila, histórico e comparação preservados. |
| Revisão editorial #513 | Formulários, campos, botões e estados de erro identificam sua versão exata. |
| Histórico #514 | Links identificam a prévia e o par comparado; as legendas respeitam a capacidade efetiva de edição. |

Foram adotados 29 paths da linha de interface. Os **26 arquivos de aplicação, código-fonte, testes e fixtures** foram comparados independentemente com os blobs da #514 e são idênticos byte a byte. Os outros três são relatórios de origem. Não houve conflito de código.

README e STATUS foram reconciliados com as duas linhas. Os três workflows antigos da sequência de UI, restritos às respectivas branches de origem, não foram copiados. Um workflow da candidata executa as suítes já existentes sobre a composição atual.

## Verificação do conjunto

A suíte PHPUnit completa local foi executada uma vez, com PHP 8.4.27, PHPUnit 12.5.43 e `--do-not-cache-result`: **206 testes, 2174 assertions e 27 skips explícitos, sem falhas**. Não foram instaladas dependências novas.

A revisão independente da composição confirmou a separação das conexões: o preview abre seu PDO nominal e verifica `sites_preview.can_access`; o recorder recebe uma conexão Laravel explícita e atua em `sites_core`. O ajuste de bootstrap preserva os seletores de leitura até sua validação estrita, sem alterar sessões, CSRF, rotas ou a lista de campos aceitos em POST. Não foi identificado bloqueador de composição.

Os dois workflows passaram por leitura de YAML, verificação dos guards e sintaxe dos doze blocos de shell. O workflow novo exige o draft, a origem no mesmo repositório, as branches exatas e o SHA da base; faz checkout do HEAD sem persistir credenciais. O scan de segurança sobre **622 arquivos de fonte**, sem `vendor`, passou e confirmou os dez gates BLOCKED.

A matriz separa os laboratórios e conserva suas identidades e gates. Resultados lidos nos logs do HEAD funcional:

| Execução | Prova confirmada |
| --- | --- |
| Workspace PostgreSQL 18, PHP 8.3/8.4 | **429 verificações**, incluindo 25 de pareceres paginados; isolamento, revogação e snapshots de leitura/escrita preservados. |
| Registry PostgreSQL 18/TLS, PHP 8.3/8.4 | **918 verificações**, incluindo 13 da cobertura de ACL; migration 12, reprodução do corpo anterior e quatro permissões indevidas restauradas. |
| Navegador HTTPS real | **439 verificações**, incluindo 39 de paginação e 6 de identificação acessível por versão; FPM/Nginx, formulários nativos, CSRF, contexto sem JavaScript e desktop/mobile. |
| Schema nativo F383, PHP 8.3/8.4 | **63 verificações do intake**, recorder **18 testes/84 assertions**, reader 3/12, editorial 2/12; rollback das **12 migrations** com schema ausente ao final. |
| Laravel geral, PHP 8.3/8.4 | Padrão: **206 testes/2174 assertions/27 skips**. Opt-in sintético: **206/2186/21**. Sem falhas. |

O [workflow integrado 44311588248](https://github.com/rafhael100/imagine-sites/actions/runs/44311588248) passou nos jobs SQL `134826819495` (PHP 8.3) e `134826820070` (PHP 8.4), com marcadores de inatividade dos dois clusters. O navegador passou no job `134826820039`, com remoção dos containers, redes e materiais próprios.

O [workflow nativo 44311587726](https://github.com/rafhael100/imagine-sites/actions/runs/44311587726) passou nos jobs `134826816208` e `134826816549`. A [matriz Laravel 44311587816](https://github.com/rafhael100/imagine-sites/actions/runs/44311587816) passou nos jobs `134826817539` e `134826817056`. Os ensaios existentes de [ingresso HTTP assinado 44311587926](https://github.com/rafhael100/imagine-sites/actions/runs/44311587926) e [containers locais 44311587778](https://github.com/rafhael100/imagine-sites/actions/runs/44311587778) também passaram sobre esta composição, com remoção de seus recursos descartáveis.

A inclusão de `apps/sites/bootstrap/app.php` no filtro do workflow F383 mantém a comprovação nativa quando o bootstrap da aplicação compartilhada muda. Não há cenário, seed ou total artificialmente acrescentado apenas para a consolidação.

**17/17 check-runs SUCCESS** no HEAD funcional `a92b365a789b3d09638feb95becc5e2dc1d384ca`. Os resultados das PRs de origem constam no STATUS por SHA; não são usados como substituto dos resultados novos acima.

O artifact `14430817072`, `sites-workspace-native-intake-preview-http`, contém 31 arquivos, tem 5307544 bytes e SHA-256 `81e812da3346e7f6c4c0e5ac5a6068585736ba412d25924c725be0a57f0b813d`. Tamanho, digest e caminhos do ZIP foram verificados antes da extração. As capturas do workspace desktop **1440×2413** e mobile **390×4377** foram revisadas visualmente: estrutura legível, links de versão explícitos, digests com quebra de linha e controles contidos na largura disponível.

## Integração com o Portal

A Portal #941 já possui uma prova pareada usando o recorder real da Sites #512. A alteração do guard e a interface não transformam o recibo pendente em autorização comercial. A referência do teste pareado continua sendo a definida naquela linha; a presente candidata será verificada pelas suítes Sites acima.

Os **24 arquivos Sites pinados pela prova pareada** foram conferidos por hash na candidata e continuam idênticos à origem #512. Essa conferência preserva a rastreabilidade do código consumido pelo teste do Portal; não equivale a uma nova execução pareada sobre o HEAD integrado.

O estado atualizado da fonte nativa, do teste pareado e do briefing permanece descrito em [Próxima integração](SITES-NEXT-INTEGRATION-2026-11-18.md). Os metadados de pedido e o recibo de criação não contêm o corpo versionado do briefing de produção.

## Fronteiras

A composição preserva a separação entre os dados demonstrativos de `sites_preview` e os registros nativos de `sites_core`. Não há promoção automática de demonstração para pedido comercial, envio entre servidores ou início de geração a partir de uma fonte de briefing presumida. A candidata permanece uma PR draft; as branches anteriores e suas evidências continuam disponíveis. Os dez gates operacionais permanecem BLOCKED.
