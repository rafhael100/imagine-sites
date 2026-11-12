# F1-279 — Comparador visual do acesso direto ao conteúdo no Laravel

Foi criada uma rota privada de laboratório `/_lab/sites/v1/skip-navigation-review` para os revisores compararem o HTML gerado com e sem o link **Pular para o conteúdo**. A opção é editorial, nunca controlada pelo visitante ou por dados gerados pela IA.

- A rota exige CLI/testing, loopback e três flags específicas. Recebe apenas quatro parâmetros de lista fechada: produto, estilo, dispositivo e modo `skip`.
- Produz dois rascunhos ficcionais independentes com a mesma cópia, e confirma que a única diferença entre os briefings é a opção de teclado.
- Confere os quatro arquivos privados e seus resumos, hash SHA-256, estado de revisão interna pendente e HTML inerte antes de renderizar.
- O revisor pode alternar os modos em desktop/mobile. A interface exibe ambos os digests e um digest de comparação dos pacotes, sem botão de aprovação ou publicação.
- Uma matriz de 24 prévias Laravel e testes negativos cobre URLs e flags inesperadas, cliente externo, tentativa POST, referência modificada e vazamento dos controles a revisores antigos.

Esta funcionalidade não cria editor self-service, não prova identidade, não aceita cliente e não interage com CRM, Cloudflare, domínio ou dados reais. Dez gates operacionais continuam BLOCKED.