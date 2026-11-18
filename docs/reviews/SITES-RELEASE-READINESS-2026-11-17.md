# Candidato: registro de release, provisionamento de prévias e navegação editorial

Data: 17/11/2026. Continuidade da PR #489, base `cac9084b4fbb0b2a5cf50ac843e89010fb9a019c`.
Branch: `feature/sites-release-readiness-2026-11-17`. DRAFT, sem merge ou instalação na VPS.

## Problemas resolvidos no código

O verificador de evidências comercial e de aceite já exigia uma terceira fonte independente para associar pedido e release. Essa fonte era uma interface com implementação padrão de recusa. A migration 000005 e os novos adaptadores PDO acrescentam o registro persistente do Sites, alimentado pelos registros existentes de projeto, contrato e revisão editorial em `sites_core`.

A fila privada exibia somente os primeiros 50 projetos sem navegação. O histórico também usava uma lista limitada de pareceres para decidir se alguém ainda podia revisar uma versão; um parecer antigo podia desaparecer dessa lista. A nova fila tem cursor, filtros por formato e parecer, marca e chamada do briefing corrente. O histórico tem janelas de 20 versões, mantendo briefing e revisão esperada no estado atual; a disponibilidade da revisão consulta o histórico completo.

A preparação do schema de prévias dependia de passos manuais e o bootstrap HTTP de laboratório criava seus objetos como `postgres`. O candidato passa a incluir um provisionador que executa a migration existente sob proprietário `NOLOGIN`, registra o ledger Laravel e verifica o resultado. O próprio ensaio HTTP consome esse procedimento.

## Registro independente do Sites

| Componente | Responsabilidade |
| --- | --- |
| `release_registry_principals` | Login SQL, organização, uma função permitida, validade e época monotônica de autorização |
| `release_artifacts` | Bytes UTF-8 e SHA-256 imutáveis, autor e época que autorizou a gravação |
| `release_binding_events` | Histórico append-only da associação ou revogação, com revisão de concorrência |
| `PgSiteReleaseRegistry` | Registrar artefato, associar versão editorial atual e revogar associação |
| `PgSiteCommercialReleaseBindingSource` | Ler o vínculo atual para o verificador de evidências existente |

Os métodos de gravação recebem identificadores, bytes e revisões esperadas. Pedido, organização Portal, contrato, revisão do contrato, produto e fingerprint comercial são derivados de `site_projects`, `tenant_accounts` e `contract_refs`. Não há campos de browser ou payload de prova Portal capazes de substituir essa origem.

Um artefato só pode ser associado depois que a revisão editorial corrente aguarda aceite no Portal e possui o mesmo digest. Autor do artefato e responsável pela associação têm funções SQL distintas. A leitura recusa versão antiga, contrato divergente, epoch vencida, revogação, privilégio excessivo, troca de papel SQL ou transação reaproveitada. Repetir a mesma gravação mantém sua identidade; reutilizar a referência para bytes diferentes gera conflito. Gravações concorrentes são serializadas por projeto e validadas pela revisão esperada.

O schema usa três papéis internos `NOLOGIN`, RLS/FORCE RLS, funções com `search_path` fixo, grants mínimos e triggers de imutabilidade. A migration não cria logins, não concede funções a usuários runtime e não insere autoridades. O rollback recusa histórico populado e não usa `CASCADE`.

Esse registro é uma associação técnica. `SiteDualProofEvidenceCheck` continua exigindo, separadamente, prova comercial assinada, testemunhas independentes de liquidação/revogação e aceite explícito da versão exata. Ele relê a associação depois das verificações. O resultado positivo desse componente é evidência, não uma chamada ao publisher. Defaults continuam recusando; nenhuma rota ou provider operacional foi ativado.

## Preparação reproduzível das prévias

O procedimento é descrito em `ops/preview-candidate/README.md`. A imagem candidata inclui o CLI em `/opt/imagine/ops/preview-candidate/provision.php`.

- `plan` verifica alvo, contrato SQL, estado, logins existentes e permissões; emite ações concretas e hash do plano sem escrever.
- `apply` exige o hash exato e autorização explícita no comando. Repete as verificações sob lock, executa migration e grants na mesma transação e verifica o resultado antes do commit.
- `check` verifica o estado instalado sem alterar dados.

