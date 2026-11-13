# F1-275 — Seção FAQ com estrutura HTML canônica completa

O auditor básico do pacote privado foi endurecido novamente. Antes, um elemento adicional inserido fora das linhas de perguntas poderia conservar as contagens corretas de `<details>` e `<summary>`. Agora, a seção inteira precisa corresponder exatamente ao formato inerte emitido pelo motor.

- Cabeçalho `Perguntas frequentes`, contêiner, `<ul>`, linhas `<li><details><summary>texto</summary><p>texto</p></details></li>` e fechamento devem manter a estrutura conhecida.
- Não são permitidos blocos adicionais, wrappers, títulos alterados, lista `<ol>` substituta, espaços estruturais improvisados ou linhas inesperadas.
- O contrato é exclusivamente para previews sintéticas determinísticas. Conteúdo de pergunta/resposta continua validado e escapado pelo briefing; o auditor não afirma autenticar a origem editorial.
- Os testes recorrem a 66 alterações negativas em seis páginas, inclusive reconstruindo `ReleaseArtifact` e `ReleaseManifest` com novo SHA-256, além de revalidar o HTML legítimo.

Não há modificação no HTML histórico de páginas nem qualquer liberação de publicação. Os dez gates operacionais permanecem `BLOCKED` e revisão independente ainda é obrigatória.