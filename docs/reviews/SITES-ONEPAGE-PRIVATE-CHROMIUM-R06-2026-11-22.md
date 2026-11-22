# OnePage R06 — negativa HTTP sem confundir a página de erro do Chromium (NO-GO)

**Preflight 22/11/2026**: #609, HEAD 3f90b7e015c28993d5ff49c6c0376eacc865a9c2, draft, R05 Chromium CI 45003002185/job 137148502235 FAILED. Três outros workflows dessa SHA SUCCESS. #608 é base histórica verde; #605 é outra trilha ativa e não foi tocada. Portal Landing #1087 e Agents OnePage #490 independentes.

## Defeito e correção restrita

O R05 navegava diretamente para `/private-onepage` quando não havia sessão. Essa rota devolve legitimamente **HTTP 403 com corpo vazio**. O Chromium pode apresentar um documento `chrome-error://` com texto não vazio em lugar desse documento. O R05 confundia o texto da página substituta com uma falha de isolamento: marcador observado `R05_FAIL DENY_GUEST R05_BROWSER_ASSERTION_REFUSED CHROME_BLOCKED`. Não há prova de que o servidor autorizou o pedido, nem de política de rede bloqueando todas as rotas.

R06 adiciona `/fixture-guest` (HTTP 200, sem cookie, sem permissão, nenhum recibo) e usa **fetch real dentro do Chromium** para verificar que `/private-onepage` e `/review` respondem 403 **com zero bytes**. Se o navegador não puder acessar o loopback ou se alguma rota negar incorretamente, o teste continua falhando. Nenhum parâmetro de segurança do Chromium foi relaxado e nenhuma proteção do Sites foi mudada. O restante da R05 continua exigindo clique real via CDP, SHA dos bytes, 403 de outro comprador, veto a versão obsoleta, duas aceitações sintéticas exatas e zero terceiro efeito.

## Escopo / segurança

Branch filha isolada de #609. Arquivos: dois scripts do teste R05, novo workflow R06, esta nota. Toda identidade, sessão, HTML e recibo são fictícios e locais. Sem contato com Portal real, OpenAI, Replit, VPS, DNS, Asaas, Cloudflare ou produção. Sem merge/deploy, sem alteração de main, sem credenciais. Não há login operacional, aceite legal, prévia pública, release real ou publicação. Não herdar evidência para Landing Page.

**Gate**: R06 só pode ser considerada aprovada quando seu workflow na HEAD exata estiver SUCCESS. Sem sucesso, registrar primeira fase falha e preservar NO-GO de Sites #38. A origem oficial do briefing, sessão e aceite autenticados, além da rota privada do worker OnePage, continuam dependências distintas.
