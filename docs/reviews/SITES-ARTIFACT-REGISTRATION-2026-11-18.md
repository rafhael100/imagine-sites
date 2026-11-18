# Imagine Sites — registro privado de um artefato exato

## Caminho funcional

O registry já possui uma API SQL/PHP para registrar bytes imutáveis, mas sua entrada era exercitada apenas pelos ensaios de integração. O pacote `prepare`/`verify` depende de um artefato registrado e de uma associação editorial posterior. Esta entrega torna a primeira etapa utilizável por uma CLI privada, mantendo as responsabilidades separadas.

Branch `feature/sites-artifact-registration-2026-11-18`, sobre a PR #497 / `fb97fa465d162c23fa1ced8d1e0092ecc30798b7`. A interface é `php ops/release-candidate/register.php plan` e `record`, com confirmação explícita do hash do plano e uma flag exclusiva para registro. O comando reutiliza `PgSiteReleaseRegistry::recordArtifact`; não cria outro contrato de persistência.

## Entrada e resultado

O manifesto `SITES_RELEASE_REGISTRATION_V1` contém somente alvo SQL, projeto, referência da release, digest e tamanho esperados. Não recebe tenant, produto, contrato, pagamento ou aceite como fonte de autoridade. Os identificadores selecionam o recurso; a função SQL existente deriva a empresa e verifica o principal e o projeto.

Manifesto, senha, CA e HTML têm quatro caminhos privados fixos. A custódia existente exige arquivos regulares, sem links, com permissões restritas e proprietário permitido. O wrapper conserva bytes, SHA-256 e identidade dos arquivos, relendo-os após a conexão e o planejamento. Qualquer mudança observada impede a chamada de gravação. O HTML precisa ser UTF-8 válido, sem NUL, com no máximo 150000 bytes, digest/tamanho exatos e auditoria inerte aprovada. O banner de `sites_preview` não é aceito como artefato comercial.

O plano possui sete campos públicos e não retorna HTML, caminhos, credenciais ou referências de empresa/projeto/release. A gravação acrescenta somente o booleano `created`. `canPublish` e `canAcceptRealOrders` permanecem falsos nas duas respostas.

## Plano e execução

A conexão é nova, não persistente, com TLS `verify-full`, CA pinada, `pg_catalog`, UTF-8, isolamento read committed e timeouts. A observação compara PostgreSQL 18, banco/OID, login, endereço/porta do servidor, TLS e a capacidade de executar exclusivamente `record_release_artifact` entre as APIs de core e diário, sem grant option.

`plan` usa sessão somente leitura. Seu hash vincula o manifesto canônico completo e cada byte do HTML, com prefixo próprio e delimitadores. O planejamento não chama a função mutável, não lê principals privados, não reserva a release e não comprova existência do projeto, autorização corrente, contrato ou pagamento.

`record` recalcula o plano em sessão somente leitura, exige o hash revisado e `--authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED`, e reconfere os quatro arquivos. Depois habilita escrita apenas nessa conexão e chama o serviço uma vez. A função SQL continua responsável por seus guards, escopo por empresa, epoch, projeto e idempotência. O serviço confere o resultado e observa novamente a conexão.

Uma repetição exata pode retornar `created=false`, conforme o contrato já existente para a mesma identidade, bytes, autor SQL e epoch. Bytes diferentes, outro autor ou época diferente não substituem silenciosamente o registro. O comando não faz retry automático, renovação, mudança de permissão ou reparo.

## Resultados inconclusivos

O erro público é sempre `RELEASE_REGISTRATION_DENIED`, com exit 1 e stdout vazio. Isso limita a exposição de dados; não é garantia de que uma falha posterior à chamada SQL desfez o commit. Perda de resposta ou falha de observação após a chamada podem exigir investigação autorizada do resultado persistente. O comando não oferece consulta de artefatos, e a inspeção de operações do diário não substitui essa consulta.

## Verificação e limites

Os ensaios offline passaram **271 verificações do serviço e 65 da CLI**, cobrindo entrada, hash, recusa de ambientes/argumentos e falhas anteriores à conexão. Não fabricam um resultado positivo de SQL. Regressões de inspeção (150) e preparação (50), sintaxe e revisão cruzada do backend/wrapper também passaram.

A fixture real acrescenta **54 verificações esperadas**, usando PostgreSQL 18/TLS descartável, a CLI efetiva e principals de funções distintas. Cobre plano sem escrita, registro de um único artefato, repetição, conflito, isolamento, expiração/revogação e posterior consumo por `prepare`/`verify` após transições editoriais e associação feitas separadamente. A contagem integrada prevista do registry é 532.

O helper de custódia recusa caminhos preexistentes e usa criação exclusiva. A origem do material sintético e o recibo são lidos por descritores de diretórios/arquivos, sem seguir links, com proprietário, modo, tamanho e identidade conferidos antes/depois. A revisão encontrou e corrigiu a leitura inicial que seguia links nos filhos do diretório temporário. A limpeza valida o recibo completo e os filhos do diretório privado antes de remover somente seus arquivos; não remove arquivos alheios nem faz exclusão recursiva.

O workflow fixa repositório, draft, branch e SHA da base, em PHP 8.3/8.4. Mantém os quatro opt-ins explícitos do registry, diário, inspeção e registro para verificar também a convivência dos arquivos privados e a ordem de limpeza. **CI desta entrega pendente; PostgreSQL local não foi iniciado.** Contagens e SHAs de execução serão registrados após o ensaio.

Não há migration, novo schema, grant, login operacional, binding, transição editorial, envio a provider ou alteração no staging. As condições para briefing oficial continuam documentadas em [Próxima integração central](SITES-NEXT-INTEGRATION-2026-11-18.md); elas não impedem esta interface de uma API existente. O [manual da CLI](../../ops/release-candidate/README.md#explicit-artifact-registration-candidate) descreve a custódia e os comandos.