O alvo inclui cluster, banco/OID, endereço, porta e login esperados. A configuração efetiva Laravel e o estado SQL precisam coincidir, com TLS `verify-full`. O manifesto não contém senhas, não cria logins e não renova acesso revogado ou expirado. Uma reaplicação exata é no-op; deriva causa recusa e exige revisão. O ledger 000004 permanece consistente com o namespace.

O procedimento trata somente `sites_preview`, que continua `demonstration_only`. Nenhuma demonstração é convertida em pedido comercial. A execução na VPS depende de revisão e autorização operacional específicas, recuperação atual comprovada e custódia de acesso. Esses pré-requisitos não são substituídos por CI.

## Navegação e revisão

Cada página da fila contém até 50 projetos. O cursor contém apenas data e UUID de ordenação; a organização continua vindo da identidade autenticada e do vínculo SQL. Todos os links preservam filtros de formato e parecer. A consulta aplica RLS e verifica a validade do acesso antes e depois da leitura.

O histórico exibe até 20 versões com navegação anterior/próxima. Briefing e `expected_revision` vêm da versão atual, obtidos na mesma consulta da janela; editar a partir de uma página antiga cria a próxima versão atual. A tela não oferece revisão própria ou já registrada, mesmo com mais de 100 pareceres acumulados. Parecer continua vinculado ao digest e à revisão correspondente.

GET de navegação e corpo de POST são tratados separadamente. Valores desconhecidos, arrays, cursores inválidos, UTF-8 inválido ou revisões fora dos limites resultam em 422. Nenhum parâmetro de navegação é usado como empresa ou autoridade. Datas da interface usam o horário de Brasília.

## Verificação e rastreabilidade

O workflow `sites-release-readiness.yml` só executa na branch DRAFT indicada, repositório de origem e base SHA exatos. Seus jobs usam recursos descartáveis, credenciais sintéticas de uma execução, permissões GitHub somente leitura e checkout do HEAD informado pelo evento.

O contrato SQL novo exercita a migration real e os adaptadores PDO, incluindo os dois produtos, revisões pelo writer editorial existente, associação derivada do banco, replay/conflito, isolamento, deriva de grants, revogação/epoch, isolamento de transações, assinatura Ed25519 pelos verificadores existentes e dois processos concorrentes. Uma revogação SQL durante a verificação das provas deve ser detectada na segunda leitura.

O contrato de prévias anterior permanece ativo. O ensaio HTTP usa Nginx, FPM e PostgreSQL18 separados, materiais TLS sintéticos e validação `verify-full`; acrescenta mais de 50 projetos e histórico com 60 versões/120 pareceres. A edição de uma janela antiga deve criar a versão 61 e exigir novo parecer. O provisionador é usado pelo bootstrap do mesmo candidato que atende ao navegador.

Os testes locais que não dependem de banco incluem renderização real Blade, navegação, briefing, sintaxe e guard de segurança do repositório. O ambiente local recusou a abertura de socket PostgreSQL; não foi tentado contornar essa restrição. A evidência SQL/HTTP é produzida no CI. SHAs, contagens efetivamente executadas, runs e artefatos são registrados na descrição da PR após a execução; cenário descrito neste documento não significa resultado aprovado.

## Limites que permanecem operacionais

Permanecem necessários o vínculo Portal real de pedido/contrato, liquidação independente do PSP, custódia S2S e IAM, aceite explícito autenticado do cliente, cópia externa de backup com restauração comprovada e procedimento de instalação/publicação com rollback. Os flags de autoridade, pagamento, aceite e publicação em `sites_core` continuam falsos; o outbox permanece bloqueado. VPS, porta 18090, serviços, volumes, DNS e Cloudflare não são alterados por esta entrega.

## Continuidade: nova rodada editorial após aprovação interna

A primeira implementação deixou visível outra lacuna funcional: a máquina editorial existente não permitia sair de `awaiting_portal_acceptance` para editar novamente. A migration aditiva 000006 acrescenta `reopen`, exclusiva do revisor interno, exigindo a revisão e o digest correntes. A operação preserva a aprovação anterior como histórico e cria um novo `draft`. O editor pode então revisar os bytes, reenviar e obter nova revisão independente.

O writer Laravel valida a ação, os limites de revisão e a resposta exata esperada. A migration compara a função anterior e posterior por digest, preserva OID, proprietário e ACL, e não cria grants. O mesmo lock por projeto serializa transições editoriais e vínculos de release. A retirada da capacidade restaura a função anterior sem apagar revisões.

