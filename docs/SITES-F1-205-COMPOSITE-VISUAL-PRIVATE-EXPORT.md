# F1-205 — Exportação privada das combinações visuais completas

A F1-203 permitiu revisar tema, cor, ordem das seções e menu na mesma interface, mas essas escolhas não podiam ser exportadas pelo fluxo canônico de arquivos privados. A F1-205 conclui o percurso **seleção visual sintética → plano de revisão → HTML verificado → quatro arquivos privados**, sem habilitar publicações.

O novo `SyntheticCompositeVisualPrivateFixture::build` consulta o catálogo único F1-204, monta o HTML com o renderer existente e, caso haja mudança real, exige compatibilidade byte a byte com `SyntheticCompositeVisualRevisionPlan` F1-202: a versão anterior é aprovada apenas no laboratório, a proposta volta a DRAFTING sem digest aprovado e o novo documento permanece pendente para uma revisão independente. Para a opção baseline, nenhuma mudança nem aprovação anterior fictícia é inventada. Todas as opções mantêm `publishable=false` e `officialClientAcceptance=false`.

O comando `sites:export-visual-fixture` aceita somente cinco enums: produto (Onepage/Landing Page), tema (classic/modern/warm), ordem (seis combinações fixas), paleta (default/ocean/plum/forest) e navegação (on/off, disponível apenas na Onepage). Executa exclusivamente sob PHP CLI, APP_ENV=testing, GITHUB_ACTIONS=true e flag SITES_F205_VISUAL_PACKAGE=1. Não recebe dados pessoais, IDs de empresas reais, uploads, HTML, URLs nem destino de arquivo do usuário.

Os diretórios seguem o allowlist exato `sites-f1-205-{produto}-{tema}-{ordem}-{paleta}-{menu}` do `SyntheticPrivateDraftWriter`. O inventário é o mesmo já verificado em F1-185: `index.html`, `internal-review-dossier.json`, `internal-review-draft.json` e `release-manifest.json`, com SHA-256 e readback de conteúdo, gravação exclusiva e recusa de symlink/sobrescrita.

A suíte PHP F1-205 gera e verifica **216 designs** (2 baselines e 214 mudanças) sem gravar arquivos; compara as propostas aos planos de revisão, checa SHA-256, recusa adulterações e seleções inválidas. O teste Laravel de integração exporta uma amostra variada de oito configurações, verifica arquivos físicos, catálogo, noindex, dossiê pendente, ausência de form/scripts e recusa de sobrescrita, symlink e modificação externa. As matrizes PHP 8.3/8.4 e Docker de laboratório permanecem obrigatórias.

Este é um exportador privado de protótipos; não é editor de cliente nem identidade autenticada, hospedagem, formulário ativo, aprovação oficial, entrega ao CRM, deploy ou rollout. OP01/02/04 e os dez gates operacionais continuam bloqueados. Nenhum merge em main ou gasto com serviços.
