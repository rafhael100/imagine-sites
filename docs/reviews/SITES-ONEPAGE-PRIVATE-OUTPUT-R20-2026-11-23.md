# OnePage R20 — contenção de saída acidental de fontes privadas (laboratório)

## Defeito reproduzido sem conteúdo real

Base imutável Sites #623 (`466da7cc2c053495ec72821d35e66af61e41e9f8`): 4/4 workflows SUCCESS antes da escrita. Portal #1097 (`b7a4510f544010f72e83ca27e0c7dafa2c388b27`): 6/6 SUCCESS. Nenhum sucessor Sites R20 identificado no preflight e nenhuma branch de outro escritor alterada. Sites #541/#542 são referências históricas, não bases novas.

O método `OnepageBuyerPrivatePreviewCandidate::render()` retorna uma estrutura `html` + SHA-256 ou nega, mas invoca portas `Closure` em PHP. Se uma porta de leitura **emitir texto diretamente** (`echo`) antes de uma rejeição, esse texto pode sair pela resposta HTTP fora do array de retorno e do `catch` fail-closed. Essa lacuna é diferente da revalidação de revisão/direitos implementada na R19.

Teste test-first `tests/onepage-private-output-r20.php` usa somente o mesmo pedido sintético, um marcador literal fictício e os renderizadores originais. No commit de testes `9ecb63fe5d9102e4359356049f8efbc5860f8fe6`, a [CI R20 45129552033](https://github.com/rafhael100/imagine-sites/actions/runs/45129552033) falhou em PHP 8.3 e 8.4 no caso positivo: saída direta da porta vazou além da estrutura retornada. Outras três CI desse commit passaram; não eram prova de contenção.

## Correção e ensaios

O método agora abre um buffer de saída antes de consultar as portas e o descarta em `finally` tanto no caminho positivo quanto na negativa. Nenhum HTML é emitido via `echo`; o HTML original permanece retornado somente no array, sem alterar seus bytes, SHA ou identidade visual Imagine.

A regressão verifica: porta privada que imprime no sucesso, fonte privada inválida que imprime antes da negativa, testemunha de comprador incorreto que imprime (zero leituras privadas) e revogação editorial durante a revalidação que imprime (negativa após segunda testemunha). Todas as saídas observadas precisam ser vazias; negativas têm erro sanitizado. O teste novo não reproduz as regressões verdes R16–R19. A primeira CI verde após corrigir, na SHA `55b700988ea0b19f7fe124f45382e2f9d811a47f`, foi [45129597210](https://github.com/rafhael100/imagine-sites/actions/runs/45129597210) (dois jobs PHP), com outras três CI SUCCESS nessa mesma SHA.

**Limite de confiança:** o buffer mitiga saída acidental de adaptadores confiáveis, não isola código malicioso que chame `ob_flush`, altere buffers, emita headers, use `exit` ou outras rotas de egress. Portas operacionais devem ser somente leitura, sem efeitos de saída, e validadas pelo produtor oficial. Não é mecanismo de autenticação, CAS, revisão humana, aceite final ou publicação.

## Bloqueios e ação seguinte

Não existe aqui fonte operacional oficialmente atestada do briefing imutável/bytes/confirmacão de direitos/aprovação e revogação do Portal #976 nem identidade e CAS compartilhados; manter o bind fechado. Portal #1003 e Sites #552 continuam apenas precedentes do contrato. Worker OnePage/OpenAI real requer rota/identidade/modelo/egress/credencial segregada previamente atestados; nenhuma chamada ocorreu. Landing Page e destino CRM/VEN não foram modificados nem herdaram resultados. A próxima prova material é o produtor oficial Laravel/PostgreSQL e autenticação dos recibos no mesmo pedido, antes de uma prévia operacional.

Sem main, merge, deploy, VPS, Asaas, checkout, DNS, Cloudflare, alterações de credenciais, publicação ou contratação. **Sites #38 permanece NO-GO.**
