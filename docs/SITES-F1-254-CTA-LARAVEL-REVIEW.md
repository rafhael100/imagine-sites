# Imagine Sites F1-254 — Comparador privado de CTA secundário

Implementada uma rota Laravel de laboratório para visualizar Onepage e Landing Page com CTA secundário desativado/ativado, combinando os três temas e visualizações desktop/mobile.

- GET somente leitura, sem POST, sem API de publicação, sem dados pessoais, sem tráfego externo.
- Rota registra-se somente em CLI/testing, sob flags explícitas de laboratório e IP loopback.
- Parâmetros fechados: product, device, preset, cta; parâmetros ou tipos desconhecidos recebem 404.
- Interface existente exibe os dois SHA-256, demonstra a prévia em iframe sandbox e preserva escolha CTA ao trocar tema/produto/dispositivo.
- Dossiê de revisão e QA asseguram internal_review, aprovação do cliente pendente e publishable=false.
- Matriz PHPUnit com 24 variantes, digest do HTML exato e negativos de método, flags e rede.

Não é painel operacional ou autorização de publicação. Todos os dez gates permanecem BLOCKED.