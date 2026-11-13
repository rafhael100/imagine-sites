# F1-261 — Comparação visual de CTA secundário com paleta no Laravel

A tela privada de revisão do Imagine Sites agora permite visualizar diretamente o CTA secundário nas paletas **Padrão, Oceano, Ameixa e Floresta**, em Onepage e Landing Page nos três estilos.

- O seletor possui lista fechada de quatro códigos. Texto, URL ou CSS da IA/cliente não entram na seleção.
- O modo opcional `palette` mantém as URLs anteriores (sem parâmetro) intactas; cada combinação recebe identidade de release própria quando explicitamente selecionada.
- A escolha permanece na navegação entre CTA ligado/desligado, produto, tema, desktop e mobile.
- Antes de exibir, a rota revalida os dois hashes, quatro arquivos privados, auditoria HTML, estado pendente e ausência de autoridade de publicação.
- 96 visualizações testadas em Laravel PHP 8.3 e 8.4, mais regressão anterior sem paleta e negativos de flags, parâmetro e rede.

Laboratório somente leitura, isolado por CLI/testing e loopback, sem clientes reais, formulários, deploy, assinatura, CRM ou provedor. `dev` e `main` permanecem intactas; dez gates ainda BLOCKED.