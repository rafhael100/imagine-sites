# F1-200 — Evidência canônica da mudança de layout

O motor agora consegue produzir um registro JSON canônico e content-addressed da mudança simultânea da ordem das seções e da navegação interna, SEM registrar qualquer decisão de aprovação e sem escrever dados.

A classe SyntheticLayoutChangeReviewEvidenceV1 exige o plano de nova revisão da F1-187, o objeto original de HTML, o briefing anterior e o workflow sintético exatamente no estado AWAITING_PORTAL_ACCEPTANCE/revisão 3. Ela recompõe os HTMLs anterior e novo, confere os SHA-256 e manifesta a identidade das duas versões. A próxima revisão obrigatória permanece no estado DRAFTING/revisão 4, sem digest aprovado. O retorno conserva os dois conjuntos de opções, tenant/site/workflow fictícios e a indicação de que nenhuma aprovação de cliente, entrega CRM ou publicação foi autorizada.

O método verifyJson não trata JSON enviado pelo usuário como autoridade: recompõe o registro esperado a partir dos objetos de origem e exige a comparação exata de cada byte. Campos extras, duplicações, falsos estados de publishable, alteração no HTML anterior, origem sem revisão e bytes fora do formato são recusados. O SHA-256 do registro facilita rastrear uma mudança sem conceder poderes de aprovação.

O teste tests/f200-layout-change-evidence.php cobre 48 combinações alteradas, 48 hashes únicos, verificações de determinismo e tentativas adversariais. A suíte principal de domínio PHP 8.3/8.4 executa a rotina no fluxo de pull request/main existente. Não cria endpoints, integrações externas, arquivos de cliente, transações, credenciais, permissões nem deploy. Não substitui IAM do Portal, revisão independente de segurança ou os dez gates operacionais.