O ensaio integrado usa esse writer real: recusa reabertura por editor, digest incorreto, revisão antiga e empresa alheia; reabre a revisão 3 como draft4, altera o conteúdo em 5, submete em 6 e aprova internamente em 7. A associação antiga deixa de ser atual assim que existe o novo draft. A nova revisão precisa de artefato e vínculo próprios; o recibo de aceite da versão anterior não passa pelo verificador da nova versão. O controle positivo usa nova prova explicitamente sintética, sem criar aceite real.

Primeiro código, antes dessa continuação: `88cd4cc452c3be44162fa47c4e0a09c6af5969f9`, PR #490. O job SQL `134444068491` do run `44197904708` concluiu com 250 verificações do contrato de prévias, 168 verificações do registro persistente, 26 casos offline de manifesto e PHPUnit 66 testes/199 assertions/27 skips explícitos. Esses resultados pertencem a esse SHA; a continuação recebe nova execução de CI e sua evidência fica registrada na PR.

O job HTTP `134444068998` do mesmo run também concluiu com sucesso: 24 verificações do provisionador e 55 da jornada no navegador. Artefato `14379697800`, `sites-release-readiness-preview-http`, SHA-256 `603d984302745dfcaed402f8cef25d65837f0ee4c8495079f18d7563c1832e0e`, contém somente dados sintéticos. Os dois jobs legados F383 (PHP8.3/8.4) inicialmente recusaram as três novas tabelas por ainda esperarem o inventário anterior; a continuidade atualiza o inventário exato e as políticas conhecidas, mantendo aplicação de todas as migrations e rollback no CI.

A continuação `36f10cfc22bd7c10ab82839c53726481104704e4` passou nos 16 check-runs do HEAD. Run `44199094329`: 191 verificações do registro/ciclo editorial, 250 de prévias, 24 do provisionador e 56 do navegador. O run legado `44199094357` aplicou todas as migrations e comprovou o rollback em PHP8.3/8.4; os scripts SQL executaram 67 verificações F383, 21 F384 e 39 F385. Artefato visual `14380397277`, SHA-256 `ca98139cef60b55a7ef776edd9875116f54be37bcb8debc0ca46b6364f88cee3`, teve fila e workspace revisados em desktop/mobile.

Uma leitura dos logs revelou que os dois comandos PHPUnit de integração do workflow legado terminavam sem asserções: o `phpunit.xml` offline forçava um banco desconectado e provocava skips. A correção `bb75ece9834b521cc1494b46e132a7755223d20f` mantém esse padrão seguro na suíte geral, mas executa os dois testes dedicados com configuração explícita do job descartável e `--fail-on-skipped --fail-on-warning`. O run `44200772489` concluiu com sucesso em PHP8.3/8.4. Os logs PHP8.4 confirmam o leitor com 3 testes/12 asserções e o escritor com 2 testes/12 asserções, além dos 67/21/39 checks SQL e do rollback completo. O sucesso anterior não é contado como execução desses adaptadores.

## Continuidade: pacote técnico privado da versão corrente

`SiteReleasePreparation` prepara os bytes exatos do artefato já associado no banco para revisão técnica interna. Ele combina os validadores existentes de digest/HTML inerte com a fonte PDO atual, verifica organização/pedido/contrato/produto/site/release/revisões e relê o vínculo antes de concluir. Falta de fonte, alteração de bytes, nova revisão ou revogação recusam o pacote. O método não escreve no banco e a fonte padrão continua negando acesso.

O pacote contém somente `index.html`, `release-manifest.json` e `readiness.json`. O manifesto identifica digest, tamanho e revisões; os flags de publicação, aceite oficial e autoridade comercial permanecem falsos. A preparação exige revalidação atual para qualquer etapa posterior e não constitui recibo de aceite ou autorização de publicação.

O CLI candidato em `ops/release-candidate/prepare.php` usa entradas privadas em caminhos fixos, conexão de leitura pinada ao banco/login/endereço esperados, CA sob custódia e TLS `verify-full`. Ele recusa campos extras, chaves JSON duplicadas, caminhos alternativos, symlinks, permissões inseguras e sobrescrita. A escrita prepara os três arquivos em diretório privado, verifica bytes e hashes e move o conjunto para um novo destino; uma falha não entrega arquivos parcialmente escritos como pacote válido. Nenhum material de cliente ou credencial faz parte do repositório.

