# Imagine Sites — busca na fila editorial

## Resultado para a equipe

O workspace permite localizar projetos pelo nome da marca ou pela chamada principal da versão atual. A busca funciona com os filtros de parecer e formato, e o resultado permanece paginado. Ao abrir um projeto e voltar, o usuário retorna ao mesmo contexto de busca e página.

Branch `feature/sites-queue-search-2026-11-18`, baseada na PR #492 / `ae3a9bd58c89d0c76b7d983c0206a9cad6bf80f8`. A base foi validada com 17/17 check-runs e 101 verificações HTTP; a evidência final da orientação editorial está registrada no relatório correspondente.

O campo **Buscar projeto** usa um formulário GET comum, sem JavaScript obrigatório. Alterar o termo ou os filtros inicia a primeira página. **Limpar busca** remove apenas o termo e o cursor, mantendo parecer/formato; **Limpar filtros** retorna à fila sem filtros. Quando nada corresponde, a tela informa o termo procurado como texto escapado.

O contexto também acompanha as páginas de histórico, o formulário GET de comparação, as URLs dos formulários de criação/edição/revisão e seus redirects. O termo não é acrescentado ao payload mutável do briefing ou do parecer.

## Semântica da busca

| Aspecto | Comportamento |
| --- | --- |
| Campos consultados | Marca ou chamada principal do briefing da versão atual |
| Correspondência | Substring literal, sem distinção de maiúsculas/minúsculas Unicode |
| Exemplos | `NAVEGAÇÃO` encontra `Navegação`; `STRASSE` encontra `Straße` |
| Pontuação | `%`, `_`, barra invertida e aspas são caracteres literais |
| Acentos | `cafe` não equivale a `café` |
| Composição Unicode | Não há normalização NFC/NFD nem busca aproximada |
| Espaços | White_Space Unicode nas pontas é aparado; espaços internos são preservados |
| Limite | Até 100 codepoints e 400 bytes, conferidos no valor bruto antes de aparar |
| Controles | C0, DEL/C1 e separadores U+2028/U+2029 são recusados |
| Termo vazio | Busca desativada, inclusive após o middleware converter campo vazio em null |
| Ordenação | A mesma ordem cronológica/cursor da fila existente; não há ranking de relevância |

O PostgreSQL 18 oferece `pg_catalog.casefold` com a collation incorporada `pg_catalog.pg_unicode_fast`, inclusive quando o banco usa locale C. O código usa esse tratamento em ambos os lados de `pg_catalog.strpos`, com dois parâmetros vinculados. Não usa interpolação, LIKE, regex, extensões ou collation criada pela aplicação. A correspondência é avaliada antes do LIMIT da página.

Referências técnicas: [PostgreSQL 18 — funções de texto](https://www.postgresql.org/docs/18/functions-string.html) e [collations padrão](https://www.postgresql.org/docs/18/collation.html). Case folding pode expandir caracteres, como `ß` para `ss`, e não promete preservar a forma normalizada.

## Autorização e compatibilidade

O parâmetro `q` é navegação não confiável. A empresa continua vindo do principal autenticado. A consulta mantém o filtro por empresa, FORCE RLS, checagens de acesso antes/depois da leitura, snapshot único e associação da revisão ao digest atual. Um cursor ou um termo de busca não identifica nem autoriza outra empresa.

Não há migration, alteração de schema, owner, role, grants ou estado editorial. Os três argumentos anteriores de `PreviewQueueQuery` continuam compatíveis; `search` é o quarto argumento opcional. A assinatura pública antiga de `projects()` mantém o mesmo formato.

O Laravel exclui `q` de `TrimStrings`, para que bytes inválidos e controles não desapareçam antes da validação do domínio. Somente `q=null` recebe o tratamento de busca vazia; arrays e tipos inválidos são recusados. A interface não usa um maxlength baseado em UTF-16 que impediria 100 emojis válidos. Valores e mensagens são escapados pelo Blade.

## Navegação e sessão

O ensaio de100 emojis revelou uma resposta de navegador sem o formulário esperado. Uma reprodução local da pilha web comprovou que o middleware padrão salva a URL anterior inteira no cookie de sessão cifrado e gera cabeçalhos maiores que4KiB com essa busca. O log inicial não registrou o status retornado pelo proxy; portanto, não se atribui a ele um502 observado.

`PrivatePreviewStartSession` remove o histórico `_previous` ao iniciar e salvar as requisições de `/_preview` e seus subcaminhos. Os links explícitos continuam transportando filtros/página, e o token CSRF e os demais dados da sessão permanecem. URLs antigas já armazenadas também são removidas antes do controller. As outras rotas conservam o comportamento Laravel, inclusive o resolvedor de cache usado no bloqueio de sessão.

A regressão usa cookies cifrados reais entre requisições separadas, reproduz cabeçalhos maiores que4KiB em uma rota comum e exige cabeçalhos abaixo desse limite no preview, com CSRF válido aceito e token ausente/incorreto recusado. Nenhum buffer do proxy foi ampliado. Referência: [Nginx — fastcgi_buffer_size](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_buffer_size).

## Verificação

Validação local executada após a correção da sessão: **122 testes PHPUnit, 854 asserções, 27 skips explícitos** dos cenários que exigem banco/opt-in. A regressão de sessão tem3 testes/66 asserções dentro desse total; suites offline de provisionamento26, diário99 e pacote50, sintaxe PHP/JavaScript, YAML e whitespace foram aprovados.

O workflow desta entrega é restrito ao draft, branch e SHA exato da base. Os ensaios usam apenas fixtures sintéticas e bancos descartáveis:

- PostgreSQL 18/PHP 8.3 e 8.4: Unicode em locale C, pontuação literal, marca/chamada, composição e acentos, head que substitui uma chamada antiga, filtros combinados, empate de timestamps, paginação além de 50 resultados, isolamento e revogação.
- HTTP autenticado Laravel/FPM/Nginx/TLS: formulário vazio, limite de 100 emojis, controles/UTF-8 inválidos, estado vazio escapado, navegação e retorno à página, criação/edição com contexto, comparação e histórico sem JavaScript.
- Capturas desktop/mobile do campo e do resultado; verificação de overflow.
- Regressões do workspace, orientação editorial, registry, pacote, diário e migrations Laravel.

Na HEAD `528efd1cb7cb5f4618f222fc663fbf0fbea27bea`, o [run44213463495](https://github.com/rafhael100/imagine-sites/actions/runs/44213463495) aprovou os contratos SQL em PHP8.3/8.4:33 verificações específicas de busca e356 totais do workspace. A regressão HTTP encontrou o problema de sessão descrito acima; a validação integral com a correção ainda está pendente. PostgreSQL local não foi iniciado. Resultados definitivos, HEAD e artefatos serão registrados na descrição da PR após a execução.

## Limites

Esta busca pertence ao workspace privado de demonstração. Não cria integração comercial, pagamento, aceite do cliente ou autorização de publicação. Nenhum merge, deploy ou alteração de VPS/18090, DNS, Cloudflare, credenciais ou dados reais faz parte da entrega.

A busca literal não cria índice de texto nem apresenta promessa de escala operacional. O volume e os planos de consulta precisam de medição própria antes de uma implantação comercial. Os dez gates operacionais e o bloqueio do rollback automático permanecem preservados.

