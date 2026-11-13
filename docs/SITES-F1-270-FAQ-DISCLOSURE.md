# F1-270 — FAQ expansível nativo, sem JavaScript

Imagine Sites agora suporta uma escolha **editorial e opcional**: exibir perguntas e respostas dentro de `<details><summary>...</summary>...</details>`, usando o comportamento nativo do navegador. O cliente continua sem editor self-service.

Quando o recurso não é ativado, o HTML histórico e seus digests SHA-256 continuam idênticos. A opção é aceita somente do fluxo de revisão tipado, não do conteúdo AI. O layout exige pelo menos uma FAQ válida, e as perguntas permanecem plain text escapado, sem links ou scripts.

A auditoria aceita elementos HTML `details` e `summary` somente sem atributos; a verificação DOM exige que estejam apenas no bloco de FAQ, que o item tenha exatamente um summary e um parágrafo, sem aninhamento e sem conteúdos interativos extras. Há estado inicial fechado e estilo de foco visível para teclado.

As alterações em tema, ordem, SEO e texto editorial preservam a escolha e reabrem revisão; a revisão de copy não consegue ativar ou desativar a opção por acidente. A matriz testa 24 combinações entre os dois produtos, três estilos e quatro paletas, incluindo HTML adulterado e ausência de FAQ.

Todo artefato continua privado, `noindex`, com release hash específico e sem captação de dados. Não equivale a aprovação do Portal nem a uma publicação. Os dez gates de produção seguem `BLOCKED`.