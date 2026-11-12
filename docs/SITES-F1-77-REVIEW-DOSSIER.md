# F1-77 — Dossiê privado de revisão interna, ainda pendente de decisão

A F1-76 estabeleceu como uma decisão interna **de laboratório** deve se vincular ao HTML, pacote, revisão e itens editoriais. A F1-77 prepara o material para essa decisão, sem simular nem disparar uma aprovação.

A nova classe `SyntheticInternalReviewDossier::fromDraft` gera um JSON determinístico, SHA-256 calculado dos bytes completos, contendo: referências do site/release/fluxo e revisão exata, digest do HTML, digest do bundle, inventário de QA estrutural, checks editoriais, recomendações e a lista explícita de itens que o revisor **precisará** reconhecer. `reviewerDecisionStatus=pending`, `officialClientAcceptance=false` e `publishable=false` são invariantes.

O comando Laravel de laboratório `sites:assemble-draft-fixture` (F1-72) agora exporta quatro arquivos em pasta privada nova: `index.html`, `release-manifest.json`, `internal-review-draft.json` e `internal-review-dossier.json`. O resumo incorpora `reviewDossierDigest` para permitir verificar a integridade do checklist sem confiar em dados do browser. Nenhuma decisão é escrita: a F1-76 permanece uma operação separada, com papel do revisor **apenas fictício**, não IAM.

Testes: 2 produtos × 3 temas × 2 variantes de copy, execução Laravel em PHP 8.3/8.4, validação do digest e de cada checklist, inexistência de aprovação, conteúdo HTML inerte, diretório privado não sobrescrito e symlink negado. Teste PHP autônomo confere determinismo do dossier e os alerts. O arquivo não contém contato, senha, token ou dado de cliente. O SHA-256 não é assinatura, trilha auditável externa ou consentimento. Sem integração real com Portal, CRM, Cloudflare ou VPS; todos os dez gates seguem BLOCKED.
