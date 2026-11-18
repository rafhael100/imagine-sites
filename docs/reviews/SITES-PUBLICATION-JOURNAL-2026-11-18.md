# Imagine Sites — diário persistente de tentativas

## Problema e entrega

Uma falha de processo ou a perda da resposta do provedor pode deixar uma tentativa com resultado desconhecido. Sem um registro durável, outro processo pode tratar a operação como nova e duplicar o efeito. A nova branch `feature/sites-publication-journal-2026-11-18`, baseada no HEAD `ad649b3f10bdee93837e9443efc9bf4b1edeb85c` da PR #490, acrescenta o diário persistente desse estado técnico.

A migration000007 cria `sites_publication` com principals controlados, intenções imutáveis e eventos append-only. O wrapper PDO oferece request, tentativa, ACK, reconciliação de prazo vencido e leitura. O vínculo com a release é derivado do registry atual, sob o mesmo lock do ciclo editorial; o cliente da API não fornece os dados de contrato, produto ou digest como autoridade.

A primeira tentativa impede outra tentativa registrada para o mesmo site, inclusive com nova chave ou operação. Uma expiração e um ACK tardio conservam o estado inconclusivo. A revogação do binding ou a reabertura editorial não apagam o histórico nem impedem o registro tardio por um principal ainda autorizado na época original.

## Fronteiras

O journal é escrituração técnica. A referência declarada de aceite não comprova consentimento; o ACK não comprova publicação; o token não autoriza envio. Replays podem devolver o mesmo token a processos com o mesmo login e não garantem envio único ao provedor. Todos os snapshots mantêm `canPublish=false` e `publicationVerified=false`.

Nenhum provider, transporte, rota ou worker foi instalado. Não existe transição para verified, retry, desbloqueio ou rollback automático. Os três papéis internos são NOLOGIN e a migration não cria principals ativos. Publicação e recuperação definitiva ainda precisam de integração autenticada e observação independente.

Os dez gates permanecem BLOCKED. A entrega é isolada em draft e não modifica a VPS, o stack existente na porta18090, DNS, Cloudflare, dados reais ou credenciais. O rollback SQL recusa qualquer principal ou histórico existente e exige revisão de recuperação.

## Verificação preparada

- Suíte offline do journal: parâmetros, respostas estritas, identidade e recusa por padrão, sem autoridade SQL simulada.
- Fixture PostgreSQL18 real: migration e vínculos derivados do registry; idempotência, CAS, token, época, expiração, ACK tardio, isolamento de empresa e imutabilidade.
- Concorrência: dois processos PHP e conexões SQL disputam operações diferentes do mesmo site; exige um único evento de tentativa e um conflito.
- Matriz dedicada PHP8.3/8.4, restrita ao draft, branch e base exatos, com ferramentas PG18 extraídas de repositórios assinados e TLS verify-full.
- Regressões do workspace, registry, pacote técnico e suíte Laravel; inventário F1-383 mantém a lista exata de 24 políticas internas do core e verifica as três tabelas vazias/roles NOLOGIN do journal.

Validação local executada: lint PHP e Bash, diff sem erros de whitespace, 99 verificações offline do journal, 32 de domínio, 26 do provisionador e 50 do pacote. PHPUnit: 80 testes, 312 asserções e 27 skips explícitos dos cenários que exigem banco ou opt-in. A revisão independente do SQL, migração e fixture não identificou defeito bloqueante; isso não substitui execução do banco.

As execuções SQL desta nova entrega ainda estão pendentes de CI. Não foi iniciado PostgreSQL local. A evidência da base permanece disponível na [PR #490](https://github.com/rafhael100/imagine-sites/pull/490): HEAD ad649b3, 16/16 check-runs aprovados, 287 verificações SQL do workspace, 295 do registry/pacote e 85 do navegador.

## Próximas dependências funcionais

Este diário permite conservar tentativas e classificá-las como inconclusivas, mas ainda não publica. O transporte precisa definir deduplicação com o provider e tratamento de commit/resposta perdida. A observação independente precisa provar a release exata no destino antes de qualquer resolução ou nova tentativa. Aceite, liquidação e revogação continuam vindo de fontes próprias.

O envio de briefing do Portal ao Sites não foi inventado nesta entrega: os documentos Portal inspecionados ainda tratam o envelope F48 como metadados de preview e remetem o briefing a contrato candidato. A produção durável do briefing e o worker correspondente devem respeitar esse contrato quando ele estiver definido.

Detalhes de API, estados e privilégios: [contrato do journal](../../contracts/publication-journal/README.md).
