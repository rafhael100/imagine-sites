# OnePage — deadline e contenção de saída do worker entre processos (NO-GO)

Escopo exclusivo **OnePage**. Base imutável: Sites [#578](https://github.com/rafhael100/imagine-sites/pull/578) HEAD `50b72e90bdd2657c59fbf933db865f30e68f2e54`; executor fictício da central [AI Agents #489](https://github.com/rafhael100/imagine-ai-agents/pull/489) HEAD `3f9ef347e51fe419e75d6582bf7630a51fe371dd`. Sites #541/#542 históricos; Landing Page #569 independente. A revisão e o contrato `sites.ai-copy.v1` da #576/#577/#578 são reutilizados sem novos campos.

## Lacuna concreta após #578

O handoff já executava um filho PHP real com stdin/stdout, mas sua leitura bloqueante (`stream_get_contents`) não tinha um limite de tempo por operação; um processo filho travado poderia consumir indefinidamente o tempo do executor. O limite de 8192 bytes de saída era aplicado **depois** da leitura, sem impedir o filho de continuar pendurado, e um diagnóstico emitido no stderr precisava ser capturado. A nova fronteira `OnepageBoundedWorkerProcessCandidate` aplica limite de tempo monotônico (máximo 5 s), stdout até 8192 bytes, stderr proibido, status de saída 0, limpeza da execução sob falha e negação uniforme sem incluir conteúdo ou diagnósticos privados.

A chamada é `proc_open` com vetor de argumentos (sem shell), script canônico dentro de checkout pinado e nome do worker conhecido. O filho recebe apenas duas flags sintéticas explícitas de ambiente; nenhum segredo, credencial, variável ambiente do pai, tenant, pedido, marca, dados privados do briefing ou autoridade é enviado. Nenhum retry, endpoint externo ou API de modelo foi ativado.

O ensaio de falhas novo injeta criança adormecida, stdout excessivo, stderr, exit code anormal, stdout vazio, variáveis herdadas e caminho não confiável; a composição existente de #578 passa pelo mesmo supervisor e ainda compara o digest da prévia HTML efetivamente servida por HTTP local. O worker real da central permanece **sintético**; não repetir a simulação de geração da #577. Não há URL privada durável acessível ao comprador.

## Limites, provas e próxima ação

CI GitHub Actions executará PHP 8.3/8.4 sobre a SHA exata da nova PR e checkout de Agents pinado. **Não há prova de chamada à OpenAI.** Não foi disponibilizada nesta sessão identidade privada de worker OnePage com credencial **específica já provisionada**, modelo/endpoint autorizados e egress permitido. Não investigar, exportar, criar ou registrar chaves; API real somente em ambiente privado explicitamente autorizado e com telemetria não secreta. Revisão, aceite humano e autorização técnica continuam separados; Portal #976 não possui fonte transacional oficial de revisão imutável, bytes do briefing, confirmação de direitos do comprador e aprovação/revogação por revisor. Portal #1003/Sites #552 seguem como transporte candidato sem identidade S2S homologada. Sites #38 e gates de pagamento/autorização permanecem **NO-GO**. Nenhum merge, deploy, publicação, VPS, Cloudflare, DNS, Asaas ou checkout.

**Evidência de CI e HEAD:** registrar no comentário da PR somente após a execução ser observada. Não assumir sucesso antecipado.
