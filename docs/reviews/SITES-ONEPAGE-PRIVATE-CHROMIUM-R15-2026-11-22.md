# OnePage R15 — leitura limitada do ledger sintético (NO-GO)

## Preflight observado em 22/11/2026
- Base Sites [#618](https://github.com/rafhael100/imagine-sites/pull/618), HEAD pinada `4b274d9eac98986f058727b974415058b60d31b5`. Na SHA exata: runs 45048242086, 45048242154, 45048242156 e 45048242144 com conclusão `success`. Consulta a PRs e branch R15 não localizou sucessor/escritor desta trilha antes da criação.
- Portal [#976](https://github.com/rafhael100/imagine-portal/pull/976) HEAD `89e309b497137cec4960b87f90a49d00bbf92843` com fontes de briefing e witness **default-deny**, sem writer operacional. Portal #1003 / Sites #552 preservados; #541/#542 históricos. AI Agents #490 apenas Responses offline; Landing Portal #1088 / Sites #580 permanecem separados.

## Lacuna concreta, sem criar regra comercial
No router PHP de laboratório OnePage R14, `file_get_contents($receiptPath)` carregava o histórico inteiro para verificar um único replay. Um ledger composto apenas por linhas JSONL canônicas, mas desproporcionalmente grande, poderia consumir memória sem limite antes de concluir a resposta. Não é prova de corrupção nem justificativa para aceitar mais dados.

## Correção e teste
- Leitura de no máximo **65537 bytes** com `file_get_contents(..., 0, 65537)`, limite de **65536 bytes** aceitos. Arquivo com 65537 ou mais bytes é recusado HTTP 503 **antes** de parsing e idempotência, preservando lock e validação R12–R14.
- Chromium/HTTP em origem somente loopback constrói um histórico sintético maior que 65536 bytes com **600 recibos extras**, todos canônicos, e com `epoch/ref` distintos. O clique idempotente antes aceito em R14 precisa receber HTTP 503 sem nova escrita. O arquivo original é restaurado e comparado byte a byte. Não utiliza identidades, documentos ou dados reais.
- Workflow draft-only em branch isolada, pin de base e HEAD, quatro caminhos exatos, sem segredos. Verificar **CI da SHA final** antes de alegar PASS; os runs verdes anteriores não comprovam esta alteração.

## Limites e próxima dependência material
A correção é **exclusivamente da fixture**. Não satisfaz o aceite final do cliente, sessão autenticada Portal, prova de briefing oficial nem obrigação de auditar/persistir transacionalmente. Integração oficial OnePage continua fechada por ausência de fonte dos bytes/revisão imutável, confirmação de direitos do comprador e aprovação/revogação do revisor vinculada à mesma revisão (Portal #976). OpenAI real depende de worker central privado com identidade, modelo/egress, credencial específica e autorização de escopo verificáveis, não de key Faísca. Landing exige autoridade própria de formulário/VEN; sem este teste emprestar evidência.

**DRAFT / NO-MERGE / NO-DEPLOY / Sites #38 NO-GO.** Sem checkout, Asaas, VPS, Cloudflare, DNS, credenciais, API OpenAI, contratação ou publicação.
