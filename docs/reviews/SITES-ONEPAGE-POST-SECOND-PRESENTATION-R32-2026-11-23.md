# OnePage R32 — validação após segunda apresentação privada (NO-GO)

**Base imutável:** Sites [#635](https://github.com/rafhael100/imagine-sites/pull/635), HEAD `7a6c2e4764a6f163432e39371aaf4cf8370d1160`, quatro workflows SUCCESS verificados antes de escrever. Portal [#1099](https://github.com/rafhael100/imagine-portal/pull/1099) (HEAD `16b225a464422570e07430e900275bfcd5813970`, seis workflows SUCCESS) e Agents [#490](https://github.com/rafhael100/imagine-ai-agents/pull/490) apenas consultados. Sites #541/#542 referências históricas. Sem modificação de branches concorrentes.

## Falha nova reproduzida antes do patch

O gate `OnepageExactFinalAcceptanceReadCandidate::observe` compara a segunda apresentação com a primeira, mas na candidata R31 **não verifica se a autoridade e a release ainda são correntes após a própria segunda leitura**. Um callback de testemunho pode devolver os bytes HTML previamente aprovados e, na mesma leitura, retirar direitos de uso, revogar aprovação do briefing, editar bytes de fonte ou substituir release/epoch/SHA/aprovação. Sem pós-validação, a versão R31 poderia devolver `clientFinalAccepted=true` para estado obsoleto.

[CI vermelha real da primeira versão da branch](https://github.com/rafhael100/imagine-sites/actions/runs/45247051387), SHA `fe2b0da7de0a5e322566a2d97bbd6ddad12e4375`: jobs PHP 8.3 e 8.4 falharam com `ONEPAGE SECOND PRESENTATION R32 FAILED: post-second-HTML uniform denial rights`. Essa execução **não** é sucesso.

## Correção mínima

Somente após a segunda testemunha independente de HTML retornar os bytes esperados, reler a revisão/declaração do comprador/aprovação exata usando o **renderizador prévio fail-closed existente** e comparar com a primeira prévia; depois consultar o head da release e comparar nomes, tipos e valores. Falha entre as consultas termina em negativa uniforme, sem terceira leitura de recibo ou HTML. Não altera o esquema `sites.ai-copy.v1`, identidade visual, parser, roteamento, publicação, origem do briefing ou papéis.

O teste R32 usa **o mesmo pedido, briefing, comprador e HTML sintéticos** de R31. Cobre direitos, revisor, bytes do briefing, releaseRef, artifactEpoch, htmlSha256 e aprovação; controle positivo e recusa de outro comprador do mesmo tenant; conferência de ausência de saída confidencial incidental. Ajustes pontuais das contagens de releitura nas regressões R28–R31. Matriz CI PHP 8.3/8.4 e quatro workflows globais; versão final/resultado somente após a CI da HEAD exata.

## Limites e gates

Read-after-read é apenas detecção de corridas observáveis: **não** prova snapshot transacional, CAS, ausência de mudanças após a leitura final, login nativo, aceite operacional ou autorização de publicação. Fontes de dados ainda são fixtures; o Portal [#976](https://github.com/rafhael100/imagine-portal/issues/976) não demonstra adaptador oficial Laravel/PostgreSQL com revisão imutável, declaração do comprador e decisão do revisor. Worker OnePage real, identidade específica, modelo, rota privada, egress e credencial segregada não atestados; nenhuma chamada OpenAI real ou prévia operacional gerada. Landing Page não tocada; não transferir prova.

Sites [#38](https://github.com/rafhael100/imagine-sites/issues/38) permanece NO-GO. Nenhum merge, deploy, alteração da VPS, Asaas, checkout, Cloudflare, DNS, credenciais, pagamento, produção, rollback automático ou publicação.
