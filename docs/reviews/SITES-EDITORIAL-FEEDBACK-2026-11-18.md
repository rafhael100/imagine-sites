# Imagine Sites — orientação editorial por versão

## Problema resolvido

O revisor podia solicitar ajustes, mas não conseguia informar quais mudanças eram necessárias. O autor precisava obter essa orientação fora do workspace. Esta entrega acrescenta a observação ao próprio parecer, vinculada à versão e ao digest que foram revisados.

Branch `feature/sites-editorial-feedback-2026-11-18`, sobre a PR #491 / `b2210ec32064ceec2faf393e85117369e185e5a8`. A base do diário foi validada em PHP8.3/8.4, com 107 verificações próprias e 14/14 check-runs aprovados; as evidências estão na descrição da PR #491 e no relatório correspondente.

## Uso no workspace

1. O revisor independente abre a versão e escolhe o parecer. Para **Solicitar ajustes**, escreve a orientação; na aprovação interna, a observação é opcional.
2. O texto fica associado à revisão e ao SHA-256 exatos. O histórico preserva pareceres e observações anteriores.
3. O autor consulta **Orientações da versão atual**, inclusive enquanto navega por uma página antiga do histórico ou compara outras versões.
4. Ao gerar uma nova versão, o projeto aguarda um novo parecer. A orientação anterior continua no histórico da versão original.

O limite é de 2000 caracteres Unicode. Espaços e quebras de linha de um texto substantivo são preservados, e conteúdo semelhante a HTML aparece como texto escapado. Uma nota inteiramente branca não atende ao pedido de ajustes. O textarea não utiliza um limite UTF-16 que impediria 2000 emojis válidos.

Na validação HTTP422, texto e decisão permanecem no formulário da mesma revisão/digest. Se outra aba do mesmo revisor já registrou o parecer, o HTTP409 conserva o envio em um painel sem botão de reenvio, evitando aplicar o texto a outra versão. Essa preservação existe somente na resposta privada sem cache; não grava o conteúdo no cookie da sessão.

## Persistência e compatibilidade

A migration000008 aplica `contracts/private-preview/002-review-notes.sql`, sem modificar a migration000004 ou o contrato001 existentes. Acrescenta a coluna nullable `reviews.note` e uma API de seis argumentos. A assinatura antiga de cinco argumentos preserva OID, owner e ACL e delega com nota NULL: aprovações antigas continuam compatíveis; novas solicitações de ajuste sem orientação são recusadas.

Um CHECK NOT VALID preserva pedidos de ajuste legados sem observação, aplicando a exigência às novas escritas. Não há preenchimento retrospectivo ou alteração de parecer/audit existente. Os mesmos grants EXECUTE são copiados para a API nova, sem PUBLIC, delegação ou nova permissão de escrita direta. A disputa entre pareceres do mesmo acesso é serializada pelo lock de projeto e produz conflito, preservando o registro original.

O rollback só prossegue após provar, sob lock exclusivo, que nenhuma nota não nula existe. Se houver texto, recusa com `Preserve preview review notes; recovery review required`. Não usa CASCADE nem remove observações para permitir o rollback.

O leitor reúne head, janela de versões, pareceres históricos e pareceres atuais em um único snapshot SQL. Os grupos são limitados e conferidos por revisão/digest. O autor não recebe instruções de uma versão antiga apresentadas como se fossem da versão atual.

## Provisionamento

Uma instalação nova aplica migrations000004/000008 no mesmo owner e na mesma transação, com dois registros de ledger no mesmo batch. O fingerprint do contrato abrange os dois SQLs. O inventário exige seis assinaturas exatas, e o marcador passa a `SITES_PREVIEW_MANAGED_V2`.

Uma instalação anteriormente gerenciada como V1 é recusada com `managed_upgrade_required`. O provisionador não transforma esse estado automaticamente nem altera seus dados/grants. A aplicação da migração aditiva e a atualização de uma instalação existente exigem procedimento de upgrade próprio revisado. O manifesto de exemplo permanece deliberadamente inelegível para uso operacional.

## Verificação

Validação local executada: sintaxe PHP/JavaScript/Bash, YAML e whitespace; PHPUnit **102 testes, 509 asserções, 27 skips explícitos** dos casos que exigem banco/opt-in; 26 casos offline de provisionamento, 99 do diário e 50 do pacote.

Fixtures reais preparadas para CI:

- Upgrade de reviews legadas pelo entrypoint antigo, preservando bytes, OID, owner, ACL e audit; recusa de nova orientação vazia; down/up sem notas.
- Notas Unicode, limite de 2000 codepoints, duplicata, empresa cruzada, revogação, digest divergente, head fora da página e rollback recusado com texto.
- Jornada HTTP com FPM/Nginx/PG18/TLS, duas abas, erros422/409, texto com bordas preservadas, HTML escapado, parecer histórico, nova versão sem herança e 2000 emojis pelo formulário real.
- Capturas desktop/mobile do painel e verificação de overflow com nota longa.
- Regressões existentes do workspace, comparação, registry, pacote, diário e migrations Laravel.

**CI SQL/HTTP desta entrega pendente.** Não foi iniciado PostgreSQL local. O workflow novo é restrito à branch/draft/base exatos e executa a matriz SQL em PHP8.3/8.4.

## Limites

O parecer continua interno à Imagine e restrito ao namespace de demonstração. Não representa aceite do cliente, liquidação, pedido comercial ou autorização de publicação. Nenhum dado real, migração de staging, merge, deploy ou alteração de VPS/DNS/Cloudflare faz parte desta entrega; os dez gates operacionais continuam BLOCKED.
