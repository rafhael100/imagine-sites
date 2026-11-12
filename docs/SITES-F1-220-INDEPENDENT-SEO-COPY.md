# F1-220 — Título e descrição SEO independentes da página

A Onepage e a Landing Page podem receber `seoTitle` e `seoDescription` opcionais escolhidos pela equipe Imagine, sem alterar o H1, o hero nem o texto dos benefícios. Quando os campos não são informados, todo HTML legado permanece byte a byte idêntico. Quando informados, os metadados SEO e os cartões OG/Twitter opcionais utilizam os mesmos textos editoriais.

A validação exige UTF-8, título até 160 bytes, descrição até 360 bytes e recusa controle, HTML bruto, URLs, esquemas executáveis e espaços externos. A renderização escapa os valores. Não existe URL pública, imagem remota, formulário, script ou permissão de indexação.

O contrato de IA v1–v4 não recebe esses campos. Eles entram apenas por parâmetros separados e tipados de SyntheticStructuredDraftAssembly. Os planos de alteração de tema, paleta, ordem das seções, menu, social e texto comum devem preservá-los; o plano de edição comum rejeita mudanças ocultas nos metadados. Uma mudança SEO explícita exige outra revisão independente.

A suíte principal PHP 8.3/8.4 executa F1-220 com 12 combinações, compatibilidade dos bytes antigos, distinção entre head e H1, cartões OG/Twitter, readback privado, preservação nos planos, entradas adversariais e negação de comandos forjados pela IA. A revisão permanece fictícia, pending e noindex. Não consulta Google, não alimenta Imagine SEO Engine nem CRM, não publica nem aprova cliente. OP01/02/04 e dez gates de produção seguem bloqueados, sem merge/deploy/custos.
