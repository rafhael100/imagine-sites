# F1-272 — Comparação visual de FAQ expansível no Laravel

Uma rota de revisão privada do Imagine Sites, `/_lab/sites/v1/faq-disclosure-review`, agora permite comparar FAQ tradicional e expansível nos dois produtos, três estilos e desktop/mobile.

- A interface mantém a seleção de modo ao navegar; não há formulário, editor aberto ao cliente ou entrada de conteúdo arbitrário.
- A rota exige execução local CLI/testing, loopback, flags isoladas e a consulta completa exata de quatro parâmetros de listas fechadas.
- Antes de renderizar, gera dois rascunhos independentes com a mesma cópia sintética; a única diferença de briefing é a opção editorial de FAQ.
- Confere os quatro arquivos privados de cada versão, integridade do manifesto, digest exato, auditoria HTML, acessibilidade estrutural e estado de revisão pendente.
- Apresenta os dois SHA-256, o digest pseudonimizado de comparação dos arquivos e o novo HTML em `iframe sandbox` no modo selecionado.
- Testes verificam 24 visualizações e rejeitam parâmetros extras, arrays, mutação, rede externa, flags desativadas e métodos de escrita. Regressão do comparador CTA permanece obrigatória.

Este revisor não concede aceite, assinatura, permissão CRM, publicação Cloudflare ou qualquer autorização de go-live. Os dez gates continuam BLOCKED.