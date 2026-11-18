# Imagine Sites — limite consistente para novas versões

## Defeito confirmado

O formulário validava `expected_revision` até 10000. A persistência, porém, guarda revisões em um `INTEGER` positivo do PostgreSQL e acrescenta a próxima revisão com `expected + 1`. Um envio válido com revisão esperada 10000 podia criar 10001; o próprio formulário retornado passava então a enviar 10001, que era recusado pela validação. Recarregar o projeto não resolvia a inconsistência.

O leitor do histórico e a navegação já aceitam revisões até 2147483647. A diferença estava na entrada de escrita, e foi identificada independentemente em duas revisões de código. O comportamento anterior pode ser consultado no [parser da base](https://github.com/rafhael100/imagine-sites/blob/4f4339b752986c2ab4ac754d011a9c1b1134a302/apps/sites/app/Preview/PreviewBriefInput.php).

A branch `fix/sites-preview-revision-ceiling-2026-11-18` parte da PR #508, em `108e1211f05a42576b7a3a2b69eb9e9e0db23b17`. A validação dessa base está registrada no [relatório do briefing preservado](SITES-UNSAVED-BRIEF-2026-11-18.md): 19 check-runs aprovados, 370 verificações HTTP e 386 SQL do workspace.

## Limites de leitura e escrita

`PreviewBriefInput::MAX_EXPECTED_REVISION` fixa o maior head que ainda possui sucessor representável: **2147483646**. A regra do Validator usa essa constante. A revisão 2147483647 continua legível, mas não é aceita como revisão esperada de uma nova geração.

| Situação | Comportamento da entrada de escrita |
| --- | --- |
| Revisão esperada 10000 ou 10001 | Aceita se os demais campos forem válidos; o SQL ainda exige correspondência com o head corrente |
| Revisão esperada 2147483646 | Aceita como último valor que pode gerar um sucessor inteiro |
| Revisão esperada 2147483647 ou maior | Recusada antes de chamar o append |
| Criação de projeto com `expected_revision` | Continua recusada; não existe head anterior a selecionar |

A mudança não autoriza criar uma revisão arbitrária. O formulário continua transportando somente a revisão esperada; o banco verifica o estado corrente e produz o próximo número. O teto maior não contorna CAS, identidade, empresa, acesso ou imutabilidade.

## Apresentação do limite terminal

O controller passa `canAppendVersion` como condição separada das permissões `canEdit` e `canReview`. A condição usa a revisão atual consultada, mesmo quando ela está fora da janela de histórico exibida. No head terminal, o workspace conserva o briefing salvo, o histórico, a comparação e os pareceres autorizados, sem oferecer um formulário cujo próximo número não possa ser armazenado.

O aviso “Limite de versões atingido.” deriva do número persistido, inclusive para leitores e revisores sem edição. A ausência de permissão de edição em uma revisão menor não é confundida com o limite numérico. As legendas de comparação e histórico passam a descrever o briefing como consulta quando o projeto está no teto.

Essa condição também alcança a resposta com uma tentativa preservada: se um conflito revelar que outra pessoa já criou a última revisão, a página mostra a tentativa e o snapshot salvo separadamente, mas não promete novo envio. O texto tentado permanece escapado e disponível para consulta. A leitura e a revisão editorial não são desabilitadas pelo limite numérico.

## Evidência local do parser

O conjunto de `PreviewBriefInputTest` passou em **12 testes e 191 assertions**, com o Validator real e ambos os produtos. Os novos casos aceitam inteiros e representações textuais de 1, 10000, 10001 e 2147483646. Recusam 2147483647 e valores superiores com erro somente em `expected_revision`. A sintaxe dos dois arquivos e `git diff --check` passaram.

O novo `PrivatePreviewRevisionCeilingViewTest` passou em **7 testes e 76 assertions**. O conjunto de views passou em **39 testes e 441 assertions**, e a suíte completa passou em **168 testes, 1818 assertions e 27 skips explícitos**, sempre com `--do-not-cache-result`. A revisão independente do parser, controller, views e testes foi concluída sem bloqueadores. A conferência corrigiu o aviso terminal para leitores e revisores, além dos autores.

Esses testes comprovam admissibilidade da entrada e apresentação local. Não presumem existência de uma revisão, autorização SQL ou sucesso de uma geração. Não foi executado banco ou Docker localmente.

## Fixtures de persistência e navegador

A fixture SQL implementa **18 verificações novas/404 previstas no conjunto**, conservando as 386 existentes. Dois projetos legítimos recebem somente uma versão alta sintética cada, copiada de sua versão inicial pelo autor restrito, com RLS e triggers ativos. A entrada real e o workspace produzem 10000 → 10001 → 10002 e 2147483646 → 2147483647. Leitura, HTML, comparação e parecer independente no teto são verificados, junto de CAS obsoleto, outra empresa e revogação. Snapshots integrais distinguem os appends autorizados de qualquer mutação indevida.

A jornada HTTP implementa **24 checks novos/394 previstos**, preservando os 370 anteriores. O seed ocorre após as verificações antigas sensíveis à contagem da fila, valida o cluster efêmero antes de escrever e inclui uma negativa com cluster incorreto. A criação dos projetos e os três sucessores usam formulários reais, com JavaScript desativado. O ensaio cobre o aviso terminal, os seis campos somente leitura, histórico antigo, comparação exata, HTML imutável, POST terminal 422, conflito 409, isolamento, parecer editorial independente e revogação após reinício. Duas capturas desktop/mobile estão previstas.

Lint PHP, sintaxe Node, YAML/shell dos jobs, pins de draft/base/head e safety guard de fontes passaram. Os dez gates continuam `BLOCKED`. **Os totais SQL/HTTP e as imagens ainda aguardam execução no CI.**

## Escopo

Nenhum schema, migration, grant, regra de revisão editorial ou contrato do Portal precisa mudar. O workspace continua usando dados demonstrativos privados. Publicação, aceite do cliente e os gates operacionais conservam suas fronteiras existentes.
