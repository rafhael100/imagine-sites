# Imagine Sites Engine

Motor independente de criação, versionamento e preparação técnica de **Onepages e Landing Pages produzidas e administradas pela Imagine**. O cliente contrata, envia briefing, aprova e acompanha pelo Imagine Portal; a edição interna pertence à equipe Imagine.

## Estado de desenvolvimento

Esta branch contém a aplicação Laravel em `apps/sites`, migrations PostgreSQL18, renderer de HTML inerte, workspace privado de demonstração, ciclo editorial e registro de releases. A sequência de desenvolvimento está em PRs draft; o estado atual e as evidências de cada revisão estão no [STATUS-ATUAL.md](STATUS-ATUAL.md) e no [relatório da PR #490](docs/reviews/SITES-RELEASE-READINESS-2026-11-17.md).

O workspace permite gerar versões imutáveis, consultar fila e histórico, buscar projetos por marca ou chamada principal da versão atual, comparar briefings de duas versões e registrar revisão editorial com outra identidade autorizada. Solicitações de ajuste incluem a orientação para o autor, preservada por versão/digest e visível ao consultar o histórico. Os dados de demonstração permanecem separados dos projetos comerciais. O registro técnico associa o artefato à versão editorial e às referências persistidas de pedido/contrato. O CLI privado prepara um pacote de três arquivos e verifica posteriormente seus bytes e a associação corrente. O diário técnico persiste tentativas, ACKs relatados e resultados inconclusivos, sem autorizar envio ou declarar publicação.

A correção candidata do renderer permite quebrar palavras e URLs literais longas na marca, descrição, CTA e benefícios. O texto completo é preservado nas novas gerações; versões históricas continuam usando seus bytes armazenados. Os exemplos de HTML e as expectativas dos novos planos acompanham o CSS atualizado, sem adaptação automática de planos antigos. A [#524](https://github.com/rafhael100/imagine-sites/pull/524) confirmou **15/15 checks SUCCESS**, incluindo **8 novos/447 HTTP totais**, os dois produtos em 320, 390 e 1440 pixels e **429 workspace/1094 registry** em PHP 8.3/8.4. As capturas de 320 e 1440 pixels também foram conferidas visualmente. Consulte o [recorte e seus limites](docs/reviews/SITES-RENDERER-LONG-TEXT-2026-11-18.md).

A entrada de benefícios agora ignora linhas formadas apenas por espaços Unicode, incluindo NBSP e EM SPACE, depois da validação original. Oito itens reais podem ser colados com essas linhas entre eles; espaços Unicode junto de texto permanecem intactos. Os limites de oito itens, 180 caracteres por item e 1500 no campo completo continuam iguais. A [#525](https://github.com/rafhael100/imagine-sites/pull/525) confirmou **14/14 checks SUCCESS**, com **4 novos/451 HTTP totais** e Laravel **284/4280/27** e **284/4292/21** em PHP 8.3/8.4. [Recorte e prova da correção](docs/reviews/SITES-BENEFIT-BLANK-LINES-2026-11-18.md).

Os campos e o botão da revisão editorial identificam a versão explicitamente. Isso permite escolher o parecer pelo nome do controle, inclusive quando várias versões estão disponíveis na mesma página. Erros e orientações preservadas mantêm essa identificação.

Os links do histórico também identificam a versão da prévia e o par da comparação. As legendas descrevem a edição quando o formulário está disponível e a consulta do briefing salvo nos acessos somente para leitura ou revisão.

Cada versão tem uma consulta própria de pareceres, com 20 registros por página e continuação para os anteriores. Assim, as orientações que excedem o resumo do histórico permanecem acessíveis. A seleção conserva o contexto de busca e comparação e mantém a edição na versão atual do projeto.

Após um erro de validação ou conflito de edição, o workspace identifica o briefing que ainda não foi salvo e conserva os campos para conferência. Um painel nativo na mesma página permite consultar o briefing realmente salvo, sua revisão e digest, sem sair da tentativa ou perder o texto. A navegação pelo histórico e o controle de revisão esperada permanecem preservados.

A geração aceita revisões acima de 10.000 e respeita o maior sucessor armazenável pelo banco. Ao atingir a última revisão representável, o projeto mantém briefing, prévias, histórico, comparação e revisão editorial; a interface informa o limite e deixa de oferecer uma geração impossível.

Na fila, uma busca segura acima do limite permanece no formulário com mensagem de correção e filtros selecionados. O limite da consulta continua em 100 caracteres; a resposta de erro pode preservar até 200, sem executar a busca inválida ou mostrar resultados alternativos. Corrigir ou limpar inicia novamente a primeira página.

O registro privado de artefatos possui plano somente leitura e gravação explícita por hash revisado, usando a API SQL existente. Ele conserva os bytes e a idempotência do registry; a aprovação editorial e a associação da release exigem suas identidades próprias antes de preparar o pacote.

A renderização local também pode conferir um plano, um snapshot candidato do briefing rico e os bytes da entrada editorial escolhida. O modo explícito produz o mesmo HTML inerte e um recibo privado ligado ao hash do plano. Os quatro arquivos são verificados antes e durante a escrita; somente o HTML segue ao registro existente. A proveniência demonstra consistência local, mantendo `authorityVerified`, `canPublish` e `canAcceptRealOrders` falsos.

O armazenamento nativo candidato recebe o manifesto e o corpo rico Onepage como bytes imutáveis, com identidade SQL própria e vínculo ao intake de projeto. A migration 13 mantém uma sequência de `briefRevision` distinta do contador agregado `sourceVersion`, com CAS e replay exato. O recibo técnico tem treze campos e conserva `authorityVerified`, `clientAccepted`, `canPublish` e `canAcceptRealOrders` falsos; ele não autentica a aprovação declarada pelo Portal.

Uma conexão de leitura separada recupera a seleção exata do snapshot e revalida o perfil completo antes de alimentar o renderer com a entrada editorial escolhida. Esse consumidor interno continua sem escrever arquivos ou registrar o artefato por conta própria. O armazenamento foi validado na #519; consulte o [manual dos snapshots candidatos](docs/PORTAL-BRIEF-SNAPSHOTS.md).

A entrada privada `receive-brief-snapshot.php`, incorporada da [#523](https://github.com/rafhael100/imagine-sites/pull/523) em uma candidata integrada, planeja e recebe os dois JSONs originais por cinco mounts fixos. A escrita exige compromisso e confirmação explícita; usa o recorder existente, preserva CAS/replay e não repete automaticamente uma chamada. A prova em preparação acompanha o snapshot recebido até o registro composto e sua inspeção histórica, inclusive quando a resposta de recebimento foi descartada. Consulte o [manual do receptor](docs/BRIEF-SNAPSHOT-RECEIVER-CLI.md) e o [recorte integrado](docs/reviews/SITES-BRIEF-SNAPSHOT-CLI-CHAIN-2026-11-18.md).

A composição candidata foi validada na [#520](https://github.com/rafhael100/imagine-sites/pull/520), HEAD `8b717d3c0c03146bc48ee812e906bc70a1c479a9`, com **15/15 checks SUCCESS**. `StoredBriefReleaseRegistration` lê e renderiza internamente, confirma um compromisso que inclui alvo, plano, editorial e HTML originais e solicita uma única operação SQL. A migration 14 associa o snapshot e registra o artefato atomicamente pela operação existente do registry, sem adotar retroativamente artefatos antigos sem relação. O SQL confere estrutura, hashes e vínculo persistente; a geração e a auditoria do HTML continuam no PHP. A API antiga conserva seu contrato. Em PHP 8.3/8.4, a prova confirmou **119 checks novos/1042 registry totais**, **245 checks de briefing/65 de intake**, Laravel **279/4250/27** e **279/4262/21**, além de rollback e cleanup dos laboratórios próprios.

O plano composto tem dez campos, o resultado de registro vinte e a consulta histórica exata dezoito. `commitmentSha256` identifica a confirmação completa; `planSha256` identifica somente os bytes do plano. Um reader separado permite consultar o recibo histórico sem repetir um writer cuja época já mudou. Falha após a chamada pode seguir commit, sem retry automático. O serviço composto não aceita sidecar arbitrário nem autentica aprovação Portal. As CLIs privadas descritas a seguir oferecem consulta e entrada explícita de registro; HTTP, provider e transporte permanecem integrações próprias. O comando local da #518 continua sem DB; produtor, transporte e materiais reais permanecem integrações próprias. Consulte o [manual da composição](docs/STORED-BRIEF-RELEASE-PROVENANCE.md).

A nova CLI candidata `inspect-provenance.php inspect` permite consultar esse recibo por site, release e hash exato do plano, usando três entradas privadas fixas e uma identidade somente leitura. Ela reaproveita o serviço existente, confere a custódia antes e depois da consulta e retorna dez campos técnicos, sem referências privadas ou conteúdo do briefing. Isso permite apurar uma resposta perdida sem repetir o writer original. O recibo histórico não certifica execução do renderer, elegibilidade atual ou autorização para publicar. A [#521](https://github.com/rafhael100/imagine-sites/pull/521), HEAD `73d610513c777c5f3a1709fcb19263d6073139c6`, concluiu **12/12 checks SUCCESS**: **51 checks offline**, **25 novos/1067 registry totais** nas duas versões PHP, com cleanup dos três arquivos privados e clusters próprios inativos. Consulte o [manual da consulta privada de proveniência](docs/BRIEF-ARTIFACT-INSPECTION.md).

A nova CLI candidata `register-provenance.php` planeja e registra pela composição existente, a partir de seis entradas privadas fixas. Usa identidades distintas para a leitura do snapshot e o registro do artefato no mesmo banco observado; ambas começam somente leitura. O comando `record` exige o compromisso do plano real e a confirmação explícita antes de habilitar uma única chamada do writer. A saída contém dez campos no plano ou doze no registro, com quatro flags falsas. A [#522](https://github.com/rafhael100/imagine-sites/pull/522), HEAD `0b735a1794ee3bb965e5a05d5d738083ddac9544`, concluiu **12/12 checks SUCCESS**: **95 verificações offline**, **27 novas/1094 registry totais** em PHP 8.3/8.4, com arquivos próprios removidos e clusters encerrados. Consulte o [manual do registro privado composto](docs/STORED-BRIEF-REGISTRATION-CLI.md).

O guard do registry inspeciona permissões de tabela e coluna em todo o schema nativo, incluindo o ledger de recebimento do Portal. A ampliação usa uma migration aditiva que preserva identidade e ACL da função existente; nenhuma capacidade de recebimento, pagamento ou publicação é concedida por essa verificação.

A CLI editorial permite planejar e solicitar uma transição por execução, com revisão esperada, bytes exatos e confirmação explícita do plano. O SQL verifica a função do operador e o estado corrente. O comando não consulta o artefato no registry nem estabelece uma associação; conflitos e respostas perdidas exigem apuração antes de outra decisão, sem retry ou incremento automático da revisão.

A inspeção editorial privada consulta a revisão, o estado e o digest atuais de um site conhecido, inclusive fora da listagem dos 100 mais recentes. Usa uma identidade de leitura separada e mantém a sessão somente leitura. Um projeto autorizado sem revisão retorna os três campos nulos; falta de acesso e site ausente recebem a mesma recusa. A observação não atribui uma transição a uma resposta perdida nem permite repetição automática.

A associação e a revogação privadas têm comandos separados e credenciais próprias, com plano somente leitura e controle da revisão esperada. A associação usa o artefato imutável e a aprovação editorial corrente; a revogação preserva o histórico e invalida o vínculo, permitindo que a preparação e a verificação recusem pacotes que deixaram de ser correntes.

A consulta privada da associação informa o último evento persistido de um site conhecido, incluindo revogação e ausência de eventos. Usa um leitor separado, sem alterar o histórico. A revisão, o tipo do evento e o digest ajudam a apuração após resposta perdida; não comprovam elegibilidade atual, autoria de uma chamada ou autorização para repetir a operação.

A reconciliação privada permite planejar e solicitar a classificação técnica de uma operação conhecida do diário. O plano vincula seletores e runtime; o SQL decide a revisão e o vencimento. Um resultado `inconclusive` pode incluir ACK tardio e não comprova publicação externa. A execução exige confirmação explícita e preserva tentativa e lease, sem reenvio ou reset automático.

A solicitação privada do diário recebe a release e a revisão esperada da associação, confirma um plano somente leitura e usa a API de idempotência existente. A referência da operação fica em um novo recibo privado para preparar sua consulta pelo inspetor. A resposta pública contém apenas a projeção técnica; uma repetição pode devolver o estado mais recente da mesma operação. O recibo e o banco são recursos separados, portanto falha na gravação do arquivo pode ocorrer depois do commit.

Esses componentes não constituem homologação operacional. Contrato, pagamento, identidade entre serviços, revogação, aceite expresso do cliente, backup externo e publicação exigem suas próprias fontes e evidências. Os dez gates em `config/operational-readiness.json` permanecem `BLOCKED`; os padrões de autorização continuam negadores.

## Componentes

| Caminho | Responsabilidade |
| --- | --- |
| `apps/sites` | Aplicação Laravel, workspace interno, migrations e testes da aplicação |
| `src/Production` | Briefing, validação compartilhada de snapshots/planos, renderer, auditoria de HTML, versões, fila, histórico e comparação |
| `src/Publication` | Vínculo da release, evidências, pacote privado e diário persistente de tentativas |
| `src/Integration` | Contratos e adaptadores candidatos de integração com o Portal |
| `apps/sites/app/Integration` | Recebimento nativo, leitura exata, renderização de snapshots e composição candidata de registro com recuperação histórica |
| `contracts` | Regras SQL versionadas e fronteiras dos componentes |
| `ops/preview-candidate` | Provisionamento explícito e revisável do workspace privado |
| `ops/release-candidate` | Renderização local, registro, associação/revogação, consultas do último evento e do recibo de proveniência, e `prepare`/`verify` do pacote técnico |
| `ops/editorial-candidate` | Transição editorial explícita e consulta exata do head com leitor separado |
| `ops/publication-candidate` | Solicitação com recibo privado, inspeção somente leitura e reconciliação explícita do diário |
| `tests/fixtures` | Materiais inteiramente sintéticos para os ensaios descartáveis de CI |

## Verificação local

O Composer lock da aplicação define as dependências. A suíte geral usa banco desconectado por padrão; os cenários que precisam de PostgreSQL ou de opt-in explícito são executados pelos jobs dedicados, com identidade do banco descartável verificada antes de qualquer escrita.

```sh
composer install --working-dir=apps/sites --no-interaction --no-scripts --no-plugins
php tests/run.php
php tests/private-preview-provisioning-offline.php
php tests/private-preview-upgrade-offline.php
php tests/private-preview-upgrade-cli-offline.php
php tests/release-preparation-offline.php
php tests/local-site-rendering-offline.php
php tests/local-site-rendering-cli-offline.php
php tests/brief-render-provenance-offline.php
php tests/local-site-rendering-provenance-cli-offline.php
php tests/release-registration-offline.php
php tests/release-registration-cli-offline.php
php tests/release-binding-offline.php
php tests/release-binding-cli-offline.php
php tests/release-binding-inspection-offline.php
php tests/release-binding-inspection-cli-offline.php
php tests/brief-artifact-inspection-cli-offline.php
php tests/brief-artifact-registration-cli-offline.php
php tests/brief-snapshot-receiver-cli-offline.php
php tests/editorial-transition-offline.php
php tests/editorial-transition-cli-offline.php
php tests/editorial-inspection-offline.php
php tests/editorial-inspection-cli-offline.php
python3 tests/fixture-private-material.py
python3 tests/fixture-receipt-custody.py
python3 tests/fixture-publication-request-custody.py
php tests/publication-journal-offline.php
php tests/publication-request-offline.php
php tests/publication-request-cli-offline.php
php tests/publication-inspection-offline.php
php tests/publication-inspection-cli-offline.php
php tests/publication-reconciliation-offline.php
php tests/publication-reconciliation-cli-offline.php
php apps/sites/vendor/bin/phpunit -c apps/sites/phpunit.xml --do-not-cache-result
```

Os runners PostgreSQL/HTTP não são comandos de instalação. Eles exigem o contexto explícito de CI e criam recursos próprios descartáveis. Resultados sintéticos não liberam a aplicação de migrations ou grants em um ambiente existente.

## Fronteiras e operação

O Portal controla contratos, aceite expresso e evento oficial `ENTREGUE`; Sites controla artefatos e release técnica; CRM controla a confirmação de leads. A associação de uma release, uma revisão interna ou um pacote exportado não comprova pagamento nem aceite do cliente.

- [Arquitetura e autoridades](docs/SITES-ARQ-02-BOUNDARIES.md)
- [Contratos candidatos Portal ↔ Sites](docs/SITES-CT-01-PORTAL-SITES-v0.1.md)
- [Briefing rico, revisão capturada e plano de renderização candidato](docs/SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md)
- [Schemas e exemplos sintéticos do contrato de briefing](contracts/portal-sites/candidates/brief-production-v1/README.md)
- [Gates de segurança](docs/SITES-SEC-01-GATES.md)
- [Inventário de migração](docs/SITES-MIG-01-INVENTARIO.md)
- [Provisionamento de prévias](ops/preview-candidate/README.md)
- [Upgrade explícito do preview gerenciado](docs/reviews/SITES-PREVIEW-MANAGED-UPGRADE-2026-11-18.md)
- [Preparação e verificação de pacotes](ops/release-candidate/README.md)
- [Registro privado de artefatos](docs/reviews/SITES-ARTIFACT-REGISTRATION-2026-11-18.md)
- [Associação e revogação de releases](docs/reviews/SITES-RELEASE-BINDING-2026-11-18.md)
- [Consulta privada do último evento de associação](docs/reviews/SITES-BINDING-INSPECTION-2026-11-18.md)
- [Transição editorial explícita](docs/reviews/SITES-EDITORIAL-TRANSITION-2026-11-18.md)
- [Manual da CLI editorial](ops/editorial-candidate/README.md)
- [Diário técnico de publicação](contracts/publication-journal/README.md)
- [Inspeção privada do diário](ops/publication-candidate/README.md)
- [Reconciliação explícita do diário](docs/reviews/SITES-PUBLICATION-RECONCILIATION-2026-11-18.md)
- [Custódia dos recibos dos auxiliares de CI](docs/reviews/SITES-FIXTURE-RECEIPT-CUSTODY-2026-11-18.md)
- [Recuperação do formulário de comparação](docs/reviews/SITES-COMPARISON-RECOVERY-2026-11-18.md)
- [Renderização de briefing local privado](docs/reviews/SITES-LOCAL-ARTIFACT-RENDERING-2026-11-18.md)
- [Proveniência local do briefing ao HTML](docs/reviews/SITES-LOCAL-RENDER-PROVENANCE-2026-11-18.md)
- [Snapshots candidatos: armazenamento, leitura exata e consumo](docs/PORTAL-BRIEF-SNAPSHOTS.md)
- [Composição candidata: snapshot, artefato e recibo histórico](docs/STORED-BRIEF-RELEASE-PROVENANCE.md)
- [Consulta privada do recibo de proveniência](docs/BRIEF-ARTIFACT-INSPECTION.md)
- [Planejamento e registro privado pela composição](docs/STORED-BRIEF-REGISTRATION-CLI.md)
- [Texto completo e quebra de palavras nas páginas geradas](docs/reviews/SITES-RENDERER-LONG-TEXT-2026-11-18.md)
- [Schemas e exemplos de briefing, plano e recibo técnico](contracts/portal-sites/candidates/brief-production-v1/README.md)
- [Próxima integração Portal → briefing → geração](docs/reviews/SITES-NEXT-INTEGRATION-2026-11-18.md)
- [Orientação editorial por versão](docs/reviews/SITES-EDITORIAL-FEEDBACK-2026-11-18.md)
- [Briefing Unicode e validação por campo](docs/reviews/SITES-BRIEF-UNICODE-VALIDATION-2026-11-18.md)
- [Texto literal e comparação de versões](docs/reviews/SITES-INERT-TEXT-COMPARISON-2026-11-18.md)
- [Limite de versões e consulta do projeto terminal](docs/reviews/SITES-PREVIEW-REVISION-CEILING-2026-11-18.md)
- [Busca na fila editorial](docs/reviews/SITES-QUEUE-SEARCH-2026-11-18.md)
- [Regras do repositório](AGENTS.md)

Não há merge ou deploy automático. Infraestrutura existente, DNS, Cloudflare, dados reais, credenciais e despesas dependem da autorização correspondente. O código em `src/Domain` continua incluindo regras de laboratório; seus booleanos não substituem identidade ou evidência externa.
