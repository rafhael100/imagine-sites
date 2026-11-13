# F1-157 — Perguntas frequentes opcionais em Onepage e Landing Page

O motor StaticSiteRenderer passa a renderizar uma seção de **Perguntas frequentes** opcional, estruturada, acessível e totalmente estática. Isso aumenta a capacidade funcional dos templates sem habilitar formulários, JavaScript, links externos, analytics ou captura de leads.

- SiteBrief aceita até seis pares question/answer, de até 180/900 bytes, em texto UTF-8, com verificação de duplicatas e formatos de entrada. O texto é escapado no HTML.
- O componente usa section com aria-labelledby, título h2, lista e h3 para cada pergunta, sem componentes executáveis.
- As prévias sem FAQ continuam **byte a byte iguais** às versões anteriores. O estilo CSS adicional só aparece quando há FAQ; todas as três paletas e ambos os produtos são suportados.
- InertPreviewAudit reconhece somente o novo elemento fixo h3, mas mantém as restrições de scripts/formulários/rede; SyntheticPreviewQaReport mantém as verificações semânticas.
- Revisões de **estilo** preservam as FAQs já existentes; alterações da FAQ durante revisão de **conteúdo** exigem novo artefato, hash e aprovação interna, sem aproveitar o aceite anterior.

A suíte de testes roda em PHP 8.3/8.4 em GitHub Actions, valida hashes, HTML inerte, navegação semântica, compatibilidade com previews antigos, entrada malformada e invalidação do ciclo de aprovação. Trata-se de prévia privada demonstrativa com conteúdo fictício, não um site publicado ou integração de consentimento/CRM. OP01/OP02/OP04 e os dez gates operacionais continuam bloqueados; nenhum merge em dev/main, deploy, VPS, dado real ou novo custo.
