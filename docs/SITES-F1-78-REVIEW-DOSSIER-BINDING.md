# F1-78 — Vincular a decisão interna ao dossiê de revisão apresentado

As etapas F1-76 e F1-77 demonstravam, separadamente, a decisão editorial e o checklist imutável. Agora o método `SyntheticInternalReviewDecision::decide` exige o **SHA-256 exato do dossiê** ao aprovar ou solicitar retrabalho.

O código reconstitui o dossiê a partir do mesmo `SyntheticStructuredDraftAssembly` e compara seu digest ao fornecido. Dossiê faltante, hash trocado ou checklist pertencente a outra revisão são recusados por padrão. O registro resultante inclui `reviewedDossierDigest` ao lado de `reviewedArtifactDigest` e `reviewedBundleDigest` e mantém `officialClientAcceptance=false` e `publishable=false`.

Testes sintéticos PHP 8.3/8.4 cobrem os dois produtos, três temas e duas variantes de copy, verificando ausência e adulteração do digest, versão obsoleta, revisão, ator não autorizado e itens editoriais sem reconhecimento. A verificação é local, imutável e **não** certifica leitura humana: a identidade dos atores é fictícia, o digest não é assinatura, e não existe banco de auditoria ou antirreplay operacional. Os 10 gates de homologação permanecem BLOCKED. Nenhuma integração real, deploy, segredo, custo ou dado pessoal foi utilizado.
