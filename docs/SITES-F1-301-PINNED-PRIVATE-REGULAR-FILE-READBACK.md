# Imagine Sites F1-301 — leitura privada com inode fixado e limite de bytes

**13/11/2026 · Apenas CLI/testing · Sem publicação ou integração operacional**

## Risco corrigido
A F1-298 validava `is_link`, `is_file`, `filesize` e depois chamava `file_get_contents`. Uma substituição no sistema de arquivos entre essas chamadas poderia resultar em leitura de outro arquivo antes de a conferência de SHA o rejeitar. A F1-300 coordena processos **cooperativos**, mas não protege contra mudanças locais que ignoram `flock`.

## Código
- Nova classe `SyntheticPrivateRegularFileReadV1::read(path,maxBytes)`: só aceita arquivos regulares com um link físico, confere device/inode/tamanho antes da abertura e depois do `stream_get_contents` limitado a `maxBytes + 1`; rejeita symlinks, hardlinks, vazio, tamanho excessivo, mudanças de inode/tamanho e leituras incompletas.
- Writer do pacote sintético usa a leitura por descritor para conferir os dois arquivos em `staging` antes da promoção atômica (F1-297/F1-300).
- Verificador independente F1-298 também usa o mesmo leitor com limite de **150.000 bytes para HTML** e **8.192 bytes para manifesto**, sob `flock` do produto.
- Testes offline PHP verificam arquivos válidos, symlink, hardlink, tamanho, ausência e leitura exata. Testes Laravel substituem arquivos do pacote por links ou manifesto excessivo; devem negar e recuperar a leitura válida. CI F1-24 e suíte sintética rodam PHP 8.3/8.4.

## Limites
A evidência é de **integridade local de laboratório**. Ainda exige pin de digest autenticado, identidade e revogação atuais do Portal, atomicidade distribuída de PostgreSQL, proteção de branches, revisão independente e observação real da Cloudflare antes de qualquer serviço de produção. Não existe aprovação cliente, banco de clientes, rede real, upload, cobrança, DNS ou deploy. `config/operational-readiness.json` continua `BLOCKED` e os dez critérios falsos.
