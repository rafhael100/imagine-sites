# OnePage R26 — verificar briefing/direitos antes do recibo privado

Data: 2026-11-23. Escopo: **somente laboratório OnePage**, sem writer operacional, deploy, API, identidade ou aceite real. Base exata: Sites #629 (`113c76caf5f9731126b056ded97944ea196ba73b`). Portal #1099 (`16b225a464422570e07430e900275bfcd5813970`) e AI Agents #490 (`8d9e78b2d359ba4e1f8cc74bf4328734dda507df`) são apenas referências de fronteira; Landing Page é independente.

## Falha material

`OnepageExactFinalAcceptanceReadCandidate::observe()` lia `currentAcceptance` (recibo privado do clique do comprador) imediatamente após o release, **antes** de chamar `OnepageBuyerPrivatePreviewCandidate::render()`. Esse último é o componente já existente que nega comprador errado na mesma empresa, direitos retirados, revisão revogada e divergência dos bytes do briefing. A negativa final do aceite não impedia o efeito da primeira leitura confidencial do recibo.

## Alteração mínima

Somente reordenar a leitura inicial de `currentAcceptance` para **depois** da validação da prévia privada, com os bytes HTML, revisão e SHA-256 atuais; preservar o parser, renderizador, recusa uniforme, revalidações R21–R25 e os marcadores explícitos de `publicationAuthorized=false`/`canPublish=false`. Não instala adaptadores nem concede poderes à prévia.

`tests/onepage-receipt-before-preview-r26.php` reaproveita exatamente o pedido e briefing sintéticos R04/R25, verifica contadores por porta e ausência de saída. Cobre direitos retirados, aprovação revogada, bytes adulterados e segundo comprador no mesmo tenant (todos com 0 leituras de recibo e apresentação), além do caminho válido, que ainda faz 2 releituras independentes e não autoriza publicação.

## Evidências de execução e limites

- **Vermelho antes da correção:** Actions [45192786277](https://github.com/rafhael100/imagine-sites/actions/runs/45192786277), SHA `1ca05f735f78528c1ae87b4f32ecf8f0609f8952`, PHP 8.3/8.4. Falha exatamente na assertiva `no private receipt/presentation read: withdrawn-rights`; não é falha de setup.
- **Correção:** commit `e83af84bf8e582e40a8ff525ad4289e1e19d694a`.
- **CI corrigida:** consultar a SHA final do PR, sem inferir resultado pelo status de outro commit. Executar novo R26 + R25 afetado, lint PHP 8.3/8.4 e workflows gerais isolados.
- As fontes e pessoas são fixtures, não comprovação de login, aceite real, origem oficial Laravel/PG18 ou transportes atestados.
- A chamada OpenAI real continua bloqueada até identidade/worker OnePage, rota privada, modelo, egress e credencial específica já provisionados e aprovados, **sem transferir credenciais do Faísca**.
- Revisão imutável oficial, bytes e declaração de direitos confirmada pelo comprador e aprovação/revogação independente pelo revisor permanecem dependências do Portal #976. Domínio somente para publicação.
- Não houve merge, deploy, main, VPS/clone, DNS, Cloudflare, Asaas, checkout, contratação, segredos, CRM, publicação ou alteração Landing.

**Sites #38: NO-GO.** Esta correção reduz leituras privadas indevidas na candidata; não cria autorização nem atomicidade transacional.
