# F1-185 — Inventário privado único para edição e layout

F1-174 exportava uma página editada usando serialização própria de dossiê, resumo e manifesto. F1-184 acrescentou um gerador canônico de quatro arquivos, repetindo conceitualmente a montagem. Nesta entrega o comando editorial F1-174 passa a usar exatamente o mesmo SyntheticPrivateReviewPackageV1::fromPendingDraft, sem duplicar regras de JSON, hashes, estados pendentes ou ordem dos arquivos.

A verificação permanece rígida: somente rascunho internal_review, revisão 2 e dossiê com reviewerDecisionStatus=pending; os campos publishable e officialClientAcceptance continuam false. O pacote tem exatamente index.html, internal-review-dossier.json, internal-review-draft.json e release-manifest.json e exige readback SHA-256 independente.

Os novos testes de domínio montam 18 versões distintas e comprovam determinismo, ordem dos arquivos, invariantes e negação de HTML adulterado, publishable=true e falso aceite interno. A matriz Laravel PHP8.3/8.4 reexecuta os fluxos de edição F1-174, de 54 layouts F1-184 e a navegação privada herdada da F1-40.

A auditoria da execução Docker F1-83 após F1-184 detectou que o antigo teste de navegação ainda reconhecia apenas oito links: o bypass acessível do iframe F1-183 criou o nono. A expectativa foi atualizada nos ramos F1-184 e F1-185 para aceitar somente os dois fragmentos fixos #apos-previa e #detalhes, além das rotas locais internas.

A funcionalidade não publica, autentica, registra aceite do cliente, transporta lead ou executa CRM real. Sem merge em main, VPS, DNS, Cloudflare, dados pessoais ou custos. OP01/02/04 e dez gates de produção bloqueados.
