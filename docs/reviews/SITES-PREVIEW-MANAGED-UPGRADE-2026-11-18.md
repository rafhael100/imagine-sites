# Imagine Sites — atualização explícita do preview gerenciado

## Lacuna resolvida

A instalação nova do workspace já aplica o contrato com orientações editoriais, mas o provisionador recusa corretamente uma instalação gerenciada pelo contrato anterior. Esta entrega acrescenta um caminho próprio de planejamento, aplicação e verificação da transição V1 → V2, preservando o histórico existente.

Branch `feature/sites-preview-managed-upgrade-2026-11-18`, sobre a cadeia da PR #493. A implementação é um candidato de CLI e CI. Nenhuma instalação V1 no VPS foi confirmada ou alterada.

## Interface

| Operação | Resultado |
| --- | --- |
| `upgrade-plan` | Inspeção somente leitura; plano vinculado à origem, destino, identidade SQL e estado gerenciado |
| `upgrade-apply` | Transição aditiva após conferir autorização explícita e SHA do plano |
| `upgrade-check` | Verificação somente leitura de uma instalação que passou pelo upgrade |
| `check` existente | Continua verificando V2 nova e também V2 com origem de upgrade coerente |

O manifesto específico usa `SITES_PREVIEW_UPGRADE_V1`, hashes de origem e destino, target exato e os mesmos principals já existentes. O arquivo fica no caminho fixo `/run/secrets/preview_upgrade`, sob as mesmas verificações de custódia do candidato anterior. O exemplo publicado contém placeholders inelegíveis e nenhuma credencial.

A aplicação exige `--authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED` e o SHA do plano. Essa opção evita uso acidental; não representa aprovação operacional. Opções da instalação nova não autorizam upgrade, e comandos de leitura recusam opções de escrita. As saídas contêm apenas estados, ações, contagens e fingerprints; os campos de autoridade permanecem falsos.

## Origem e compatibilidade

Somente o contrato V1 exato de `001-workspace.sql` é aceito: SHA-256 `5ecfa4e626c622b387047d51e3a672b733d5830283b4e34d847f7469df5279eb`. O destino é o bundle V2 de 001 e 002: `d0c26c3bcc71c0615ad8efeeb6ebc1b10b9bede37a82d90173a913863ffc08d5`.

A inspeção exige o owner existente NOLOGIN/NOINHERIT, identidade efetiva e TLS verify-full, marcador V1 íntegro, seis objetos relacionais, cinco assinaturas de função, ausência da coluna de nota/API de seis argumentos e um único registro válido de migration000004. ACLs, privilégios efetivos, bindings, prazo e revogação são conferidos antes da transição. Não há reparo automático de deriva nem alteração de empresa/perfil/validade.

O `apply` original continua recusando managed V1. Um ambiente V2 criado diretamente não é apresentado como se tivesse sido atualizado. A operação de upgrade repetida exige um plano novo do estado já atualizado e retorna no-op.

## Preservação e transação

A mudança executa somente a migration000008 existente sob o owner do namespace, sem modificar 001, 002, 000004 ou 000008. Não cria owner ou LOGIN, não concede database CREATE, não reinsere principals e não renova acessos. A própria migração copia para a API nova apenas os EXECUTEs já autorizados na assinatura antiga.

O mesmo advisory lock do provisionamento inicial serializa essas operações. Locks no ledger e nos principals estabilizam as escritas relevantes; o plano é conferido novamente dentro da transação, com timeouts delimitados.

A linha original de migration000004 mantém seu ID e batch. Somente migration000008 é acrescentada, em um batch posterior escolhido sob lock. O marcador V2 passa a registrar origem, fingerprint estrutural anterior e os IDs/batches das duas migrações. Instalações novas sem essa origem continuam exigindo as duas migrações no mesmo batch. O plano vincula o ledger do preview; migrações alheias não são alteradas.

DDL, registro novo do ledger, marcador e verificação final são confirmados juntos. Uma falha reverte essas mudanças. A sequence do ledger pode consumir um valor antes da falha; não há tentativa de reescrever seu contador para eliminar lacunas.

A origem registrada é informação sob custódia do owner/DBA e vinculada ao plano. Ela não constitui uma prova externa contra um administrador privilegiado. O verificador confere a estrutura V2 corrente, a forma estrita da origem e sua coerência com os registros do ledger.

## Limite de compatibilidade editorial

Reviews e auditoria anteriores permanecem intactos, inclusive solicitações de ajuste legadas com nota NULL. A assinatura de cinco argumentos continua aceitando aprovação interna sem nota, mas novos pedidos de ajustes sem orientação passam a ser recusados. Uma futura aplicação operacional precisa coordenar o código da interface com a migração.

Não há comando automático de downgrade. A migração já recusa remover notas persistidas; voltar a uma imagem antiga não autoriza apagar orientação ou histórico.

## Verificação

As verificações offline cobrem manifestos e argumentos sem abrir conexão ou ler segredos: 40 casos do upgrade, 44 da CLI e os 26 casos anteriores de provisionamento. A evidência SQL/CLI real desta entrega ainda está pendente.

O novo job parte de um segundo checkout do provisionador V1 original em `ad649b3f10bdee93837e9443efc9bf4b1edeb85c`, carregado em processo separado. Isso cria uma origem antiga real; não se limita a trocar o nome de um marcador V2. O banco e todos os dados são descartáveis e sintéticos, com a identidade do cluster conferida antes de qualquer escrita.

A fixture cobre histórico populado, duas empresas, registros antigos sem nota, preservação de OID/ACL/bytes/ledger, recusas por deriva, falhas após mudanças transacionais, invocação efetiva da CLI, concorrência de planos e uso da orientação após o upgrade. O provisionamento novo e a jornada HTTP anterior também são reexecutados como regressão.

Resultados, HEAD, contagens efetivas e limitações serão registrados após o CI. Não foi iniciado PostgreSQL local nem executado comando no VPS.

## Fronteira operacional

O procedimento permanece no namespace de demonstração. Contrato, pagamento e aceite do cliente continuam no Portal; o upgrade não instala publisher, não habilita rollback automático e não altera os dez gates operacionais. A infraestrutura existente, a porta18090 e os demais serviços ficam fora desta entrega.

