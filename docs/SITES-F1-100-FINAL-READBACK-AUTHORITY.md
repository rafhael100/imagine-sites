# F1-100 — Segunda avaliação de autorização antes da releitura privada

## Objetivo e mudança

A F1-96 negava a leitura quando a autoridade fictícia Portal estivesse revogada **na entrada**, mas a decisão podia mudar durante a consulta PostgreSQL + arquivos privados. A F1-100 acrescenta uma segunda avaliação independente de `SiteProjectAdmission` **após** confirmar a última revisão no banco e a segunda conferência do pacote privado, e **antes** de confirmar a transação do leitor.

Uma negativa ou falha do gateway nesta fase gera `lab_latest_draft_reader_final_authority_denied`, faz rollback e não devolve metadados. A primeira autorização continua obrigatória e o gateway default permanece DENY.

## Evidências de teste

O teste PG18 `tests/f95-pg-verified-draft-revisions.php` revoga programaticamente a autorização fictícia **na segunda chamada** do gateway e exige negativa, transação desfeita e nenhuma nova revisão. Depois restaura a permissão fictícia, que permite a mesma releitura canônica. A suíte existente executa em PHP 8.3/8.4 no workflow `sites-f1-94-project-admission.yml`.

## Limites e gates

Esta alteração não conecta a um IAM Portal real, não autentica o cliente, não fornece evento de revogação distribuída e não impede uma revogação **após** a última avaliação. O contexto de tenant PostgreSQL continua forjável por SQL direto; advisory locks não são lease de filesystem. Nenhum HTML é retornado, nenhum aceite/publish é produzido. Executa apenas CLI/testing com banco efêmero e flags existentes; os 10 gates de produção permanecem BLOCKED. Sem merge, deploy, VPS, dados reais ou novas despesas.
