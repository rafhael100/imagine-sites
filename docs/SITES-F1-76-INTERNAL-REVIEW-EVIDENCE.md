# F1-76 — Evidência de decisão editorial interna com vínculo a bytes exatos

A produção do rascunho na F1-71 terminava em `internal_review` e, até aqui, o fluxo sintético permitia simular a aprovação interna diretamente, sem vínculo **estruturado** entre a decisão e a revisão do artefato, os bytes do pacote e as recomendações editoriais conhecidas.

A classe `SyntheticInternalReviewDecision` adiciona um registro imutável e determinístico de decisão **somente para o laboratório**. A entrada obrigatória tem: snapshot de rascunho na revisão atual, papel fictício `imagine_reviewer`, referências sintéticas de decisão/revisor, digest do HTML, digest do bundle, ação `approve_internal` ou `request_rework` e lista exata de chaves editoriais reconhecidas pelo revisor. Ela reconstrói o HTML e relatórios, verifica bundle/manifests e rejeita qualquer diferença de fonte, hash, relatório ou revisão.

Uma aprovação interna exige reconhecer explicitamente **todos** os pontos editoriais pendentes, inclusive para mensagens sintéticas com título ou descrição curtos; sem esse reconhecimento o fluxo permanece em revisão. Um pedido de retrabalho exige motivo de vocabulário fechado e descarta o digest no novo snapshot. Nem mesmo após aprovação interna há aceitação oficial do cliente, direito de publicar, prova de entrega ou emissão de comando ao Portal.

O registro tem `reviewDigest` SHA-256 dos seus próprios dados canônicos. Esse hash **não é assinatura digital, autenticação, prova de identidade nem replay durable**: reexecuções sintéticas com a mesma referência são determinísticas e não geram ledger; isso fica para o Portal e serviços autorizados na homologação. Os testes abrangem 2 produtos, 3 estilos, copy boa/insuficiente, versões stale, ator não revisor, hashes incorretos, sugestões incompletas/desconhecidas e retrabalho.

Continuam não cumpridos os contratos operacionais, a validação de identidade real dos revisores, assinatura/auditoria imutável, PostgreSQL operacional, integração com Portal/CRM e os 10 gates em `BLOCKED`. A classe não contém endpoint, senha, API paga, deploy, DNS, acesso a VPS ou processamento de clientes.
