# F1-90 — Prévia visual não pode aprovar a revisão interna implicitamente

## Problema observado

O endpoint de laboratório `/_lab/sites/v1/review` (F1-38) construía o HTML sintético e chamava `SyntheticProductionWorkflow::advance('imagine_reviewer', 'approve_internal', ...)` durante uma requisição **GET** de prévia. Embora nenhum cliente ou produção pudesse acessar esse endpoint, a UI exibia uma etapa de “Revisão interna simulada” concluída e uma proposta de revisão para o Portal sem a decisão editorial explícita, o dossiê correspondente ou evidência persistida. Esse comportamento não deveria servir de modelo para a interface de operação real.

## Correção

A rota continua acessível **exclusivamente em testes Laravel no processo PHP CLI**, com `SITES_SYNTHETIC_HTTP=1`, `SITES_SYNTHETIC_REVIEW_UI=1` e IP local, e permanece indisponível no servidor HTTP/FPM. Agora ela usa `SyntheticStructuredDraftAssembly::build` e `SyntheticInternalReviewDossier::fromDraft` para obter o HTML, SHA-256, QA, estado de trabalho e digest canônico do checklist. A revisão permanece no estado `internal_review`, revisão 2. Não chama `approve_internal`, `portalReviewCandidate` ou `SyntheticInternalReviewDecision::decide`.

A tela exibe claramente **“Revisão interna pendente”**, SHA-256 do dossiê e número de observações editoriais a reconhecer; conserva o HTML inert `iframe sandbox=""`, as variantes de produto, estilo e dispositivo, noindex, CSP, os cards de QA e a indicação de que o aceite oficial somente existe no Portal. Não há botão, formulário, rota de aprovação, publicação ou entrega.

## Verificação

`SyntheticReviewPendingAuthorityTest` cobre as 12 variantes de produto/tema/dispositivo, comparação do HTML e dossiê com objetos gerados de modo independente, revisão 2 pendente, ausência de estado aprovado e impossibilidade de criar endpoints. O teste também inspeciona a declaração isolada da rota para rejeitar futuras reintroduções de transições de aprovação no caminho GET, sem interferir nas outras simulações separadas de revisão de conteúdo.

O endpoint é fictício, não fornece autenticação de operadores e não substitui futura UI de revisão interna devidamente autenticada e auditada. Todos os 10 gates de produção continuam `BLOCKED`; nenhum usuário real, Cloudflare, CRM ou VPS é envolvido.
