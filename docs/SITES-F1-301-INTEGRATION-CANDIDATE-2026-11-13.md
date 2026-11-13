# Imagine Sites — candidato de revisão F1-301

**13/11/2026 · DRAFT / NO-GO · base dev fixa SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`**

Este candidato atualiza a integração #367 (F1-293–299) com:

- **F1-300 / #368:** exclusão mútua cooperativa entre geração e verificação de pacotes privados, arquivo `.lock` persistente por produto, sem bloqueio indefinido e sem sobrescrever saídas existentes.
- **F1-301 / #369:** leitura privada com descritor limitado, controle do inode/dispositivo/links antes e depois da leitura, recusa de symlink, hardlink e tamanho indevido.
- Testes negativos independentes PHP 8.3/8.4, suíte Laravel, segurança de rotas e conteúdo, Docker isolado, sem qualquer serviço, cliente, credencial ou deploy real.

## Critérios do candidato
1. F1-240 deve enumerar **todos** os arquivos alterados no Git (não confiar no limite REST de 300) em relação a `dev` inalterada, confirmar SHA e os dez bloqueios de produção.
2. F1-241 deve criar seis pacotes de revisão, com hash individual e cobertura exata do mesmo inventário. Conferir riscos AppSec de filesystem, concorrência, readback, autenticação Portal e governança.
3. Revalidar todas as suítes no SHA exato deste candidato, inclusive F1-300/301, navegador, PostgreSQL 18 descartável e Nginx/PHP-FPM isolados. A aprovação de CI é evidência de testes, não parecer de segurança.
4. **Não alterar `dev`/`main`**, não habilitar Worker/Cloudflare, CRM, DNS, Leaseweb, chaves, clientes, PII, pagamentos, automações externas ou release operacional.

O estado `config/operational-readiness.json` segue `BLOCKED` e os dez gates `false`. Issues #281 (branch protection/reviews), #33 (S2S Portal), #34 (banco/revogação), #35 (publicação real), #36 (CRM/contingência), #37 (AI Agents) e #38 (homologação) são bloqueios externos válidos. O merge só pode ser reavaliado mediante revisão independente e proteções administrativas.
