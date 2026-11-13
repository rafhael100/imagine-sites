# F1-184 — Salvar personalização de layout e menu como pacote privado

A equipe Imagine pode transformar uma composição estritamente fictícia (produto, tema, ordem das três seções e navegação on/off) em um pacote local completo, reutilizando o verificador de digests e o gravador privado F1-86/87.

O comando CLI sites:export-layout-review-fixture recebe somente enums para produto (onepage/landing_page), preset (classic/modern/warm), order (seis permutações) e navigation (on/off, com on negado em Landing Page). Executa somente com APP_ENV=testing, PHP CLI, GitHub Actions e SITES_F184_LAYOUT_PACKAGE=1. Não recebe caminho de destino, cliente, upload, HTML, texto livre nem credencial.

A classe SyntheticPrivateReviewPackageV1::fromPendingDraft gera quatro arquivos canônicos: index.html, internal-review-dossier.json, internal-review-draft.json e release-manifest.json. Antes da escrita exige revisão interna pendente, hashes e metadados coerentes e os indicadores publishable=false e officialClientAcceptance=false. O gravador privado permite nomes específicos de F1-184, recusa sobrescrita/symlink e verifica novamente os arquivos gravados.

A matriz PHP 8.3/8.4 valida 54 combinações (36 Onepages e 18 Landing Pages), menu, ordem, SHA distinto, dossiê, parâmetros ilegais, reexecução, symlink, adulteração e regressões F1-174/F1-181. O Docker F1-83 também deve executar o conjunto com usuário não-root e rede interna.

É prototipação sintética privada: sem autenticação real, coleta, dados pessoais, CRM, cliente, domínio público, deploy ou custos. OP01/OP02/OP04 e 10 gates operacionais continuam bloqueados; main inalterada.
