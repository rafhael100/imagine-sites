# F1-99 — Proteção contra ancestrais simbólicos e hardlinks no pacote privado

## Problema

O verificador F1-86 já recusava links simbólicos nos próprios arquivos e no diretório final, mas um **diretório ancestral simbólico** podia redirecionar uma leitura. Também era possível verificar bytes inicialmente corretos de um arquivo com **dois nomes ligados ao mesmo inode** (*hardlink*), inclusive com uma referência externa ao inventário privado.

## Implementação

`SyntheticPrivateDraftVerifier::verifyDirectory()` percorre a hierarquia de diretórios e rejeita qualquer componente que seja um symlink, mesmo quando o diretório final não é um link. Para os quatro arquivos, exige que `lstat()['nlink'] === 1` após limpar o cache de stat; uma segunda referência física àquele arquivo impede a verificação. Permanecem os quatro nomes exatos, validação canônica JSON, checksums de HTML e dossiê, estrutura de QA, estado pendente e flags de não publicação.

O teste `SyntheticPrivateDraftVerificationTest::test_symlinked_parent_and_hardlinked_package_file_are_denied()` comprova as duas negativas usando diretórios fictícios locais. O HTML original é restaurado e a verificação passa novamente, demonstrando que não houve mudança permanente no conteúdo. A matriz Laravel PHP 8.3/8.4 e Docker exercitam o código.

## Limites

São defesas adicionais em laboratório, **não** uma garantia contra troca de caminhos entre o check e a abertura (*TOCTOU*), manipulação por um processo com controle do filesystem, mount points, atributos ou alterações posteriores à leitura. Uma solução operacional precisa de armazenamento privado segregado e resistente a troca, abertura de descritores com proteção contra symlinks (ex.: APIs nativas apropriadas), snapshot imutável/versão, identidade Portal, autorização e auditoria independente. Um hash não prova aceite humano. Nenhuma rota HTTP, migração operacional, Cloudflare, VPS ou autorização de deploy é habilitada. Os 10 gates permanecem BLOCKED.
