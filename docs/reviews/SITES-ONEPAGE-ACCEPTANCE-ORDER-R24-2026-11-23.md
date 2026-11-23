# Imagine Sites OnePage R24 — releitura estrita sem dependência da ordem do mapa (CI-only, NO-GO)

## Preflight e isolamento (23/11/2026)

Verificados PRs/branches/HEADs, atividade e CI exata nos três repositórios antes da criação: Sites [#627](https://github.com/rafhael100/imagine-sites/pull/627), SHA d3b0509ca8bcef82d2d3f240c994d0fdea51e362, quatro workflows SUCCESS; Portal [#1097](https://github.com/rafhael100/imagine-portal/pull/1097), SHA b7a4510f544010f72e83ca27e0c7dafa2c388b27, seis SUCCESS; AI Agents [#490](https://github.com/rafhael100/imagine-ai-agents/pull/490), apenas precedente de Responses simulado. Ramo R24 exclusivo, filho da #627. As Sites #541/#542 são históricas. Nenhum escritor Landing foi tocado.

## Falha reproduzida e correção

OnepageExactFinalAcceptanceReadCandidate valida por nome/tipo/valor as três fontes associativas independentes (currentRelease, currentAcceptance, currentPresentation), mas comparava o segundo resultado ao primeiro com !==. Em PHP, isso rejeita um mapa semanticamente idêntico cuja ordem de inserção mudou durante leitura de adaptadores.

Teste primeiro, no mesmo pedido/revisão/HTML fictícios da R04/R23: tests/onepage-acceptance-associative-order-r24.php, sem novo writer. A SHA db81e77606533a37410d4810ce91dbb158d8b0c2 falhou em PHP 8.3 e 8.4 na primeira permutação da currentRelease: [run 45162997200](https://github.com/rafhael100/imagine-sites/actions/runs/45162997200), job PHP 8.4 137652826295 e job 8.3 137652826745 FAILURE. Não é falha de autenticação real ou de fonte oficial.

A correção substitui comparação de ordem por igualdade estrita de **conjunto de nomes, tipos e valores** somente em mapas já validados: nenhum campo opcional, conversão numérica, normalização de HTML/bytes, SHA, revisão, época ou identidade. Identificou-se também que uma troca efetiva de release/recibo após a primeira observação ainda permitia consultar a segunda apresentação privada antes da negativa final. O mesmo teste exige recusa antecipada: a segunda leitura HTML somente ocorre após revalidar ambas as referências. Nenhum checkout, autorização operacional ou publicação é inferido da leitura.

## Limites e próxima dependência

Fonte oficial e imutável do briefing, confirmação de direitos pelo comprador e aprovação/revogação transacional autenticada do revisor **ainda não comprovadas** no Laravel/PG18 do Portal #976. O código permanece uma candidata CI-only, não prova navegador/login nativos, CAS, aceite de cliente real ou publicação. O esquema do agente permanece sites.ai-copy.v1 (somente headline, description, callToAction e benefits) e não há invocação OpenAI, Replit ou credencial. O ensaio real aguarda worker exclusivo com identidade, modelo/rota, egress autorizado e credencial exclusiva atestada. Landing Page/CRM/VEN mantidos independentes.

Nenhum merge, deploy, original/clone da VPS, DNS, Cloudflare, PSP/Asaas, checkout, main, credencial ou produção. Sites #38 permanece **NO-GO**.

CI da SHA final e regressões afetadas serão registradas no comentário da PR #628 após observação dos jobs, sem presumir êxito.
