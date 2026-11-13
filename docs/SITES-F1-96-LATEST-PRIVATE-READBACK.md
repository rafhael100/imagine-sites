# F1-96 — Releitura privada da revisão vigente com PostgreSQL + verificação em disco

## Objetivo

A F1-95 implementa a gravação transacional de revisões. A F1-96 implementa a **operação complementar de leitura**: reabrir o pacote privado e comparar seus hashes com o **último registro persistido** de uma empresa/site previamente admitidos. Isso impede que um arquivo antigo ou alterado seja mostrado como revisão vigente, mas não substitui autenticação de cliente nem armazenamento imutável.

O serviço `LabVerifiedLatestDraftReader::read(command, directory)` opera exclusivamente em CLI, `APP_ENV=testing`, PostgreSQL descartável de nome fixo, role não privilegiada `sites_f94_app` e flags F1-94/F1-95/F1-96 ativadas. Por padrão, `PortalProjectAuthorityGateway` **nega toda leitura**. Nenhuma rota HTTP ou resposta contendo HTML é criada; o retorno possui apenas os IDs e hashes verificados para revisão interna.

## Fluxo de leitura

1. Revalida a autorização corrente do pedido e contrato, inclusive depois de uma gravação ou leitura anterior.
2. Reabre os quatro arquivos privados com `SyntheticPrivateDraftVerifier`, conferindo seu inventário, JSON canônico, SHA-256, dossiê e estado pendente.
3. Deriva a referência de site da empresa/pedido/contrato e verifica que a prova em disco pertence exatamente à empresa e site esperados.
4. Inicia transação PG18 e define contexto privado de empresa via `SET LOCAL`; exige no banco a mesma admissão e fingerprint de pedido/contrato/produto, com ciclo de vida elegível.
5. Consulta a **última** revisão de `sites_drafts`, exigindo igualdade da revisão vigente, workflow, release, hashes de HTML/dossiê e estado `pending_internal`.
6. Reabre os arquivos antes de concluir a transação. Responde somente com `storedRowAndPrivateFilesMatch=true`, revisão e hashes; sempre `officialClientAcceptance=false` e `publishable=false`.

Uma revisão anterior, diretório inexistente, arquivo alterado, empresa diferente, autorização revogada, role de banco privilegiada ou transação já aberta resulta em negação. O teste usa o banco e os pacotes escritos nas etapas F1-94/95 e não grava novos registros.

## Segurança e limites

O caminho de arquivos é **somente um diretório privado fixo de laboratório**. Esta operação não estabelece um endpoint público/Portal para clientes e não comprova que a pessoa autorizada viu ou aceitou determinada versão. A segunda leitura do filesystem reduz erros de mudança comum, mas **não** implementa snapshot/lease atômico entre banco e disco ou storage remoto; o resultado poderia ficar desatualizado imediatamente após a leitura. A GUC de tenant é controlável por SQL direto e não prova identidade.

A solução operacional exigirá gestão de snapshots imutáveis, autenticação e revogação atuais do Portal, assinatura/prova de visualização/aceite, segregação de storage, contratos e revisão AppSec. A F1-96 continua sendo um teste funcional incremental, sem cumprir os **10 gates operacionais BLOCKED**, sem despesas, dados reais, DNS, publicação ou merge.
