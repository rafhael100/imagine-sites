# F1-170 — Revisão Laravel com etapas e comparação das versões v3/v4

O novo bloco "Como funciona" da F1-169 passa a ser conferível pela equipe Imagine no visualizador Laravel. A rota existente de laboratório, /_lab/sites/v1/rich-compare, mantém as variantes v3 já conhecidas (Somente FAQ, Somente serviços, FAQ e serviços) e adiciona duas composições sintéticas v4: **Serviços e etapas** (sem FAQ) e **Página completa** (serviços, FAQ e etapas).

O conteúdo de etapas é fixado no repositório em fixtures/sites/f1-170-process-copy.synthetic.json. Os novos modos exigem o contrato sites.ai-copy.v4, com três etapas, e geram releases/hash SHA-256 distintos. Não há entrada de texto livre, upload, edição de dados pessoais, endpoint de CRM ou botão de publicação. A rota continua restrita a CLI/testing/loopback com as flags explícitas de revisão.

A interface de revisão mostra a quantidade de etapas e mantém os seletore de tema, produto, dispositivo e composição. Os modos antigos preservam seus bytes de prévia v3 e a rota /_lab/sites/v1/rich-review permanece sem o comparador. Toda mudança de conteúdo exige uma nova versão de revisão.

O teste Laravel F1-170 executa 2 produtos × 3 temas × 2 novas composições × 2 dispositivos, confere hash exato da prévia em iframe sandbox, lista numerada, ausência de scripts/formulários, headers no-store/noindex/CSP, compatibilidade v3 e bloqueio de parâmetros ou IP externo. PHP 8.3/8.4 no GitHub Actions.

Nenhum site real, dado de cliente ou transferência para o CRM foi ativado. OP01/OP02/OP04 e 10/10 gates de produção permanecem bloqueados. PR draft, sem merge em dev/main, VPS, deploy ou despesas.
