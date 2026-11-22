# OnePage R06 — negativa HTTP sem confundir a página de erro do Chromium (NO-GO)

**Preflight 22/11/2026**: #609, HEAD 3f90b7e015c28993d5ff49c6c0376eacc865a9c2, draft, R05 Chromium CI 45003002185/job 137148502235 FAILED. Três outros workflows dessa SHA SUCCESS. #608 é base histórica verde; #605 é outra trilha ativa e não foi tocada. Portal Landing #1087 e Agents OnePage #490 independentes.

## Defeito e correção restrita

O R05 navegava diretamente para `/private-onepage` quando não havia sessão. Essa rota devolve legitimamente **HTTP 403 com corpo vazio**. O Chromium pode apresentar um documento `chrome-error://` com texto não vazio em lugar desse documento. O R05 confundia o texto da página substituta com uma falha de isolamento: marcador observado `R05_FAIL DENY_GUEST R05_BROWSER_ASSERTION_REFUSED CHROME_BLOCKED`. Não há prova de que o servidor autorizou o pedido, nem de política de rede bloqueando todas as rotas.

R06 adiciona `/fixture-guest` (HTTP 200, sem cookie, sem permissão, nenhum recibo) e usa **fetch real dentro do Chromium** para verificar que `/private-onepage` e `/review` respondem 403 **com zero bytes**. Se o navegador não puder acessar o loopback ou se alguma rota negar incorretamente, o teste continua falhando. Nenhum parâmetro de segurança do Chromium foi relaxado e nenhuma proteção do Sites foi mudada. O restante da R05 continua exigindo clique real via CDP, SHA dos bytes, 403 de outro comprador, veto a versão obsoleta, duas aceitações sintéticas exatas e zero terceiro efeito.

## Escopo / segurança

Branch filha isolada de #609. Arquivos: dois scripts do teste R05, novo workflow R06, esta nota. Toda identidade, sessão, HTML e recibo são fictícios e locais. Sem contato com Portal real, OpenAI, Replit, VPS, DNS, Asaas, Cloudflare ou produção. Sem merge/deploy, sem alteração de main, sem credenciais. Não há login operacional, aceite legal, prévia pública, release real ou publicação. Não herdar evidência para Landing Page.

**Gate**: R06 só pode ser considerada aprovada quando seu workflow na HEAD exata estiver SUCCESS. Sem sucesso, registrar primeira fase falha e preservar NO-GO de Sites #38. A origem oficial do briefing, sessão e aceite autenticados, além da rota privada do worker OnePage, continuam dependências distintas.

## Nova falha observada e correção restrita

Na primeira execução R06, HEAD `f2741614b54646001b0312150cd381c1c54434c3`, [run 45007011468](https://github.com/rafhael100/imagine-sites/actions/runs/45007011468) (dedicado) falhou **depois** do fluxo de clique A/B, na fase `DENY_OTHER`, porque `/fixture-other` redirecionava para `/review` 403 vazio e o Chromium novamente substituía o documento por `chrome-error://`. Marcador exato `DENY_OTHER_R05_BROWSER_EVALUATION_REFUSED_CHROME_BLOCKED`. Sintaxe e os três workflows gerais ficaram verdes naquela SHA; o teste dedicado foi vermelho.

Correção: `fixture-other` ainda emite o cookie de **outro comprador fictício**, mas redireciona à página neutra 200 sem autoridade (`fixture-guest`). No mesmo navegador, as requisições reais a `/private-onepage` **e** `/review` devem retornar 403 e zero bytes para essa identidade distinta. Não muda decisões de backend, não concede sessão do comprador responsável, não desliga segurança do Chrome, não finge sucesso. Revalidar CI na HEAD posterior, sem reutilizar o verde da SHA anterior.
