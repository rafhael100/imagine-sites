# Imagine Sites — identificar os destinos do histórico e o modo de consulta

## Problemas demonstrados

O workspace mostrava o mesmo nome em links que abriam versões diferentes: “Visualizar prévia privada ↗” e “Comparar com a anterior”. Os destinos estavam corretos, mas uma lista de links ou navegação por nome não identificava a versão ou o par escolhido.

Além disso, a comparação e o aviso do histórico descreviam um formulário de edição mesmo quando o usuário possuía apenas leitura ou revisão. Nessa resposta não existia formulário de edição. Os casos existentes de apresentação já renderizavam ambos os acessos e exigiam a mesma legenda.

A branch `fix/sites-history-link-context-2026-11-18` parte da PR #513, HEAD `a21781e1c1143392fbe37239a0d6ecb3938c0339`.

## Comportamento

Cada cartão identifica o destino no texto visível do link:

- **Visualizar prévia privada da versão N ↗** mantém a abertura em nova aba, seu `href` e `rel="noopener"`.
- **Comparar versão N com a versão N-1** identifica o par real e mantém os seletores e o contexto de navegação.

A primeira versão continua sem link para uma anterior inexistente. A alteração não troca a ordem da comparação nem o alvo da prévia.

As legendas distinguem a capacidade efetiva de acrescentar uma versão. Quando o formulário está disponível, descrevem a edição da versão atual. Nos acessos somente para leitura/revisão, descrevem a consulta do briefing salvo. Os ramos de tentativa não salva e de limite numérico conservam suas mensagens e precedência.

## Verificação

São reutilizados os casos de apresentação que já cobrem múltiplos cartões, URLs com contexto, leitura/edição, tentativa não salva e limite. O conjunto focado passou com **43 testes e 520 assertions**, usando `--do-not-cache-result`. Não foi criado um novo cenário para repetir a implementação dos rótulos.

A jornada HTTP também reutiliza suas verificações: a releitura da primeira prévia resolve o link pelo nome exato entre duas versões; a comparação inicial abre o par 1/2 pelo link do cartão. O formulário GET continua exercitado pela comparação histórica 1/60. Os demais percursos e o estado final foram preservados, com **439 verificações confirmadas**, sem checks ou seeds adicionais.

O workflow dedicado executa o navegador real e a matriz geral existente cobre PHP 8.3/8.4. As capturas de workspace já produzidas pelo percurso permitem conferir os links em desktop e celular. A revisão do produto e do percurso HTTP não identificou bloqueadores; Node syntax, diff check, YAML/Bash, restrições exatas de draft/base/head e safety guard de 610 fontes passaram.

### Evidência final da PR #514

O HEAD `c5df3ee02f040028cd2655b963c7a7dced07ab7c` teve **11/11 check-runs SUCCESS** na conferência final. O [run 44304778713](https://github.com/rafhael100/imagine-sites/actions/runs/44304778713), job `134803383204`, confirmou os 439 checks HTTP e a remoção dos containers e redes descartáveis. A [matriz Laravel 44304778161](https://github.com/rafhael100/imagine-sites/actions/runs/44304778161) passou em PHP 8.3/8.4, jobs `134803379213` e `134803378576`: suíte padrão 188 testes/2090 assertions/27 skips e opt-in sintético 188/2102/21.

O artifact `14428565756` teve seus 31 arquivos, 5369089 bytes e SHA-256 `494b756e9dd3e89e78062e45b045bf8eb25b020fadced6b8d211d0c1d898bacd` conferidos. As capturas `workspace-desktop.png` (1440×2413) e `workspace-mobile.png` (390×4377) foram revisadas visualmente: os destinos ficam explícitos e os rótulos longos quebram a linha dentro dos cartões, sem corte horizontal.

## Limites

Mudança de apresentação e navegação acessível sobre os mesmos destinos. Não há alteração de autorização, banco, sessão, payload ou estado comercial. A candidata permanece em draft, o workspace demonstrativo e os dez gates operacionais BLOCKED.
