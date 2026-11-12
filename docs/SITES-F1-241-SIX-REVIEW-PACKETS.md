# F1-241 — revisão do Imagine Sites distribuída em seis pacotes verificáveis

A auditoria completa F1-240 revelou **597 caminhos alterados** em relação à baseline `dev` fixada, dos quais **172** demandam revisão independente por envolverem código/infraestrutura sensível. A saída não pode ser auditada adequadamente usando os primeiros 300 itens da API do GitHub.

O script `ci/f241_six_integration_review_packets.py` consome o **JSON completo e verificado** do F1-240. Antes de gerar relatórios ele reconfere SHA-256 e campos da origem, número total de arquivos, contagem de caminhos de risco e flags `goLiveApproved=false`, `deploymentPerformed=false` e dez gates bloqueados. Em seguida, classifica **todos os caminhos, exatamente uma vez**, nos lotes abaixo:

| Lote | Escopo principal |
| --- | --- |
| A-render-visual | Motor estático, views, fixtures, layout, SEO inerte, UX |
| B-db-security | Migrações/RLS, segurança, persistência, rotas, Laravel app |
| C-integrations | Contratos/versionamento e integração candidata Portal–Sites–CRM |
| D-infra-ci | Docker/Nginx/PHP-FPM, configuração, CI, workflows, gates |
| E-testing-labs | Testes negativos, ambientes/labs e regressões |
| F-docs-other | Documentos de requisitos, governança e demais arquivos |

Cada pacote Markdown lista caminho, operação Git (A/M/D/T), sinalização de revisão sensível, SHAs da base/head, resumo de quantidade e critérios mínimos de revisão. Um `review-packets.json` relaciona seis arquivos e seus SHA-256 individuais com o digest do inventário F1-240, garantindo que nenhuma categoria ou arquivo se perca na preparação.

O workflow `sites-f1-241-integration-review-packets.yml` refaz o inventário com checkout do SHA real do PR, valida a base `dev`, testa o classificador, publica o artefato **`imagine-sites-six-code-review-packets`**, com retenção de 7 dias, e remove os arquivos do runner após o upload. O inventário não contém código-fonte embutido nem credenciais: somente metadados de caminhos já existentes no Git.

**Não é revisão executada nem autorização humana.** Os pacotes permitem que responsáveis por AppSec, DBA, Portal/CRM, arquitetura, acessibilidade e engenharia registrem findings em um trabalho rastreável. A avaliação humana, a resolução dos achados, o merge em `dev`, a integração real e qualquer deploy continuam dependentes dos gates do repositório. A `main` permanece inalterada.

## F1-258 — renovação de seis lotes independentes (12/11/2026)

Os seis lotes precisam ser gerados novamente a partir do inventário F1-240 do SHA exato do candidato F1-258, não dos pacotes F1-239/246/251. Revisar motor/CTA, Laravel, segurança, contratos, banco/RLS, cadeia CI e documentação, com reviewers AppSec, DBA, Portal e CRM. Pacotes de CI são evidência de diff, **nunca** revisão aprovada. Dez gates permanecem BLOCKED e as branches `dev/main` não devem ser alteradas por este relatório.

## Renovação F1-263 — 12/11/2026

Gerar novamente os seis pacotes de revisão no mesmo SHA do candidato F1-263 com os hashes provenientes do F1-240 novo. Destinar para exame independente, com prioridade nas classes de privacidade de contato, contrato CRM, integridade de revisão, segurança de rotas e CI. Resultados de testes antigos não substituem revisão humana; dez gates de produção permanecem fechados.

## Renovação F1-265 — 12/11/2026

Regenerar seis pacotes usando o SHA exato do inventário F1-240 da nova branch de integração. Incluir auditoria AppSec independente do módulo `ops/cloudflare-worker`, audiência `.invalid`, verificação de relógio/assinatura, política de negação e CI Node 22; não confundir assinatura de fixture com autoridade real do Portal. Sem merge, publicação ou liberação de gates.

## Atualização F1-267 — 12/11/2026

Gerar novamente seis lotes de revisão a partir do inventário F1-240 do mesmo HEAD; atualizar revisões AppSec de comandos de deploy, Worker, privacidade, RLS/CRM e Docker. Inventários CI são evidência, não aprovação para merge, domínio, produção ou uso de fornecedores.

## Revisão F1-269 — 12/11/2026

Gerar seis pacotes independentes a partir do mesmo SHA do F1-240 atualizado. Adicionar revisão técnica de assinatura Base64URL, expiração, nonce efêmero, replay, Worker permanentemente bloqueado e restrições de CI. Não tratar hashes ou simulações como aceite real do cliente, identidade S2S ou aprovação dos dez gates.
