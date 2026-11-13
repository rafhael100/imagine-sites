# F1-329 — exportação ZIP privada e verificável de Onepages/Landing Pages sintéticas

**Escopo:** laboratório CLI Linux (GitHub CI), dados inteiramente fictícios, sem leitura de clientes nem cloud. Prova de segurança e portabilidade do formato, **não** funcionalidade de backup operacional de clientes.

## O que faz

O arquivo ci/f329_private_synthetic_zip.py agrega **exatamente** index.html e release-manifest.json, nessa ordem, em ZIP determinístico e sem compressão. Antes de criar o ZIP:

1. Lê os dois arquivos privados por descritores fixados, verificando inode, tamanho e ausência de symlinks/hardlinks/FIFO.
2. Rejeita manifestos com alegação de publicação, aceite oficial ou alteração do digest/bytes HTML.
3. Confere dois hashes fornecidos separadamente: SHA-256 do HTML e SHA-256 da concatenação dos dois arquivos separados por NUL.
4. Confere novamente, em memória, os bytes e os metadados do próprio ZIP; só então grava com abertura exclusiva, modo 0600 e sem sobrescrever arquivo existente.

A operação inspect lê um ZIP com teto total de tamanho, recusa compressão, nomes arbitrários, caminhos relativos, entradas duplicadas, extraneous data, datas/atributos alterados e qualquer diferença de bytes com a serialização canônica. **Não extrai arquivos, não restaura, não serve, não publica e não sobe arquivos a outro provedor.**

## Uso de laboratório

Após executar os comandos existentes no Laravel com APP_ENV=testing e flags de fixture, e calcular os hashes locais independentes:

    python3 ci/f329_private_synthetic_zip.py create \
      --directory apps/sites/storage/app/private/sites-f1-24-onepage \
      --archive /caminho/privado/sites-f329-onepage.zip \
      --product onepage --artifact SHA256_HTML --bundle SHA256_BUNDLE

    python3 ci/f329_private_synthetic_zip.py inspect \
      --archive /caminho/privado/sites-f329-onepage.zip \
      --product onepage --artifact SHA256_HTML --bundle SHA256_BUNDLE

É obrigatório usar um caminho de arquivo ZIP novo, privado, com nome fixo e sem links. O script é propositalmente restrito à plataforma de testes POSIX; o design de exportação para Windows, UI administrativa e storage remoto será separado.

A saída é JSON de evidência sintética sem identidade do cliente, com hashes e flags false de publicação e restauração. **Os pins informados na CLI não são autenticados pelo Portal; não validam titularidade, aceite, histórico de release nem segurança de dados reais.**

## Provas

- ci/f329_synthetic_zip_selftest.py cobre as duas modalidades, determinismo e roundtrip, recusa sobrescrita, symlink/hardlink, ZIP slip, duplicatas, ordem trocada, lixo no início/fim, comentário e manifesto falsamente publicável.
- .github/workflows/sites-f1-24-release-bundle.yml executa selftest e exportação/inspeção com **arquivos Laravel realmente gerados em PHP 8.3 e 8.4**, sem implantações.
- O restore de pacote, transação de banco e exportação real do cliente **não** são contemplados.
- Não incluir dados pessoais, credenciais, URLs reais ou conteúdo de clientes nesses arquivos.
- config/operational-readiness.json continua com status BLOCKED, 10/10 gates false; #281 e OP01–OP05 permanecem bloqueadores para go-live.

## F1-333 — escopo obrigatório também na exportação e inspeção

Desde F1-333, os comandos `create` e `inspect` exigem **também** `--tenant-ref`, `--site-ref` e `--release-ref`, fixados independentemente da entrada ZIP/manifesto. Os três valores são comparados antes de gravar ZIP ou declarar inspeção válida; um arquivo de outra empresa, mesmo com hashes recalculados, falha. A mesma função de validação é compartilhada com a restauração F1-332 para impedir políticas divergentes entre exportação e importação. Acrescente em ambos os exemplos acima as opções `--tenant-ref tenant-fixture --site-ref site-fixture --release-ref release-bundle-onepage` para Onepage ou o release equivalente a Landing Page. Esses argumentos ainda são declarações de laboratório, não autenticação do Portal.

## F1-334 — diretório de arquivos ZIP exclusivamente privado

A exportação e inspeção agora recusam diretórios pais do arquivo ZIP que concedam qualquer permissão para grupo/outros (bitmask 077), e também recusam um symlink como componente final da pasta de backup. É obrigatório preparar uma pasta **0700** antes do comando de laboratório (`mkdir -m 700 /tmp/sites-f334-zip-privado`); os arquivos ZIP individuais continuam 0600. No CI, a pasta ZIP sob `$RUNNER_TEMP` passa a ser criada explicitamente com modo 0700, independente da configuração global do runner. Testes negativos cobrem 0755 na criação e inspeção e pasta pai por symlink. Este controle não é criptografia, não prova posse/identidade do cliente, e não autoriza backup externo ou restore real.

## F1-335 — conferir os bytes reais do ZIP depois de gravar

Uma gravação aceita pelo sistema operacional não garante que os bytes persistidos são idênticos aos bytes verificados em memória. A F1-335 fecha essa lacuna na exportação privada: após `write` + `fsync`, o arquivo ZIP é **reaberto** por um segundo descritor seguro, seus bytes inteiros são comparados com o arquivo canônico esperado e o diretório pai recebe `fsync` antes de retornar sucesso. Se o armazenamento modificar bytes mantendo o mesmo tamanho, ou a escrita não avançar, a operação falha e tenta limpar apenas o inode que criou. Testes adversariais injetam corrupção silenciosa e escrita de zero bytes e garantem que nenhum backup inválido seja anunciado como concluído. Esta é uma garantia técnica local, não uma prova criptográfica de custódia, IAM, backup em nuvem ou recuperação de dados reais.
