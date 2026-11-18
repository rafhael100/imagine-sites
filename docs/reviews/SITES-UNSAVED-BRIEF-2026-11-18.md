# Imagine Sites — briefing preservado e conteúdo salvo

## Defeito observado

O workspace já preserva os campos de um briefing recusado por validação 422 ou conflito 409. Em um conflito, o controller consulta novamente o projeto para obter a revisão atual e depois sobrepõe o formulário com os campos da tentativa do autor. O briefing salvo permanece disponível separadamente nos dados da página.

A view, porém, afirmava sempre que o briefing do formulário correspondia à versão atual. A mesma afirmação aparecia na apresentação da comparação e no aviso do histórico paginado. Assim, uma tentativa ainda não salva podia ser apresentada como o conteúdo da revisão recém-criada por outra pessoa. A comparação ou a navegação para a versão atual exigiam um novo GET, descartando o texto preservado nessa resposta.

A branch `fix/sites-preview-unsaved-brief-2026-11-18` corrige essa distinção no próprio documento e parte da PR #507, em `4f4339b752986c2ab4ac754d011a9c1b1134a302`. Essa base foi incorporada preservando as alterações da interface; o CI da base passou integralmente, com evidência na [consulta editorial](SITES-EDITORIAL-INSPECTION-2026-11-18.md).

## Contrato da resposta

`PrivatePreviewController::renderWorkspace` passa à view a flag explícita `hasUnsavedBrief`. Ela é verdadeira somente quando o status é 422 ou 409 e o conjunto de campos de briefing submetidos não está vazio. A flag descreve a origem do formulário nessa resposta; não representa autorização, persistência ou uma nova versão.

O merge e a preservação existentes permanecem iguais. `$form` contém os valores que o autor pode corrigir ou consultar; `$brief`, `currentRevision` e `currentSha256` continuam sendo o snapshot salvo obtido pelo leitor do histórico. O produto de um projeto existente mantém a regra de imutabilidade. Texto malformado segue as regras de preservação anteriores, sem nova normalização ou ampliação de limites.

O GET normal, a recuperação da busca/comparação e os erros de revisão editorial não enviam um briefing submetido para esse caminho. Eles conservam a apresentação anterior. A tentativa de parecer continua no componente próprio, vinculada à revisão e ao digest do parecer.

## Consulta sem perder a tentativa

No estado de briefing preservado, o título passa a ser “Briefing ainda não salvo” e a mensagem informa “Este briefing ainda não foi salvo.” O formulário editável se associa ao aviso por `aria-describedby`. As legendas da comparação e do histórico paginado também deixam de identificar a tentativa como briefing atual.

Para um projeto existente, um `details`/`summary` nativo oferece “Consultar briefing salvo da versão N”. O painel usa exclusivamente `$brief`, a revisão e o digest salvos. Ele mostra formato, marca, chamada principal, descrição, chamada do botão e benefícios, preservando a ordem destes. Todos os valores são escapados, inclusive texto que contenha aspas, HTML literal ou expressões semelhantes a handlers.

Abrir e fechar o painel não faz GET, POST ou execução de JavaScript. Não há controles de edição, links, preenchimento automático ou ação de copiar programática dentro dele. O autor pode selecionar o texto salvo e copiá-lo manualmente, enquanto os campos de sua tentativa permanecem na página. O painel também funciona quando a revisão atual está fora da janela visível do histórico; ele não extrai conteúdo de uma versão antiga dessa janela.

Uma criação recusada ainda não possui versão salva. Ela recebe o aviso, preserva os campos corrigíveis e não apresenta painel, revisão ou digest fictícios.

## Permissões e novo envio

O aviso e a consulta do conteúdo salvo ficam antes da bifurcação entre edição e leitura. Se o acesso de edição deixar de estar disponível entre o envio e a renderização, mas a leitura continuar autorizada, a tentativa é apresentada como texto ainda não salvo e o snapshot salvo permanece consultável. Não surge formulário de edição nesse estado. Uma criação recusada nessa mesma condição conserva seu texto para leitura, sem inventar um formato válido para uma seleção inválida.

