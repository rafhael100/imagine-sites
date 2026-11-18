# Imagine Sites — consulta completa dos pareceres por versão

## Problema demonstrado

O histórico reúne até 20 versões e 100 pareceres. Esse limite de pareceres era global na janela, enquanto a paginação considerava somente o número das versões. Com 20 versões e seis pareceres em cada uma, todos os cartões cabiam na página, mas parte das orientações antigas ficava omitida e não havia uma próxima página que permitisse consultá-la. A orientação da versão atual também tinha um teto de 100 registros.

A branch `feature/sites-review-history-pagination-2026-11-18` parte da PR #510, em `61a8d1db212d298051e6f64f8b923e545a4c6bb3`. Corrige esse acesso sem ampliar indefinidamente o resumo ou modificar o histórico persistido.

## Consulta por versão

Cada cartão oferece **Consultar pareceres da versão N**. As orientações atuais também oferecem acesso à versão atual, mesmo quando ela está fora da janela do histórico. O link abre uma seção própria, com o número da versão, seu digest, decisões, datas e orientações. Ela contém até 20 pareceres, do mais recente para o mais antigo, e permite seguir para os anteriores ou retornar ao início.

O briefing e a revisão esperada de edição continuam associados ao head do projeto. Consultar um parecer antigo não muda o alvo do formulário, registra uma revisão ou copia sua nota para uma tentativa de edição. As observações legadas nulas continuam identificadas, sem inventar texto; as demais orientações conservam seus bytes e são escapadas na apresentação.

| Parâmetro GET | Regra |
| --- | --- |
| `review_revision` | Inteiro decimal canônico entre 1 e 2147483647, em uma página de projeto |
| `review_cursor` | Opcional, hexadecimal minúsculo de 64 caracteres, somente junto à revisão |

Os dois seletores novos não passam pelo trim do framework: espaços e NUL nas bordas devem chegar à validação e ser recusados. As exceções anteriores para nota e busca permanecem.

Os links conservam busca, filtros, cursor da fila, janela de versões e comparação aberta. Trocar a versão consultada remove apenas o cursor de pareceres. Fechar a consulta remove somente seus dois parâmetros; fechar a comparação preserva a consulta de pareceres. O formulário GET de comparação conserva o contexto pelos campos ocultos necessários ao envio nativo do navegador.

Os seletores são exclusivamente de leitura. Os URLs dos formulários POST e os redirecionamentos de escrita não os transportam; recebê-los na query de um POST não muda seu alvo nem cria um pré-requisito. Campos extras no corpo do POST continuam recusados.

## Ordem, cursor e autoridade

A consulta usa `PrivatePreviewHistory::reviewsForVersion` e `PreviewReviewQuery`. A versão selecionada e seus pareceres são lidos em uma única SELECT, com empresa, projeto e revisão vinculados explicitamente e sob as regras RLS existentes. Runtime e acesso de leitura são conferidos antes e depois, além da conferência do principal antes de renderizar a resposta.

A ordenação usa `created_at DESC` e o login interno do revisor em ordem de bytes como desempate. A chave primária existente impede duplicação do mesmo revisor na mesma versão. O cursor público contém somente um hash de domínio e identidade da linha, incluindo o escopo e timestamp em UTC; não serializa o login. Esse hash não é uma credencial ou prova de autoridade.

Para continuar, a mesma consulta resolve exatamente uma âncora dentro da versão autorizada e usa os valores internos dela para obter os registros seguintes. Uma 21ª linha serve apenas como sentinela para detectar continuação. A projeção final remove o cursor por linha e o revisor; expõe somente os campos editoriais já apresentados e o próximo cursor quando necessário.

Não há OFFSET. A chegada de um parecer mais recente após a primeira página não desloca a continuação para repetir um registro já lido. Cada GET é uma observação atual; a sequência inteira não é um snapshot congelado. Registros mais novos que a âncora podem ser encontrados ao voltar ao início. Não há total geral ou numeração de página que sugira uma contagem congelada.

