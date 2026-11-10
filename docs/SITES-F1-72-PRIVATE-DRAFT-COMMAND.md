# F1-72 — Gerador Laravel de rascunhos privados, sem aceite

Comando exclusivamente de laboratório: `SITES_SYNTHETIC_DRAFT=1 php artisan sites:assemble-draft-fixture {onepage|landing_page} {classic|modern|warm} {basic|focused}` com Laravel em `APP_ENV=testing` e PHP CLI. Ele seleciona **somente** dois arquivos de copy inteiramente fictícios, não recebe caminho de arquivo arbitrário nem texto de cliente, e utiliza a composição F1-71 para criar um pacote privado de revisão interna.

Cada variante escreve, numa pasta nova em `storage/app/private/`, somente `index.html` inerte, `release-manifest.json` com SHA-256 exato e `internal-review-draft.json` com estado `internal_review`, revisão 2, `internalReviewRequired=true` e publicação/aceite **false**. Reexecuções não sobrescrevem dados, destino simbólico é recusado, arquivos têm permissões restritas no runner Linux. A saída não contém proposta de aceite do Portal, token, domínio ou dados reais.

O teste Laravel valida as 12 combinações (2 produtos × 3 estilos × 2 conteúdos), tamanhos/hashes, invariantes não publicáveis, ausência de scripts e formulários, opções inválidas, desligamento padrão, não sobrescrita e symlink. A execução da suite padrão ocorre em PHP 8.3/8.4.

Este comando é **um ensaio offline**, não um editor ou gerador operacional. A futura entrada de conteúdo real exige contratos e segurança P0, origem confiável do Portal e tratamento de dados conforme LGPD. Sem IA externa, usuário real, VPS, Cloudflare, despesas, deploy ou merge. Depende de F1-71 (#93). Os dez gates operacionais permanecem BLOCKED.
