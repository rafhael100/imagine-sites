# F1-285 — Comparação do link de acessibilidade na interface Laravel

A equipe Imagine passa a ter uma tela privada de revisão que compara o link de acessibilidade “Pular para o conteúdo” ativado e desativado em Onepage e Landing Page.

- A rota `/_lab/sites/v1/skip-link-review` aceita exclusivamente quatro opções tipadas de listas fechadas: produto, dispositivo, tema e estado do link.
- Exige execução in-process CLI/testing, três flags de laboratório, IP loopback e método GET. Parâmetros extras, arrays, valores inesperados e execução sem autorização de laboratório retornam 404.
- Gera dois rascunhos fictícios usando o motor PHP real; os briefings são byte-a-byte equivalentes exceto o booleano editorial do link.
- Confere os quatro arquivos privados de ambas as versões, HTML, digest, manifesto, QA e estado pendente de revisão antes de renderizar qualquer prévia.
- O painel exibe os dois SHA-256, o digest do par de pacotes e a prévia num iframe sandbox sem scripts, formulários ou rota de publicação.
- A opção é preservada ao alternar desktop/mobile. Os testes percorrem 24 visualizações e garantem regressão independente dos revisores de FAQ e CTA.

Nenhuma ação aprova o cliente, integra CRM, publica, expõe dados privados ou altera a infraestrutura. Dez gates operacionais permanecem `BLOCKED`.