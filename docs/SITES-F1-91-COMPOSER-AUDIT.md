# F1-91 — Auditoria automatizada de dependências Composer com lockfile

O repositório `imagine-sites` possui `apps/sites/composer.json` e `apps/sites/composer.lock`, com PHP 8.3+ e Laravel. O sucesso dos testes Laravel/HTML/Docker não demonstra que as versões travadas das bibliotecas estejam livres de vulnerabilidades **conhecidas**.

Esta etapa acrescenta um workflow GitHub Actions sem deploy, com token de leitura e PHP 8.4, que executa:

1. `composer validate --strict --no-check-publish`, verificando consistência do manifesto e lockfile;
2. `composer audit --locked --abandoned=report --format=plain`, examinando o conjunto completo de dependências de produção e desenvolvimento;
3. `composer audit --locked --no-dev --abandoned=report --format=plain`, auditando separadamente as dependências destinadas ao ambiente operacional.

O comando `composer audit` usa a base de advisories disponível ao Composer, **sem instalar pacotes adicionais, sem atualizar versões e sem permitir exceções automáticas**. Pacotes abandonados são relatados como observação, mas vulnerabilidades conhecidas ou impossibilidade de consultar as fontes de segurança devem falhar o job. O workflow é disparado por alterações no manifesto, no lockfile ou em sua própria definição. A execução inicial é solicitada pela abertura deste PR.

**Limites**: a consulta avalia apenas advisories publicados no momento da execução; não substitui SAST, revisão independente de arquitetura, análise de código próprio, avaliação de licença, varredura de imagens Docker, gestão de secretos ou testes de penetração. Nenhum token do cliente, credencial de produção, dado pessoal ou servidor Leaseweb é utilizado. A aprovação de release Cloudflare e os 10 gates operacionais permanecem BLOCKED. Uma dependência potencialmente vulnerável exige análise e atualização controlada do lockfile; não deve ser ignorada para "ficar verde".