A conferência corrente de leitura permanece obrigatória. Perda de leitura, isolamento de empresa e as recusas existentes não são convertidos em uma recuperação de interface.

O campo `expected_revision`, a ação do formulário, o CSRF e a proteção SQL de concorrência não mudam. A revisão esperada continua sendo preenchida como antes a partir da head consultada; um novo envio exige a ação explícita do autor e pode voltar a conflitar se outra revisão surgir. O painel não reserva a revisão, não altera o CAS, não repete a tentativa e não confirma que um envio anterior persistiu.

Busca, filtros, cursor e janela do histórico continuam no contexto de navegação já validado. A tentativa não é copiada para links, parâmetros de comparação, sessão ou armazenamento. Ela continua disponível somente na resposta privada sem cache; a consulta nativa evita sair dela para conferir os dados salvos.

## Evidência local executada

O novo `PrivatePreviewUnsavedBriefViewTest` passou em **7 testes e 78 assertions**. O conjunto com as views existentes do workspace e da recuperação da busca passou em **32 testes e 365 assertions**, usando `--do-not-cache-result`. A suíte completa da aplicação passou em **159 testes, 1702 assertions e 27 skips explícitos**. Sintaxe do controller e do novo teste e `git diff --check` também passaram.

Os casos verificam a separação entre valores salvos e tentados, escaping, ordem dos benefícios, revisão e digest do snapshot, história fora da head, navegação preservada, CAS inalterado, ausência de novas ações no painel e mensagens de validação acessíveis. Também cobrem criação recusada, perda apenas da edição e ausência do aviso em GET normal ou parecer editorial. São renderizações locais com dados sintéticos; não simulam uma autorização SQL positiva.

As revisões independentes do controller, das três views e dos testes não identificaram bloqueadores. A conferência incluiu a origem dos dois conjuntos de campos, o estado de leitura após perda de edição e a preservação dos contratos anteriores de navegação e parecer.

A jornada HTTP está implementada e revisada independentemente, com **30 checks novos/370 HTTP aprovados**, preservando o baseline de 340. Ela usa POSTs de navegação reais para validação 422 e conflito 409; neste último, a ferramenta de teste altera somente a revisão esperada para emular uma aba antiga, mantendo o JavaScript da página desativado. O teste confere os seis campos salvos contra o GET normal e o digest contra os bytes do endpoint HTML. O painel nativo abre sem navegação e sem substituir a tentativa. Também cobre head 61 fora da janela 40–21, criação inválida, parecer editorial inválido separado, outra empresa com CSRF próprio, replay do POST após revogação e snapshots integrais das cinco tabelas. As capturas desktop e mobile foram revisadas: painel legível, texto literal escapado e layout sem transbordamento horizontal. Não há escrita positiva adicional nessa fixture. O [run 44244700974](https://github.com/rafhael100/imagine-sites/actions/runs/44244700974) passou na HEAD `108e1211f05a42576b7a3a2b69eb9e9e0db23b17`, confirmando **30 novos/370 HTTP**, **386 verificações SQL do workspace** e **159 testes/1702 assertions/27 skips** em PHP 8.3/8.4. Foram conferidos **19/19 check-runs SUCCESS** e a limpeza dos laboratórios. O artifact `14401282122` contém 25 arquivos; seu SHA-256 foi conferido: `89ca63094f7a4aa30cda91dff2a5128f9b59e0458188a47fa9ad556e78f5f9f8`. A suíte de 850 verificações do registry pertence à base PR #507; este workflow não a executa novamente.

## Escopo preservado

A entrega modifica apenas a apresentação do briefing preservado e a flag que informa sua origem. Não acrescenta endpoint, consulta SQL, migration, schema, grant, renderer ou regra editorial. Não transforma revisão interna em aceite do cliente, nem promove os dados demonstrativos de `sites_preview` ao fluxo operacional.

Staging, serviços, credenciais, dados reais, Portal e publicação permanecem fora desta alteração. Os gates operacionais continuam sujeitos às evidências e autorizações próprias já documentadas.
