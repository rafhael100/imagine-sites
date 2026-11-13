# F1-332 — backup sintético só pode ser restaurado para o escopo fixado

**Laboratório offline Linux, sem clientes ou dados pessoais, NO-GO produção.**

A recuperação F1-330/331 tinha proteção de integridade dos dois arquivos por SHA-256 e promoções atômicas sem overwrite, mas dependia dos hashes fornecidos e do tipo de produto. Um pacote de outra empresa, acompanhado de hashes próprios válidos, poderia ser aceito em um diretório de laboratório que esperasse outro projeto.

A F1-332 acrescenta **três identificadores exigidos separadamente**: `--tenant-ref`, `--site-ref` e `--release-ref`, todos com a mesma sintaxe de IDs do `ReleaseManifest`. O comando primeiro verifica o ZIP canônico, manifesto e hashes; **antes de criar qualquer pasta** compara a tripla esperada com a tripla gravada no manifesto. Qualquer divergência, pin ausente ou ID inválido falha sem escrever arquivo ou criar staging. A evidência indica `scopeMatched=true` e **`scopeAuthenticated=false`**.

Exemplo fictício após obter os hashes já presentes na etapa F1-329:

```bash
APP_ENV=testing SITES_SYNTHETIC_RESTORE=1 \
python3 ci/f330_private_synthetic_restore.py \
  --archive /tmp/fixture-private/sites-f329-onepage.zip \
  --parent /tmp/fixture-private/new-restore \
  --target-name restored-f330-onepage --product onepage \
  --artifact HASH_HTML --bundle HASH_BUNDLE \
  --tenant-ref tenant-fixture --site-ref site-fixture \
  --release-ref release-bundle-onepage
```

O laboratório local fora de GitHub Actions ainda exige `SITES_ALLOW_LOCAL_SYNTHETIC_RESTORE=1`. O usuário deve criar previamente a pasta pai 0700. Não usar dados reais.

A matriz CI F1-24 PHP 8.3/8.4 exercita os dois produtos Laravel com a tripla fixa do fixture. Testes F1-330 negam tenant, site ou release diferentes, pin ausente e um ZIP de **outra empresa, com hashes recalculados e íntegros**. O teste F1-331 continua verificando restauração atômica e concorrência.

**Não confundir validação de escopo com identidade ou autorização.** A tripla digitada na CLI é uma alegação sintética; o Portal ainda precisa fornecer um registro autenticado/autorizado e o banco precisa impor isolamento RLS, revogação e retenção. A implementação não autentica tokens, não importa backups de clientes nem autoriza publicações. O status `BLOCKED` e os dez gates operacionais permanecem inalterados.

## F1-333 — paridade do escopo em todas as operações

O validador de `tenantRef/siteRef/releaseRef` agora mora no módulo de ZIP F1-329 e é reutilizado pelo restore F1-330. Os comandos ZIP `create` e `inspect` exigem a mesma tripla externa que `restore`, evitando que uma exportação de outra empresa seja inadvertidamente considerada íntegra para o projeto selecionado. O escopo continua uma alegação não autenticada em ambiente sintético e não autoriza uso com clientes.
