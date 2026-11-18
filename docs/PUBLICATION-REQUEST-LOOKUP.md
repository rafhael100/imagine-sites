# Recuperação privada de solicitação de publicação pelos cinco seletores originais

**Candidata de 18/11/2026.** Quando o registro técnico de uma solicitação é
persistido e a resposta/recibo se perde, a inspeção antiga exige conhecer o
`operationRef`, que pode não estar disponível. Este incremento recupera o
**mesmo evento existente** pelos cinco campos usados para solicitar a operação.
Não executa publicação ou reenvio, nem certifica aceite/pagamento.

## Seleção e resultado

`sites_publication.read_request(uuid,text,bigint,text,text)` recebe exatamente:

1. `siteRef` UUID do site;
2. `releaseRef` textual da release;
3. `expectedBindingRevision` positivo;
4. `portalAcceptanceRef` textual do comprovante **não autenticado por essa API**;
5. `idempotencyKey` textual original.

A função resolve `tenant_id` pelo principal leitor **atualmente autorizado**,
localiza exclusivamente a chave de idempotência naquele tenant e exige igualdade
dos outros quatro seletores antes de emitir a projeção técnica de **24 campos**.
Ausência ou divergência devolve SQL NULL; acesso revogado, função indevida e
runtime inseguro são recusados. A coluna `operationRef` histórica só é
retornada a esse leitor privado, nunca por uma API pública de cliente.

O método `PgPublicationJournal::readByRequest` faz uma única consulta sem
retry. `PrivatePublicationRequestLookup::readExact` acrescenta validação do
alvo e CA, identidade de sessão, TLS verify-full observado, modo somente leitura,
EXECUTE exclusivo da nova função, e conferência de identidade antes/depois.
O chamador deve fornecer uma conexão PDO nova, previamente configurada; a
classe não cria login, DNS, sessões ou grants.

O retorno pode representar `requested`, `dispatched`, `acknowledged` ou
`inconclusive`, exatamente como gravado no diário. Mesmo um ACK não é prova
de que o site esteja publicado. `canPublish=false` e
`publicationVerified=false` permanecem fixos, e ausência/recusa **não**
comprova que a escrita anterior não ocorreu.

## Persistência, escopo e rollback

A migration 16 usa `contracts/publication-journal/002-read-request.sql` para
adicionar uma função security-definer pertencente ao papel interno
`sites_publication_reader` (NOLOGIN). Como o guard antigo lista cada função
admissível, a mesma migração atualiza só sua whitelist de leitura, assinatura
e verificação de owner. Escrita, reconciliação, grants e schema do diário
continuam inalterados; **nenhum login recebe novo EXECUTE automaticamente**.

A reversão `002-read-request-down.sql` remove apenas a nova função e repõe
o guard exato anterior. Não usa CASCADE nem altera, exclui ou restaura registros,
atores ou eventos. Rollback com dados **não** é justificativa para apagar o
diário: os dados antigos permanecem e o leitor anterior continua disponível.
Antes de executar a reversão é necessário confirmar a migração aplicada e a
identidade administrativa autorizada.

## Evidência e limites

`tests/publication-request-lookup-offline.php` cobre entradas inválidas e
recusas limitadas, sem simular uma resposta de banco. A fixture
`tests/fixtures/publication-request-lookup/contract.php` deve executar em
PostgreSQL18/TLS totalmente descartável **após** a fixture do diário:
compara o snapshot original, leitura por empresas separadas, diferentes
estados, cinco seletores incorretos, revogação, privilégios extras, guard,
reversão e reaplicação, exigindo nenhum append em operações e eventos.

O workflow `sites-publication-request-lookup.yml` é draft-only, fixa a
base, executa PHP 8.3/8.4 e usa os clusters próprios do laboratório. Sua
execução e resultados devem ser conferidos separadamente antes de declarar
a candidata validada.

**Fora do escopo:** CLI de recuperação e recibo em arquivo, transporte Portal,
autenticação comercial, provider, publicação, merge, deploy, VPS, dados reais,
aceite de cliente, `ENTREGUE` ou alteração dos dez gates bloqueados. A CLI
privada poderá vir em etapa distinta, reutilizando este serviço e exigindo
custódia de material/arquivo sem inferir ausência após erro.
