# F1-73 — Perfil Docker isolado para futuros testes na VPS Leaseweb

**Estado: preparado em código; NÃO executado nem homologado.** A VPS ainda não está conectada a esta conversa. Este perfil foi criado para facilitar a futura validação em ambiente de desenvolvimento Ubuntu 24.04, preservando a separação dos projetos Imagine.

## Estrutura

- `ops/staging/Dockerfile`: PHP 8.4 **CLI**, Composer, extensões PostgreSQL/mbstring/DOM, usuário não privilegiado UID 10001, execução única de `php artisan test`; sem web server nem `EXPOSE`.
- `ops/staging/compose.yml`: serviços opcionais com `profiles: ["lab"]`, PostgreSQL 18 descartável, rede `internal: true`, **nenhuma porta publicada no host**, `restart: no`, sem Cloudflare/DNS/API pública. O password do banco é exigido por variável local `SITES_LAB_DB_PASSWORD`, **não é cadastrado no código**.
- `ci/f73_staging_lab_contract.py`: verificações estáticas e mutações negativas no job F1-21, impedindo adição evidente de portas, host networking, permissões privilegiadas ou serviços de produção. **Não substitui análise do YAML por Docker, revisão de segurança, auditoria de imagem ou prova de rede.**

## Futuro procedimento manual (apenas após acesso autorizado)

1. Confirmar VPS Leaseweb correta, conta Linux não privilegiada, versões Docker/Compose, políticas de segurança e cotas; não liberar acesso público para os serviços deste perfil.
2. Configurar a variável de ambiente de senha aleatória e efêmera **somente** na sessão segura da VPS/secret manager. Nunca enviar senha, token, certificado, `.env` ou backup ao GitHub público ou chat.
3. A partir da raiz clonada de branch autorizada, inspecionar a configuração renderizada com `docker compose -f ops/staging/compose.yml --profile lab config --quiet`, criar o build de laboratório e rodar `docker compose -f ops/staging/compose.yml --profile lab run --rm sites-lab-tests`. Nenhuma publicação está incluída.
4. Depois da coleta de resultados sintéticos, remover o banco descartável com `docker compose -f ops/staging/compose.yml --profile lab down -v` e destruir a variável de ambiente efêmera.
5. Registrar hashes da revisão, matriz PHP/PG, logs sem PII, falhas e parecer de revisão. **Nenhum resultado daqui autoriza produção** ou substitui os testes de recuperação e revogação entre bancos.

## Limitações abertas

A imagem não foi construída/executada em Docker nesta tarefa; tags de imagens devem ser fixadas por digest e submetidas a inventário/vulnerabilidades antes da homologação. Dependências externas de build podem necessitar acesso a registries e gerar consumo de rede, sujeito à aprovação de custo. O stack atual não é servidor HTTP; não implementa API real nem hospedagem. O banco é **somente de laboratório** e não equivale ao PostgreSQL operacional exigido por OP02.

**Pendências sem alteração:** SITES-OP01..05, SITES-GATE #38, identidade Portal, backups/restore, domínio técnico, Cloudflare, CRM real, AppSec/LGPD e 10 gates `BLOCKED`. Sem deploy, criação de VPS, alteração de DNS, dados pessoais, merges ou novos custos autorizados automaticamente.
