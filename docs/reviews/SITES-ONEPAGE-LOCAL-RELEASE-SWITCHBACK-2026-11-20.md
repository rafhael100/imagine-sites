# OnePage — troca e reversão explícita de release com leitura HTTP 127.0.0.1 (20/11/2026)

## Reuso e diferença do ensaio anterior

- Continuação **exclusiva OnePage** da PR #573 (base congelada `449a118bbeb859ee8b1c9ab57e08c4b7bd0c60b7`), depois de #572. Histórico #541/#542; Landing Page #569 intacta.
- Reaproveita a máquina de estados da [issue Sites #9](https://github.com/rafhael100/imagine-sites/issues/9), `SyntheticPublishCoordinator`, `PublicationOperation`, `SyntheticOriginRegistry` (F1-16) e `LocalSiteRendering`. **Não** reimplementa parser, contrato S2S, verificadores de assinatura, prévia privada ou aceite.
- F1-16 já prova ACK ≠ verificação e calcula digest no registro em memória, mas **não comprovava a alternância e reversão explícita com leitura independente dos bytes realmente servidos** por HTTP em destino local. O teste novo preenche somente esta lacuna.

## Prova isolada

A fixture rende duas versões HTML OnePage do renderer existente, A (última aprovada fictícia) e B (revisão fictícia). Inicializa um servidor PHP estático no **127.0.0.1 com porta efêmera**, servindo um arquivo `current.html` sob diretório temporário privado `0700`; arquivo `0600`. A observação faz GET HTTP independente e **calcula SHA-256 dos bytes recebidos**.

1. Confere SHA A diretamente pela resposta HTTP e pelo origin F1-16.
2. Simula pedido/dispatch/ACK de B **sem trocar o arquivo**. A segue servida; F1-09 termina `inconclusive` para B, preservando a referência observada anterior.
3. Simula uma troca local explícita do arquivo com `rename` atômico; comprova SHA B realmente servido; apenas então a operação fictícia alcança `verified` técnico, depois de comparar o digest com as leituras independentes.
4. Corrompe os bytes servidos com o registro em memória ainda apontando B: a leitura HTTP detecta a divergência, e a operação não é verificada.
5. Repõe B, mostra que não houve rollback automático, e só depois de ação explícita simulada reverte para a **A anteriormente aprovada**, verificando SHA A no HTTP e no origin. O estado técnico simulado não registra aceite, entrega nem publicação operacional.

Não se trata de URL pública, rede externa, Cloudflare, backup restaurado, ACK do provedor, domínio, homologação ou publicação real. Não cria autorização de rollback real nem mecanismo operacional de promoção. Um `PolicyDecision(true)` neste ensaio é **fixture inerte**, não comercial, financeira ou humana. Diretório, HTML e logs do servidor são apagados ao final do job; nenhum token, DNS ou serviço é instalado.

## Bloqueios e próximo passo

O teste não conecta a prévia privada em HTTP #486 ao ledger do pedido/aceite #573, nem fornece produtor oficial do Portal #976 ou autenticação S2S #1003/#552. Falta revisão/aceite real do cliente, domínio para futura publicação, permissionamento do publisher, ledger de release/rollback e observador operacional externo com rastreabilidade.

Manter gates da [Sites #38](https://github.com/rafhael100/imagine-sites/issues/38) e os dez gates de homologação **NO-GO**. Nada em main, VPS, produção, Asaas/checkout, credenciais, DNS, Cloudflare, Replit/API ou fornecedores. A trilha Landing Page não herda nenhuma destas evidências. CI por SHA exata deve ser conferido antes de declarar sucesso.
