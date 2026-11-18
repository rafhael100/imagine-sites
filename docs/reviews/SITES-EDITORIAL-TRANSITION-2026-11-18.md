# Imagine Sites — transição editorial explícita

## Caminho funcional

O registro de artefatos da PR #498 fornece bytes imutáveis ao registry, mas preparar uma release também exige revisão editorial corrente e associação posterior. A API editorial SQL e seu writer Laravel já existem. Esta entrega acrescenta uma CLI privada para planejar e solicitar uma transição por execução, preservando os operadores e as regras de persistência existentes.

Branch `feature/sites-editorial-transition-2026-11-18`, sobre a PR #498 / `dedbae34474e68f4ce9f1d28d457d730ec18998a`. A interface é `php ops/editorial-candidate/transition.php plan` e `apply`, com confirmação do hash revisado e `--authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED`. A execução carrega somente o autoloader Composer; não inicializa a aplicação, registra rota, cria worker ou altera startup.

## Entrada exata e custódia

O manifesto `SITES_EDITORIAL_TRANSITION_V1` contém somente `schema`, `target`, `siteRef`, `expectedRevision`, `action`, `artifactDigest` e `sizeBytes`. O alvo possui os sete campos já usados pelos candidatos privados: hostname, porta, banco, OID do banco, login, endereço observado do servidor e SHA-256 da CA. A revisão é um inteiro entre 0 e 2147483646; não aceita coerção. Campos extras, chaves duplicadas e coordenadas inseguras são recusados.

Manifesto, senha, CA e HTML usam quatro mounts privados fixos. O wrapper reutiliza `SiteReleasePreparationFiles::read`, exige custódia restrita e conserva bytes, digest e identidade dos arquivos. Após estabelecer a conexão e calcular o plano, relê os quatro inputs e recusa mudanças observadas antes de habilitar a escrita. O comando não repara permissões nem altera os arquivos.

O HTML precisa ter entre 1 e 150000 bytes, ser UTF-8 válido sem NUL, corresponder ao digest/tamanho declarados e passar pela auditoria inerte. Os bytes não são normalizados. A CLI não procura o artefato no registry e não o registra: ela usa os bytes para validar o compromisso editorial exato. A associação posterior deve ligar artefato, revisão editorial e release por sua própria API autorizada.

## Plano e autoridade SQL

`App\Editorial\PrivateEditorialTransition` recebe uma nova conexão PDO com TLS `verify-full`, CA pinada, `pg_catalog`, UTF-8, read committed e timeouts. A observação verifica PostgreSQL 18, TLS, banco/OID, login, endereço/porta e capacidade efetiva restrita à função editorial, sem grant option. A conexão começa somente leitura.

O plano não chama a função mutável, não consulta o head nem o duty privado e não comprova existência do projeto, operador ativo, estado corrente ou artefato registrado. Seu hash vincula o manifesto canônico completo e cada byte do HTML, incluindo alvo, revisão esperada e ação. O resultado possui exatamente oito campos: `schema`, `status`, `planSha256`, `action`, `artifactDigest`, `sizeBytes`, `clientAccepted` e `canPublish`. Os dois booleanos permanecem falsos.

`apply` exige as duas flags exatas, recalcula o plano em sessão somente leitura, compara o hash e reconfere a custódia. Somente então habilita escrita nessa nova sessão e chama uma única transição. O adaptador local do writer usa o mesmo PDO verificado, sem pool de leitura, eventos, log de queries ou reconexão que possa repetir a chamada automaticamente. A função SQL existente deriva tenant e duty do binding privado ativo e verifica projeto, revisão, estado e digest sob seu contrato atual.

As ações continuam `register`, `revise`, `submit`, `rework`, `approve_internal` e `reopen`. O nome do login não autoriza uma ação no PHP. Os dois logins permitidos no laboratório identificam apenas os atores sintéticos; a separação entre editor e revisor continua sendo decidida pelo SQL.

O resultado de apply tem exatamente dez campos: os oito campos técnicos do plano, com schema/status de resultado, mais `revision` e `state`. A revisão retornada deve ser a esperada mais um. O resultado não expõe site, login, HTML, caminhos, credenciais ou referências comerciais.

## Conflitos, resposta perdida e histórico

O erro externo é somente `EDITORIAL_TRANSITION_DENIED`, com exit 1 e stdout vazio. Uma falha durante ou depois da chamada SQL pode ocorrer após o commit. O erro sanitizado não é prova de rollback.

Transições editoriais não são idempotentes: uma chamada já efetivada torna obsoleta a mesma revisão esperada. O comando não faz retry e não incrementa automaticamente a revisão para tentar novamente. Uma resposta perdida exige investigação por acesso separado e autorizado ao estado/histórico editorial antes de outra decisão e de um novo plano. A CLI não oferece um lookup privado de head para resolver isso.

`reopen` acrescenta um draft e invalida a associação corrente à aprovação anterior; preserva o histórico e não transfere a aprovação para outro digest. Tornar um operador inativo impede novas ações desse operador, mas não apaga uma aprovação histórica nem equivale a revogar uma associação do registry. Revisão interna continua distinta de aceite expresso do cliente e de publicação.

## Custódia dos ensaios descartáveis

