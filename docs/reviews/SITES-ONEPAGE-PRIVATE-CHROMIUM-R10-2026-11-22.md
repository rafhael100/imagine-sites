# OnePage R10 — recusar aceite sem recibo durável (somente laboratório)

## Preflight 22/11/2026
- Base Sites #613 (R09) HEAD imutável `bde6a83947d6acc04496e5cfe095535be886c31f`. Quatro workflows daquela SHA foram observados como **success**: Actions 45021439833, 45021439846, 45021439862 e 45021439898.
- #605 é teste SQL concorrente e não foi alterado; Portal #976/#1003 ainda não apresentam fonte operacional autenticada de briefing, comprador e revisão; AI Agents #490 permanece sem worker real; Portal #1088 é Landing, separada.
- Branch filha exclusiva R10, draft, quatro arquivos permitidos e diff contra a SHA exata da base. Nenhum segredo nem URL pública.

## Defeito técnico efetivo da fixture R09
O harness de aceite retornava **HTTP 201** depois de chamar `file_put_contents(... FILE_APPEND|LOCK_EX)` sem conferir o resultado. Se o arquivo de recibos fosse um link simbólico para `/dev/null`, o append podia reportar sucesso sem preservar **um byte sequer**: o navegador receberia um falso aceite persistido. O bug é exclusivo da fixture de teste — não foi demonstrado no produto nem no Portal.

## Correção implementada
- Recusar destino de recibo simbólico/não regular com HTTP 503, sem conceder aceite.
- Negar falha de leitura anterior, append parcial/falho e divergência na releitura do último registro. Nenhuma resposta 201 antes de verificação do conteúdo recém-escrito. Este teste não prova atomicidade entre escritores concorrentes nem durabilidade física após queda de energia.
- Chromium real R10 conserva os três recibos A/B/C, substitui temporariamente **somente no diretório descartável** o destino pelo symlink para o sink local `/dev/null`, apresenta release sintética D, exige **HTTP 503** e restaura o arquivo original byte a byte. O antigo R09 concederia indevidamente 201. Nenhuma conexão externa, IAM, credencial ou modificação do servidor real.
- O workflow executa PHP 8.4, Node, Chromium em loopback e fixa SHA/escopo; o resultado é declarado apenas pela CI da HEAD final, não por este documento.

## Limites e próximas ações
Esta proteção é uma regressão de laboratório. Não representa aceite do comprador autenticado, recibo operacional ou autorização para publicação. Falta fonte transacional oficial do briefing imutável, confirmação de direitos pelo comprador, revisor autorizado e revogação por versão no Portal. API OpenAI real permanece sem rota privada, identidade, modelo, egress e credencial OnePage atestados. Não converter fixture em autoridade, não repetir testes simulados anteriores nem transportar evidência para Landing. Sites #38 **NO-GO**. Sem merge, deploy, VPS, DNS, Cloudflare, Asaas, checkout, fornecedor, produção ou dados reais.
