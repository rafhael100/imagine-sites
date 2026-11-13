# Imagine Sites F1-300 — exclusão mútua entre geração e verificação de pacotes privados

**13/11/2026 · Somente laboratório fictício CLI/testing · Sem publicação ou cliente real**

## Falha fechada
A F1-297 já escreve dois arquivos em um staging privado, verifica hashes e promove a pasta com um `rename`. Porém, a verificação de existência da pasta e o `rename` não formam por si um protocolo de exclusão mútua entre dois processos PHP. A F1-298 permitia também que um leitor observasse uma saída enquanto o escritor preparava ou promovia seu pacote.

## Implementação F1-300
- `SyntheticPrivateBundleLockV1` oferece uma trava **cooperativa, exclusiva e não bloqueante** via `flock(LOCK_EX | LOCK_NB)`, com nomes fixos separados para `onepage` e `landing_page`.
- Valida diretório privado, nome canônico, regularidade do descritor, inode/device iguais entre `fstat` e `lstat`, rejeição de symlink e hardlink, falhando fechado. A trava permanece aberta durante a gravação, conferência, promoção privada e readback. `finally` libera em qualquer retorno/erro.
- O **leitor** não cria a trava: exige um arquivo persistente já gerado pelo escritor. O arquivo de lock fica sem dados de clientes e **não é removido em execução normal** para evitar múltiplos inodes sob processos concorrentes.
- Leitores e escritores do **mesmo produto** disputam a mesma trava; operações de produtos distintos podem ocorrer paralelamente.
- CLI/lab existente permanece restrito a `APP_ENV=testing`, SAPI CLI e flags explícitas. Nenhuma autenticação, API, rede, DNS, Cloudflare, CRM ou publicação foi adicionada.
- Novos testes PHPUnit e PHP 8.3/8.4 demonstram tentativa simultânea recusada imediatamente, trava liberada, locks distintos por produto, reader sem criação lateral, destinos preservados e symlink bloqueado.

## Limitações importantes
`flock` **não** é um lock de banco, uma garantia de concorrência distribuída, transação durável, política anti-rollback ou defesa contra processos hostis que rodam com o mesmo UID. Um processo local não cooperativo ainda pode alterar arquivos. A renomeação não concede direito de deploy, e o hash do fixture não substitui aceite/comercial Portal. Para produção serão necessários locks e versões duráveis no PostgreSQL, credenciais e IAM reais, submissões idempotentes, evidência observada de publicação, revisão AppSec/DBA e proteção de branches.

Os **dez gates de `config/operational-readiness.json` permanecem `false`**. PR em draft empilhada sobre #367, sem merge nem deploy. Issues #33–#38 e #281 não encerradas.
