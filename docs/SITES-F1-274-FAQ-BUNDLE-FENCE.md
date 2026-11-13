# F1-274 — Proteção estrutural da FAQ no pacote de release

A inclusão de HTML sem JavaScript `<details>/<summary>` na F1-270 foi acompanhada por QA DOM, mas o componente independente de empacotamento depende também do auditor básico `InertPreviewAudit`. Esta entrega exige que **ambos** neguem uma FAQ deslocada ou adulterada.

- A FAQ expansível precisa estar exclusivamente na seção canônica `faq` e possuir de uma a seis linhas de estrutura estrita `<li><details><summary>pergunta</summary><p>resposta</p></details></li>`.
- As contagens de abertura e fechamento das tags têm que coincidir, inclusive no caso de fechamento solto sem abertura.
- A FAQ não pode misturar linhas expansíveis com títulos estáticos ou inserir tags adicionais dentro de summary/answer.
- Os estilos acessíveis para resumo e foco precisam estar presentes exatamente uma vez.
- O auditor rejeita abertura automática (`open`), atributos extras, linhas deslocadas para o rodapé, tags aninhadas, parágrafos extras e alteração do foco.
- Para cada alteração, os testes criam novo HTML, recomputam hash e manifesto e exigem que **a criação do pacote de release continue negada**.

Os HTMLs válidos gerados pelo motor, tanto o modo antigo como o expansível, continuam aceitos. Isto é um controle de integridade de laboratório, não autorização, identidade, aprovação humana ou antissanitização universal. Os dez gates operacionais permanecem `BLOCKED`.