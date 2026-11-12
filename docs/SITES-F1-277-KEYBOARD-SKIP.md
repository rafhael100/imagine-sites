# F1-277 — Link opcional de teclado para o conteúdo principal

O Imagine Sites agora oferece uma opção editorial explícita para colocar **Pular para o conteúdo** no início das prévias privadas, tanto Onepage quanto Landing Page. A primeira tabulação pode chegar ao link, que aponta exclusivamente ao `#principal` gerado pelo motor.

- Código e conteúdo fixos, sem links arbitrários, JS, rede ou formulários; nunca controlado pela cópia enviada por IA.
- CSS oferece alvo legível e foco contrastante, permanecendo visualmente recolhido até o foco de teclado.
- Quando desativado, os bytes e o digest histórico permanecem idênticos. Quando ativado, é exigido um novo SHA-256 e um pacote privado inteiramente verificado.
- O auditor de HTML aceita somente o link fixo, único e antes do cabeçalho, com estilo de foco obrigatório; duplicação, atributo ativo, alteração do destino ou mudança da posição falham.
- Os fluxos de revisão de tema, FAQ, SEO, seção e conteúdo preservam a escolha, e edições normais de cópia não podem alterar seu estado silenciosamente.
- Testes PHP verificam dois produtos, três estilos e quatro paletas com casos adversariais e nova revisão pendente.

**Sem homologação de WCAG e sem produção.** A opção não concede autoridade ao Portal, publicação, captação ou serviço. Dez gates continuam BLOCKED.