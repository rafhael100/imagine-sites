# F1-80 — Releitura verificável da decisão editorial persistida (PostgreSQL de laboratório)

A F1-79 insere decisões editoriais com isolamento RLS, porém o simples retorno do comando INSERT não constitui prova de que a evidência persistida ainda corresponde ao rascunho revisado. A F1-80 acrescenta uma etapa explícita de **read-back de consistência**.

`LabPersistedReviewEvidence::verify` opera somente em PHP CLI com flag `SITES_F79_PG_LAB=1` e banco PostgreSQL descartável `sites_f79_lab`. Ela exige a role restrita correspondente ao tenant do registro e verifica os campos persistidos: empresa, site, workflow, release, revisão, ação, referências, digest do HTML, bundle, dossiê e decisão, além das flags de exclusão de publicação/aceite. Divergência, inexistência, acesso cross-tenant e erro de banco falham por padrão.

Um comprovante informativo de laboratório retorna somente referências e hashes, com `persistedInDisposableLab=true`, `portalAuthorityRequired=true`, `officialClientAcceptance=false` e `publishable=false`. Ele **não autoriza criar um candidato de revisão do Portal**, emitir entrega ou publicar. Isso exigirá identidade, revogação e autoridade operacional independentes.

O teste F1-80 é executado após o F1-79 no mesmo runner PostgreSQL 18, em PHP 8.3/8.4. Ele testa prova ausente, papel de administrador negado, tenants cruzados, workflow/release alterados, flag desligada, hashes exatos, persistência de retrabalho e detecção de modificação de hash pelo administrador do banco de laboratório (com trigger temporariamente desabilitado apenas no teste). Uma linha restaurada para o mesmo conteúdo volta a passar — portanto **ainda não há proteção contra rollback de backups antigos nem prova externa de imutabilidade**.

Esta etapa não define migration ou IAM de produção, não assina os dados e não trata informações de clientes. Os dez gates operacionais permanecem BLOCKED. Sem VPS, deploy, DNS, terceiros pagos ou merge.