O contrato descartável passa a gerar uma CA e um certificado de servidor válidos somente para o ensaio; conexões de todos os atores usam `verify-full`. Um teste negativo exige a falha específica de verificação do certificado antes de qualquer escrita SQL. O fixture do pacote executa renderização, gravação de artefato, revisão editorial e associação reais; o fixture do CLI prepara os caminhos privados apenas se estiverem ausentes, invoca o comando original em outro processo e verifica bytes, modos, hashes, ausência de escrita SQL, recusa de sobrescrita e revogação corrente. Sua execução completa depende do novo CI e não é substituída pelos 40 checks offline de parsing e custódia.

O guard SQL do registry também passa a verificar privilégios indevidos nas quatro tabelas de custódia/outbox que faltavam no inventário: `db_login_bindings`, `editorial_operator_bindings`, `replay_nonces` e `site_outbox`. O contrato exige a recusa específica do guard e a preservação do histórico em cada caso.

**Execução confirmada da preparação:** código `5afa3b653061dfcaac176b2204801cd5a619850a`, 16 check-runs aprovados. Run `44201915769`, job SQL `134457907349`: 270 asserções do registro, incluindo 27 da preparação técnica e 36 da CLI real; 250 do workspace; 40 offline da preparação; 26 do provisionador; PHPUnit 73 testes/220 asserções/27 skips explícitos na suíte geral. CA incorreta e recibo de limpeza com inode divergente foram recusados antes de escritas/remoções; os três marcadores de conclusão são obrigatórios no runner. Job HTTP `134457907888` e artifact `14381821977` confirmam 56 checks do navegador. SHA-256 do ZIP: `0d6caf73d43b1e09e73657c6f389b3714e110553c71be1deade99acb9edb9a29`. Filtros desktop/mobile revisados visualmente, sem rótulos cortados. A matriz legada `44201915714` também passou em PHP8.3/8.4 com testes dedicados sem skips.

## Continuidade: comparação editorial e verificação de pacotes existentes

A comparação seleciona duas versões persistidas do mesmo projeto e exibe briefing anterior/novo por campo, incluindo a ordem dos benefícios. Empresa, projeto, revisão, digest, HTML e autorização são conferidos na leitura; a seleção pode alcançar versões fora da janela atual do histórico. O briefing de edição permanece na revisão corrente. A tela usa texto escapado, navegação GET local e colunas empilhadas no mobile; comparar não registra parecer nem muda qualquer estado.

A operação `verify` do CLI lê os três arquivos de um pacote privado existente e compara seus bytes com o pacote reconstruído a partir da associação SQL atual. Nenhum campo do JSON exportado é tratado como autoridade. Ela recusa adulteração, arquivos extras/ausentes, troca da associação, revogação e reabertura editorial, preservando arquivos e histórico. A verificação dispensa o mount do HTML original e mantém `canPublish=false` e `canAcceptRealOrders=false`.

**Execução confirmada da comparação e da verificação:** código `ad649b3f10bdee93837e9443efc9bf4b1edeb85c`, 16/16 check-runs aprovados. [Run 44203027317](https://github.com/rafhael100/imagine-sites/actions/runs/44203027317): 287 checks do workspace (37 da comparação), 295 do registro/ciclo/pacote (61 da CLI), 50 offline do pacote, 26 offline do provisionador e 85 HTTP/navegador. PHPUnit geral: 80 testes/312 asserções/27 skips explícitos; as integrações SQL são executadas pelos jobs dedicados. A matriz de migrations/rollback PHP8.3/8.4 `44203027314` também passou. Artifact `14382942257`, SHA-256 `00074b8bddf202f56cb09b7ce9db531b436538ac883295e7843a6f00b7cc8e9f`; comparação desktop/mobile revisada visualmente, com campos alterados legíveis, texto escapado e sem overflow. O browser sem JavaScript comparou versões1→60 mantendo o formulário na head61, e a edição posterior criou62 usando a revisão corrente.

O ensaio usa HTML produzido pelo renderer existente, novas revisões pelo writer Laravel real e uma associação lida pelo PDO restrito. Uma revogação SQL entre as duas leituras testa a recusa com autoridade real do laboratório. O CLI recebe materiais TLS e credenciais sintéticos apenas no runner descartável; a implementação não oferece caminho de staging sem verificação de certificado.
