# F1-113 — Falha de permissões do armazenamento de nonces e recuperação segura

## Objetivo

Comprovar que o fluxo de `LabSignedProjectIntake` continua **fail closed** caso a base de nonces PostgreSQL fique temporariamente indisponível por uma alteração de privilégios, mesmo quando o comando tem HMAC sintética válida e a autoridade fictícia permitiria a admissão de um projeto.

## Evidência concreta

O teste integrado `tests/f111-pg-durable-signed-intake.php` usa as duas bases efêmeras do workflow F1-94: projetos em `sites_f94_lab` e nonces em `sites_auth_replay_lab`. Em uma etapa de laboratório, a conexão administrativa da base de nonces executa `REVOKE INSERT ON auth_replay.nonce_consumptions FROM sites_auth_runtime` e tenta consumir um **novo** envelope de projeto previamente assinado por meio de outra conexão com a role restrita.

O cenário exige:

1. A admissão ser negada enquanto `INSERT` estiver revogado, sem nova linha em `sites_project_admissions`.
2. A permissão `INSERT` ser restabelecida obrigatoriamente em `finally`, sem qualquer reconfiguração operacional.
3. A mesma mensagem originalmente recusada poder ser processada depois da recuperação, pois o nonce não foi consumido.
4. Uma terceira tentativa de usar aquela mensagem já processada ser recusada pelo armazenamento PostgreSQL persistente, sem criar linhas duplicadas.

A suíte executa com PHP 8.3 e PHP 8.4, PostgreSQL 18 descartável, conta SQL restrita e chaves/autoridades inteiramente fictícias. Nenhuma conta ou tabela de produção é alterada.

## Limites

A prova confirma uma falha de permissões específica e a recuperação no banco **descartável**, não um cenário completo de queda/rede/replicação. Se o armazenamento de replay consumir o nonce com sucesso e o banco de projetos falhar depois, não existe transação distribuída; a nova tentativa precisa de **novo nonce assinado**, após revalidação da autorização. Não há fonte IAM operacional, credenciais reais, gestão de chaves, backups, observabilidade, deploy ou publicação.

Os dez gates operacionais permanecem `BLOCKED`. Nenhum merge em `dev` ou `main`, alteração da VPS ou custo adicional.
