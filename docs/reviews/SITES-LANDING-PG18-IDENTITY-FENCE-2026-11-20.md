# Imagine Sites — identidade sob revogação concorrente (PG18, somente CI)

Continuação isolada de [Sites #566](https://github.com/rafhael100/imagine-sites/pull/566) (base imutável `f42b407dedb948d1ac8f4047acc140f920bf5cdd`). As provas originais [#541](https://github.com/rafhael100/imagine-sites/pull/541) e [#542](https://github.com/rafhael100/imagine-sites/pull/542) continuam em draft, sem alteração de suas branches.

## Falha e correção proposta

Antes desta mudança, `record_pending` consultava a role escritora em `principals` sem bloquear a linha de autorização. Uma transação independente poderia revogar o principal depois da leitura inicial e antes do COMMIT do artefato. O mesmo risco existia para a identidade do revisor e, no diário de revisão, para o autor do artefato que servia de base à observação.

A proposta mantém bloqueios `FOR SHARE` em registros de identidade autenticados pelo PostgreSQL até o commit da operação. A custódia bloqueia o seu principal de escrita; o diário bloqueia o revisor e a identidade do autor, além do bloqueio `FOR UPDATE` existente na admissão do site. Transações que pretendam revogar esses registros mediante `UPDATE` precisam aguardar a conclusão da operação, ou vice-versa. Falha de contenção `lock_timeout` é negada sem persistência, sem vazar dados privados.

## Testes de concorrência

O teste inicia a transação `postgres` separada com `FOR UPDATE` no registro da identidade, e tenta utilizar a sessão não proprietária correspondente com timeout de 300 ms. São três negativas independentes:

1. Writer `sites_landing_ci_a` não consegue armazenar artefato quando sua identidade está bloqueada;
2. Reviewer `sites_landing_ci_reviewer_a` não consegue registrar observação quando sua identidade está bloqueada;
3. Revisor também não consegue registrar observação quando a identidade do autor `sites_landing_ci_a` está bloqueada.

Depois do `ROLLBACK` dos locks artificiais, o caminho positivo de custódia e revisão existentes permanece testado. A matriz de CI usa PostgreSQL 18 descartável, PHP 8.3/8.4 e o Portal candidato #1019 fixado no SHA `4a5e1e06376188f85c90bee3d10056ab3ce2302a`.

## Limites e política de segurança

É uma correção **local** dos candidatos PG18, não uma aprovação humana, fonte transacional oficial do Portal, identidade S2S operacional, sincronização distribuída, garantia de readback futuro, autorização de publicação ou aceite. As tabelas e principals permanecem sintéticos. O `STATUS-ATUAL.md` histórico e as migrations produtivas não são alterados. Não há deploy, merge, VPS, DNS, Cloudflare, Asaas, pagamentos nem fornecedores. Dez gates permanecem bloqueados; evidências reais de CI devem constar na PR após execução.
