# F1-74 — Restringir contexto de build Docker e validar Compose renderizado

**Apenas laboratório, sem deploy nem homologação operacional.**

## Problemas reais corrigidos

1. O perfil F1-73 usava como contexto de build a raiz do repositório, sem uma política `Dockerfile.dockerignore` específica. Isso poderia transmitir arquivos de configuração local ou dados gerados ao daemon Docker durante o build. O novo `ops/staging/Dockerfile.dockerignore` usa **deny-by-default**, permite apenas a árvore de aplicação e os diretórios de código/fixtures necessários e nega novamente `.env*`, Git, chaves, credenciais, dependências e storage, depois das exceções.
2. No Dockerfile anterior, `composer install` podia executar scripts de descoberta do Laravel **antes** de criar `bootstrap/cache` e `storage/framework`. A ordem foi corrigida, e o container continua sob UID/GID 10001, sem listener HTTP.

## Testes de regressão

- `ci/f74_docker_lab_boundary.py` verifica a sequência exata de regras do ignore, as cópias explícitas, a ordem das pastas de runtime e a execução sob usuário não privilegiado, com mutações negativas.
- O workflow F1-21 agora executa **`docker compose config --format json` sem iniciar containers**, passando a saída por uma auditoria semântica em Python. Confere perfis opt-in, inventário exato de dois serviços, rede interna, ausência de portas/host networking/serviços privilegiados, dados em volume nomeado e variáveis de senha de teste.
- A senha presente no CI é um placeholder público, exclusivamente fictício. A configuração renderizada é transmitida por pipe e **não é registrada no log**.

## Limitações

As regras do ignore e a análise semântica oferecem prevenção, mas **não constituem teste do Dockerfile com imagem executada nem análise formal de todas as variações de Docker Compose**. A execução real de build e a prova de isolamento por rede continuam dependentes da infraestrutura Leaseweb habilitada, ou de outra sandbox controlada, com credenciais temporárias fora do repositório. Tags e imagens deverão ser fixadas por digest e avaliadas antes de qualquer homologação.

Não há alteração em DNS, Cloudflare, pagamento, Portal, CRM, políticas de publicação, aceites do cliente ou `dev/main`. Os dez gates permanecem `BLOCKED`. PR em rascunho dependente da linha F1-73 (#95 e consolidado #96).
