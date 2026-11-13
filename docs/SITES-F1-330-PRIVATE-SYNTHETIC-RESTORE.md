# F1-330 — recuperação controlada de ZIP sintético em pasta privada NOVA

**Estado:** somente laboratório POSIX/Linux no GitHub CI, com dados completamente fictícios. Não é restauração de cliente, autorização de publicação nem backup de produção.

O script ci/f330_private_synthetic_restore.py complementa a exportação F1-329:

1. Recusa execução fora de APP_ENV=testing e da flag SITES_SYNTHETIC_RESTORE=1; em Linux local fora GitHub CI exige também SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE=1.
2. Lê um ZIP já gerado em diretório privado por descriptor, valida a forma canônica, os dois arquivos exatos, a semântica de prévia inerte/noindex, os flags não operacionais e os hashes SHA-256 externos.
3. Aceita somente pasta pai já existente com permissão de dono, e nome final FIXO restored-f330-onepage ou restored-f330-landing_page; mkdir é exclusivo e recusa qualquer diretório/link anterior.
4. Escreve index.html e release-manifest.json em arquivos novos, modo 0600 e O_EXCL/O_NOFOLLOW, faz fsync e readback integral dos bytes antes de produzir evidência.
5. Nunca extrai caminhos do ZIP como nomes no filesystem. Se uma escrita falhar, a pasta parcial permanece isolada; é responsabilidade do operador limpar os dados fictícios. Nunca remove um alvo potencialmente substituído.
6. A evidência JSON informa labFilesRecovered=true, mas operationalRestore=false, publishable=false, officialClientAcceptance=false e crmTouched=false. Nada conecta Portal, CRM, DNS, CDN, DB ou API.

Exemplo fictício, após ter produzido o ZIP com F1-329 e com os dois hashes calculados independentemente:

    install -d -m 700 /tmp/sites-f330-fixture-privado
    APP_ENV=testing SITES_SYNTHETIC_RESTORE=1 python3 ci/f330_private_synthetic_restore.py \
      --archive /tmp/sites-f329-onepage.zip \
      --parent /tmp/sites-f330-fixture-privado \
      --target-name restored-f330-onepage \
      --product onepage --artifact HASH_HTML --bundle HASH_BUNDLE

A pasta-alvo NÃO pode existir antes do comando. Uma segunda tentativa com o mesmo nome falha. Nunca apontar o diretório pai para instalações ou diretórios de clientes.

ci/f330_private_restore_selftest.py cobre laboratório sem as flags, APP_ENV não testing, SHA errado, nome de traversal, outro produto, symlink de destino, ZIP alterado, parent 0755, sucesso de ambos os produtos, bytes 0600 e recusa sobrescrita.

O workflow F1-24 executa geração Laravel PHP 8.3/8.4 → exportação ZIP → inspeção do ZIP → recuperação privada → cmp dos dois arquivos → tentativa de duplicação negada. **Não é restauração transacional durável**: falta autenticação independente dos pins, CAS distribuído, criptografia de backup, armazenamento remoto, controle de retenção, importação de registros de clientes, isolamento multiempresa, anti-revogação e homologação do banco. OP01–OP05, issue #281 e os dez gates BLOCKED continuam P0.
