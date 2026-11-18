# Imagine Sites — candidata unificada do workspace e recebimento nativo

## Objetivo

As melhorias de interface e o recebimento persistente do Portal avançaram em duas linhas a partir da #510. A candidata `integration/sites-workspace-native-intake-2026-11-18` reúne esse código num único ponto de revisão e valida sua coexistência.

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

A matriz preparada separa os laboratórios e conserva suas identidades e gates:

| Execução | Prova prevista |
| --- | --- |
| Workspace PostgreSQL 18, PHP 8.3/8.4 | 429 verificações, incluindo pareceres paginados, isolamento, revogação e snapshots de leitura/escrita. |
| Registry PostgreSQL 18/TLS, PHP 8.3/8.4 | 918 verificações, incluindo migration 12, reprodução do corpo anterior e quatro ACLs indevidas restauradas. |
| Navegador HTTPS real | 439 verificações com FPM/Nginx, formulários nativos, CSRF, contexto sem JavaScript, desktop/mobile e limpeza própria. |
| Schema nativo F383 existente | Recebimento persistente, 63 verificações do intake, testes do recorder e rollback completo das 12 migrations. |
| Laravel geral existente | Suíte completa do conjunto em PHP 8.3/8.4, nos modos padrão e sintético previstos. |

A inclusão de `apps/sites/bootstrap/app.php` no filtro do workflow F383 mantém a comprovação nativa quando o bootstrap da aplicação compartilhada muda. Não há cenário, seed ou total artificialmente acrescentado apenas para a consolidação.

**A execução conectada da candidata ainda aguarda CI.** Os resultados das PRs de origem constam no STATUS por SHA; não são apresentados como se já tivessem sido executados nesta composição.

## Integração com o Portal

A Portal #941 já possui uma prova pareada usando o recorder real da Sites #512. A alteração do guard e a interface não transformam o recibo pendente em autorização comercial. A referência do teste pareado continua sendo a definida naquela linha; a presente candidata será verificada pelas suítes Sites acima.

Os **24 arquivos Sites pinados pela prova pareada** foram conferidos por hash na candidata e continuam idênticos à origem #512. Essa conferência preserva a rastreabilidade do código consumido pelo teste do Portal; não equivale a uma nova execução pareada sobre o HEAD integrado.

O estado atualizado da fonte nativa, do teste pareado e do briefing permanece descrito em [Próxima integração](SITES-NEXT-INTEGRATION-2026-11-18.md). Os metadados de pedido e o recibo de criação não contêm o corpo versionado do briefing de produção.

## Fronteiras

A composição preserva a separação entre os dados demonstrativos de `sites_preview` e os registros nativos de `sites_core`. Não há promoção automática de demonstração para pedido comercial, envio entre servidores ou início de geração a partir de uma fonte de briefing presumida. A candidata permanece uma PR draft; as branches anteriores e suas evidências continuam disponíveis. Os dez gates operacionais permanecem BLOCKED.
