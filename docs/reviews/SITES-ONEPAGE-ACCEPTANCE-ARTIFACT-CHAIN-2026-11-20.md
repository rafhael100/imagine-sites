# OnePage — arquivo privado e prova sintética de aceite exato (20/11/2026)

## Proveniência e lacuna não coberta anteriormente

- Sites [#541](https://github.com/rafhael100/imagine-sites/pull/541)/[#542](https://github.com/rafhael100/imagine-sites/pull/542): referências históricas; OnePage sucede via [#570](https://github.com/rafhael100/imagine-sites/pull/570) → [#571](https://github.com/rafhael100/imagine-sites/pull/571) → [#572](https://github.com/rafhael100/imagine-sites/pull/572), base exata `3464403f8bb0eede572a2d1558061b9ad5df791e`; Landing Page [#569](https://github.com/rafhael100/imagine-sites/pull/569) é independente.
- Portal [#976](https://github.com/rafhael100/imagine-portal/pull/976) continua com fontes oficiais *default-deny*. Portal [#1003](https://github.com/rafhael100/imagine-portal/pull/1003) / Sites [#552](https://github.com/rafhael100/imagine-sites/pull/552) já testaram contrato de troca e **não** são reimplementados.
- Interface HTTP privada e persistência demonstrativa em Sites [#486](https://github.com/rafhael100/imagine-sites/pull/486); aqui **não** há integração HTTP dessa interface nem falso aceite via tela. O componente preexistente [F1-391](https://github.com/rafhael100/imagine-sites/pull/473) verifica recibo Ed25519 e witness independente, mas não havia teste **combinado** que obtivesse SHA do HTML privado da OnePage por #572, criasse arquivo temporário com aqueles bytes e conferisse o aceite na mesma versão e o invalidasse após troca de artefato.

## Ensaio novo (sem conexão real)

`tests/onepage-private-acceptance-chain-offline.php` constrói **um único pedido fictício**, a partir dos exemplos existentes de snapshot e plano, com briefing versionado, comprador titular simulado, declaração de direitos e aprovação por revisor distinto. Reutiliza `OnepageBuyerPrivatePreviewCandidate`, `LocalSiteRendering`, `ReleaseManifest`, `SignedPortalClientApprovalGate` e `IndependentClientApprovalWitness`.

O HTML é materializado como **arquivo privado temporário CI 0600** e o SHA é obtido dos bytes de disco. A fixture de sessão é **inventada**: não representa comprador autenticado operacional. O aceite é um recibo Ed25519 **assinado com chave descartável na memória do runner**, com contexto MFA sintético e witness simulado independente. A prova é sobre a ligação lógica entre dados e bytes, não uma afirmação de autenticação real ou aceite jurídico.

Em seguida uma alteração editorial gera outra release e outro SHA por meio do renderer existente, sem modificar o briefing original. A evidência de aceite anterior é recusada tanto na nova release quanto na anterior após o witness independente mudar seu epoch. Outra pessoa compradora da mesma empresa não consegue ler a prévia; revogação do revisor bloqueia a geração da nova prévia. O HTML temporário anterior não pode ser usado como prova dos novos bytes. Repetição de regressões completas #570–#572 e de F1-391 foi evitada: este é um ensaio **de composição** e de mudança de release.

**Limitador deliberado:** o testemunho de revisão #572 cobre a versão do briefing, não a revisão editorial do artefato. A edição requer revisão humana nova, e o teste não simula obtê-la. Nenhuma nova release é aceita, publicada ou entregue. Não vincula o recibo de aceite a uma identidade nominal do comprador além da afirmação do emissor: o Portal terá de provar titular autenticado, sua sessão, autorização e CAS/epoch reais, auditar o aceite e revogação e emitir receipt assinado.

## Gates e continuidade

Fonte oficial imutável, bytes, confirmação de direitos e aprovação/revogação do Portal #976 **não demonstradas**; transporte S2S homologado, revisão editorial real, homologação de HTTP privado #486 e aceite do cliente real ausentes. Esta PR **não** acrescenta adaptação ao serviço; a fonte continua default-deny. Publicação técnica requer gate próprio, domínio somente na publicação, e um mecanismo independente de observação de SHA efetivamente servida; aceite final não equivale a autorização de publicação.

Após CI, verificar SHA exata e registrar os resultados na própria PR. Gates Sites [#38](https://github.com/rafhael100/imagine-sites/issues/38) e dez gates operacionais: **NO-GO**. Sem checkout, Asaas, rede, Replit, IA real, VPS, Cloudflare, DNS, segredos, merge, deploy ou fornecedores.
