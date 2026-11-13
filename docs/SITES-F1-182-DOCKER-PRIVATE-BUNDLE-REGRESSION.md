# F1-182 — Regressão de testes do pacote privado no Docker

Após F1-181, a execução completa em `Sites F1-83` identificou três falhas em `SyntheticEditedPrivateBundleTest` que não apareciam na matriz PHP 8.3/8.4 do F1-174:

- O container `sites-lab-tests` não recebia o sinal `GITHUB_ACTIONS=true` do runner, portanto o comando CLI de laboratório era corretamente negado (mesmo com as flags configuradas dentro do teste).
- A imagem Docker, executada como usuário 10001:10001, não contém a pasta `storage/app/private` antes do teste do symlink, então a fixture tentava criar um arquivo em um diretório inexistente.

Correções: a Compose repassa `GITHUB_ACTIONS: ${GITHUB_ACTIONS:-false}` **somente como valor do ambiente host**, sem habilitar CI por padrão em instalações locais. O contrato estático F1-73 passa a exigir esta forma e tenta alterá-la para `true` como teste negativo. O `setUp` dos testes F1-174 cria a raiz de armazenamento privada usando modo `0700`, nega symlink e não depende de uma execução anterior ou de permissões de root.

Limites de segurança: o nome GITHUB_ACTIONS é apenas um **sinal de laboratório** e não prova identidade confiável, credenciais ou autorização do cliente. Docker continua sem listener, com rede interna, UID não-root e banco fictício isolado. A implementação não cria serviço de coleta, CRM operacional ou release publicável. Nenhuma mudança de deploy, VPS, DNS, Cloudflare ou `main`.

Evidência original: [F1-83 falhou na execução completa](https://github.com/rafhael100/imagine-sites/actions/runs/43018953252). A homologação desta correção deve conferir novamente a execução Docker F1-83 e a matriz específica F1-174.