| Situação | Resultado |
| --- | --- |
| Versão própria existente sem pareceres | HTTP 200 e mensagem de ausência de parecer editorial |
| Âncora válida sem registros posteriores | HTTP 200, mensagem própria e retorno aos mais recentes |
| Versão ausente, de outro projeto ou outra empresa | HTTP 404, sem projetar conteúdo privado |
| Cursor bem formado ausente ou pertencente a outro escopo | HTTP 404, sem apresentá-lo como uma lista vazia |
| Seletor malformado, array, valor não canônico ou cursor sem revisão | HTTP 422 |
| Leitor revogado ou sem autorização corrente | Recusa do acesso atual |

## Verificação

A suíte local completa passou com **188 testes, 2052 assertions e 27 skips explícitos**, usando `--do-not-cache-result`. Os 7 testes/72 assertions do seletor e das recusas do reader não simulam uma consulta SQL positiva. O conjunto de navegação passou com 17 testes/221 assertions; as views afetadas passaram com 46 testes/535 assertions, incluindo 7 testes/94 assertions novos. A leitura independente de Navigation/controller e a revisão do reader, DTO e apresentação não identificaram bloqueadores. YAML/Bash, matriz e pins do workflow foram conferidos. Após a exceção de trim dos seletores, os testes focados de middleware/entrada/navegação passaram com 39 testes/466 assertions.

A fixture SQL implementada e revisada independentemente contém **25 verificações novas/429 totais previstas**, preservando as 404 anteriores. O preparo cria 20 versões reais com seis pareceres por API em cada uma. Acrescenta 15 linhas de ordenação explicitamente sintéticas na versão 1 para produzir um empate de timestamp atravessando a fronteira entre as páginas; usa os próprios logins fictícios, RLS e auditoria ativos, com INSERT temporário retirado em `finally` e ACL conferida antes de qualquer consulta do produto. Esse preparo não é apresentado como prova de escrita pela API. Nenhum principal novo entra no manifesto HTTP.

A primeira versão possui 21 pareceres, inclusive um autor histórico revogado. Depois da primeira página, um novo parecer é registrado pela API e uma versão 21 é gerada; cada ação deve acrescentar somente sua linha e auditoria. A continuação antiga permanece estável, enquanto retornar ao início alcança os 22 pareceres atuais. A fixture também verifica mudança de TimeZone, notas Unicode e nulas, projeção sem login, estados vazios, cursores alheios, empresa B legível antes da recusa cruzada, revogação do leitor e snapshots integrais das cinco tabelas.

A jornada HTTP implementada contém **39 checks novos/433 totais previstos**, sobre os 394 confirmados na base. Usa links e formulários reais com JavaScript desativado, incluindo o retorno ao início depois do novo parecer, continuação 20+2, seletores fora do trim e capturas do painel em desktop/mobile. Os POSTs usam CSRF e digest reais; os GETs e as recusas preservam snapshots completos. Node syntax e revisão do percurso passaram; a revisão independente final confere os deltas e os hooks. **SQL e navegador novos aguardam CI; nenhum resultado conectado é inferido da validação local.**

O workflow dedicado usa a branch e o SHA exatos da base, PR draft no mesmo repositório, PHP 8.3/8.4 e PostgreSQL 18 descartável. A jornada de navegador usa HTTPS, sessão nominal e JavaScript desativado, com dados inteiramente sintéticos. Nenhuma instalação em staging faz parte dessa validação.

## Fronteiras preservadas

Não há nova tabela, migration, política, grant ou ação de escrita no produto. A consulta não transforma aprovação interna em aceite do cliente, altera `demonstration_only`, habilita publicação ou fornece um briefing oficial do Portal. A PR permanece draft, e os dez gates operacionais continuam BLOCKED.
