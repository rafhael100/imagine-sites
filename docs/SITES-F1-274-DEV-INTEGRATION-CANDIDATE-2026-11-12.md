# Imagine Sites — candidato de integração F1-274 (12/11/2026)

**PR DRAFT exclusivamente para revisão em `dev`, sem merge, deploy, clientes, Cloudflare ou outras operações.**

## Origem e base fixa

- `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; `main` SHA `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`. Ambas reportavam `protected:false` na API.
- Origem F1-274 (PR #321), 1.162 commits à frente de `dev`, zero atrás antes desta documentação.
- Candidato #320 F1-273 concluiu **105/105 workflows com sucesso**, F1-240 inventariou **693 arquivos e 195 caminhos sensíveis**, F1-241 dividiu-os em seis pacotes. Os números pertencem àquele HEAD e NÃO substituem a nova auditoria.

## Novos recursos e defesa

- F1-270 a F1-273: FAQ expansível sem JavaScript, troca editorial que revoga aprovação, comparador privado Laravel e teste de teclado/viewport em Chromium real (PRs #316 a #319).
- **F1-274 / #321:** o auditor básico que permite a criação de `SyntheticReleaseBundle` também exige que todas as tags details/summary estejam em linhas de FAQ canônicas, dentro da seção correta, com estrutura, fechamento e foco apropriados. Rejeita mutações reempacotadas com SHA-256 recalculado. O QA DOM independente permanece obrigatório.

## Checks deste candidato

1. Executar **todas** as matrizes PHP 8.3/8.4, Laravel, PostgreSQL 18, Docker, Chromium, Node e segurança neste HEAD.
2. Recalcular inventário Git integral F1-240 contra `dev` fixo; não usar o limite de 300 arquivos da API compare.
3. Regenerar F1-241 com seis pacotes do inventário novo e encaminhar para revisão independente AppSec/DBA/Portal/CRM/frontend.
4. Preservar dez gates operacionais `BLOCKED`; não aprovar por CI ou assinatura sintética.
5. Ativar proteção de branches e obter revisores externos (issue #281) antes de considerar qualquer merge.

## Fora de escopo

Sem clientes reais, endpoint público, formulários, consentimento operacional, CRM real, publicação, DNS, VPS, credenciais, custos, IAM, homologação ou evento ENTREGUE. Apenas desenvolvimento e testes privados fictícios.