Os helpers de pacote, inspeção e registro passam a compartilhar `tests/fixtures/release-registry/private_material.py`, também usado pelo novo helper editorial. A senha e a CA de origem são acessadas por descritores, sem seguir links, com verificação de identidade, proprietário, modo e limites. Os helpers de registro e edição também conservam a leitura do próprio recibo por descritor. A criação e a limpeza continuam limitadas aos paths exatos de cada fixture e aos seus próprios recibos.

O teste offline `tests/fixture-private-material.py` passou **18 casos** de links, hard links, modo, proprietário, material inválido e substituição de inode antes do open ou após a leitura. Trabalha apenas em `TemporaryDirectory` próprio; não cria mounts de `/run` nem inicia banco. Essa correção comum não autoriza o uso dos helpers fora do runner descartável.

## Evidência concluída da PR #499

| Verificação local | Resultado |
| --- | --- |
| Serviço editorial offline | 307 checks aprovados |
| CLI editorial offline | 76 checks aprovados |
| Custódia comum offline | 18 checks aprovados |
| PHPUnit local | 134 testes / 1186 assertions / 27 skips explícitos |
| Sintaxe PHP e revisão cruzada de serviço/wrapper | Aprovadas |
| Exemplo JSON inelegível pelo parser real | Recusado como esperado |
| PostgreSQL/TLS e CLI editorial reais | 68 checks novos aprovados em PHP 8.3/8.4 |
| Registry integrado com a nova fixture | 600 checks aprovados em PHP 8.3/8.4 |

Os testes offline exercitam os parsers, o compromisso com os bytes, recusas e o isolamento do adaptador. Os testes da CLI limitam o subprocesso ao diretório do comando: help funciona sem autoload e os argumentos/ambientes recusados não podem acessar os inputs privados nem o banco. Eles não fabricam um resultado positivo de autoridade SQL.

A fixture real executou **68 verificações novas**, elevando o registry integrado de 532 para **600 verificações aprovadas**. Exercita as seis ações em sete revisões explícitas, operadores separados, plano sem mutação, CAS obsoleto, isolamento por tenant, operador inativo, TLS/CA e custódia. O caminho posterior registra artefatos por outro autor, associa a aprovação por um binder separado, prepara/verifica o pacote e comprova a invalidação após `reopen`. Snapshots completos verificam que cada apply acrescenta somente a revisão esperada e preserva o restante. Os dois jobs confirmaram essas contagens, os quatro negativos de recibo antes de qualquer remoção e a limpeza editorial → registration → inspection → package, com encerramento dos clusters descartáveis.

O workflow `.github/workflows/sites-editorial-transition.yml` fixa repositório, draft, branch e SHA da base. Executa PHP 8.3/8.4, os ensaios offline, PHPUnit e os contratos PostgreSQL descartáveis. Conserva os opt-ins do registry, diário, inspeção e registro, acrescentando `SITES_EDITORIAL_TRANSITION_CI=1`. Não adiciona jornada HTTP a esta entrega de CLI. **HEAD validada: `9789cd050e1d4b88b9c469149cb796985ed615a2`; 18/18 check-runs SUCCESS na conferência final.** O [run 44227302252](https://github.com/rafhael100/imagine-sites/actions/runs/44227302252) passou nos jobs [PHP 8.3 / 134543515207](https://github.com/rafhael100/imagine-sites/actions/runs/44227302252/job/134543515207) e [PHP 8.4 / 134543515677](https://github.com/rafhael100/imagine-sites/actions/runs/44227302252/job/134543515677). Ambos confirmam 307 verificações offline do serviço, 76 da CLI, 18 testes de custódia, 68 SQL/CLI editoriais, 600 registry, 54 registro, 76 inspeção, 386 workspace e PHPUnit 134 testes/1186 assertions/27 skips. O ensaio sintético com containers também passou. Nenhuma interface visual mudou nesta entrega.

### Base validada

A PR #498 foi validada em `dedbae34474e68f4ce9f1d28d457d730ec18998a`: **17/17 check-runs SUCCESS** na conferência atual. O [run 44225215421](https://github.com/rafhael100/imagine-sites/actions/runs/44225215421) passou nos jobs [PHP 8.3 / 134536616656](https://github.com/rafhael100/imagine-sites/actions/runs/44225215421/job/134536616656) e [PHP 8.4 / 134536617045](https://github.com/rafhael100/imagine-sites/actions/runs/44225215421/job/134536617045), com **54 checks novos de registro, 532 registry, 76 inspeção, 386 workspace e PHPUnit 134 testes/1186 assertions/27 skips**, além de **271 checks offline do serviço e 65 da CLI de registro**. Essa é a evidência da base, não da nova CLI editorial. O [relatório de registro](SITES-ARTIFACT-REGISTRATION-2026-11-18.md) registra os detalhes.

Não há migration nova, alteração de função SQL, grant, principal operacional, binding automático, envio a provider ou mudança no staging. O [manual editorial](../../ops/editorial-candidate/README.md) descreve caminhos, ações e comandos. Os requisitos independentes de integração, aceite e publicação continuam documentados em [Próxima integração central](SITES-NEXT-INTEGRATION-2026-11-18.md).
