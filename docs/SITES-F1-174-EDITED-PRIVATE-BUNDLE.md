# F1-174 — Gravar o resultado da edição em pacote privado completo

A F1-171 introduziu a edição pontual e F1-172 exibiu a comparação antes/depois na interface Laravel. A F1-174 conecta essas etapas ao gravador de pacote privado já existente no motor, para que uma página editada possua HTML, manifesto e dossiê de revisão **fisicamente verificáveis**.

O novo comando de laboratório sites:export-editorial-package-fixture aceita apenas produto (onepage/landing_page) e estilo (classic/modern/warm) e lê exclusivamente a fixture fictícia v4 versionada no repositório. PHP CLI, APP_ENV=testing, GITHUB_ACTIONS=true e as flags SITES_SYNTHETIC_EDITORIAL_REVIEW e SITES_F174_EDITORIAL_PACKAGE são obrigatórios; não há argumentos de caminho, upload, texto de usuário, rede, autenticação externa ou dados pessoais.

O comando constrói um rascunho de origem, simula revisão interna, aplica patch restrito de FAQ e etapas via SyntheticEditorialPatchV1 e verifica que os bytes e hash do patch coincidem com o novo rascunho. A saída é composta de exatamente quatro arquivos: index.html, internal-review-dossier.json, internal-review-draft.json e release-manifest.json. O estado permanece internal_review, decisão pending, publicável=false e aceite do cliente=false.

A escrita usa SyntheticPrivateDraftWriter::create da F1-87, incluindo verificação de conteúdo antes da gravação, diretório temporário exclusivo com arquivos privados, move atômico entre diretórios irmãos, readback SHA-256 e recusa de sobrescrita e symlinks. O allowlist de nomes do Writer foi estendido **apenas** para sites-f1-174-{produto}-{tema}-edited, mantendo os nomes da F1-72.

A matriz Laravel PHP8.3/8.4 comprova seis combinações de produto/tema, os quatro arquivos exatos, integridade do dossiê, ausência de scripts/formulários, replay da exportação recusado, symlink protegido e adulteração detectada. Esse pacote não é release operacional, publicação, aprovação do cliente, backup real, dados de cliente ou garantia de filesystem hostil. OP01/02/04 e 10/10 gates de produção continuam bloqueados.
