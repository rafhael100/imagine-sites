# STATUS-ATUAL — Imagine Sites Engine

## 20/11/2026 — prévia privada do comprador Onepage (draft #570, sem bind operacional)

[PR Sites #570](https://github.com/rafhael100/imagine-sites/pull/570), branch isolada sobre #569 HEAD `8172e5cc14e97951e529c843059c214e05c33db5`. Candidata de visualização privada de Onepage requer: origem imutável do Portal #976, confirmação exata do comprador responsável com declaração de direitos, aprovação/revogação corrente de revisor independente e HEAD Portal #1003/Sites #552; todas as portas não vinculadas ao runtime e padrão negadas. Testes offline sintéticos comprovam 36 verificações por versão PHP 8.3/8.4 na [CI inicial #44773217062](https://github.com/rafhael100/imagine-sites/actions/runs/44773217062) HEAD `58f487f325a45a80d098be529a27d453090aa0af`. A CI da próxima HEAD deve ser reconferida. Visualização privada NÃO é aceite final, autorização de publicação ou pagamento; domínio só na publicação. A fonte oficial humana/bytes/revogação e S2S autenticado ainda faltam; #38 e dez gates BLOCKED. Nenhum merge/deploy/main/VPS/Cloudflare/DNS/Asaas. [Limitações e tests](docs/reviews/SITES-ONEPAGE-BUYER-PRIVATE-PREVIEW-2026-11-20.md).

## 20/11/2026 — custódia da HEAD antes de readback privado (candidata draft)

Branch isolada após #568 HEAD `a9660b34bc0eab2c2c6be59186ff1e08cf2d1967`. Uma segunda instrução `FOR SHARE` no site ocorre **antes** da consulta da versão corrente do artefato, eliminando o risco de snapshot antigo durante espera por escritor que usa `FOR UPDATE`. Teste PG18 de duas sessões prova que um writer de mesma empresa/site não obtém lock enquanto a leitura privada mantém a transação aberta. [Documento](docs/reviews/SITES-LANDING-PG18-READBACK-HEAD-SERIALIZATION-2026-11-20.md). **CI pendente de observação; laboratório não é aprovação, publicação, entrega ou cobrança. Dez gates BLOCKED.** Sem merge/deploy/main/VPS/Cloudflare/DNS/Asaas.

## 20/11/2026 — proteção do readback Landing contra revogação concorrente (candidata draft)

Branch isolada sobre #567 HEAD `5c2d40b8bd16cf3e4c1935b1789ede054d6ef29d`, sem disputa com #541/#542, Portal ou main. A função candidata de leitura privada passa a travar com `FOR SHARE` as admissões de leitor, site, autor e revisor até COMMIT/ROLLBACK; o adaptador deixa de usar `SET TRANSACTION READ ONLY` porque PostgreSQL proíbe row locks nesse modo, mas não recebe grants de escrita. Quatro fixtures novas simulam revogação concorrente por sessões PostgreSQL independentes. Ver [relatório de limitações](docs/reviews/SITES-LANDING-PG18-READBACK-REVOCATION-FENCE-2026-11-20.md). **CI ainda não declarado até execução na HEAD final; homologação operacional, aprovação, publicação, aceite e dez gates permanecem bloqueados.** Sem merge/deploy, mudanças em main, VPS, produção, Cloudflare/DNS, credenciais ou Asaas.

## 20/11/2026 — leitura independente do diário de revisão Landing PG18 (draft #564)

[PR Sites #564](https://github.com/rafhael100/imagine-sites/pull/564) em branch isolada descendente da #563 HEAD `533bc571d9c04aee0ff5d2cd0fcf8d0ab7b01985`, sem tocar #541/#542, main ou branch de outra pessoa. Implementados SQL read-only com RLS FORCE e identidade do leitor derivada de `session_user`, adapter PHP com revalidação Portal pré/pós leitura e fixture adversarial de revogação, tenant cruzado, nova versão, identidade e digest. O resultado é **somente observação privada**, nunca aprovação formal, publicação ou aceite.

**Evidência na HEAD inicial `c908fc8bc1f9f65a686eb79cf8b48bf1303c3691`:** [run PG18 #44734530237](https://github.com/rafhael100/imagine-sites/actions/runs/44734530237), PHP 8.3 e 8.4, **2/2 jobs SUCCESS**, marcadores `PRIVATE CUSTODY PASS: 35 checks`, `PRIVATE REVIEW JOURNAL PASS: 26 checks`, `REVIEW READBACK PASS: 26 checks` por versão; **5/5 workflows SUCCESS** nessa HEAD. Estes testes usam identidades e aprovações fictícias; o transporte S2S, fonte oficial Portal, migrações/roles operacionais, revisão humana, publicação e aceite permanecem não homologados. **Dez gates BLOCKED**. Nenhum merge/deploy/cobrança/produção/VPS/Cloudflare/DNS/Asaas.

## 20/11/2026 — custódia privada e idempotente do rascunho Landing em PG18 (draft #562)

[PR Sites #562](https://github.com/rafhael100/imagine-sites/pull/562) em branch exclusiva, descendente da #561 HEAD imutável `b7303b8f8c8c7638823c88ae4e5c555fafc274b3`, sem modificar #541/#542, Portal nem main. Contrato PG18 candidato mantém HTML e ponteiro privado de revisão, com hashes e entrada por role vinculada independentemente a tenant/site/pedido/contrato; restringe funções, rejeita campos de publicação/aceite, identifica replay exato, recusa colisões e registra somente estado pendente. Adapter PHP revalida o head Portal antes de confirmar a transação; revogação observada após INSERT causa ROLLBACK.

**Evidência verificada na HEAD `9b9199f059e594e6b37f04ae8daaeee14047c575`:** [workflow PG18 44718436983](https://github.com/rafhael100/imagine-sites/actions/runs/44718436983) **2/2 jobs SUCCESS**, PHP 8.3/8.4, `SITES LANDING PG18 PRIVATE CUSTODY PASS: 34 checks` e 34 regressões editoriais por versão. Também **5/5 workflows da HEAD SUCCESS**; não há homologação operacional.

O cadastro de papéis, a admissão do site e a aprovação de Portal usados nos testes são fictícios. Faltam fonte oficial autenticada, grants/RLS de produção homologados, reviewer autenticado, journal de revisão/aceite, S2S/mTLS operacional, publicação e entrega. Releitura não equivale a autorização distribuída atômica. **Dez gates BLOCKED.** Sem merge/deploy, alteração de VPS, main, produção, credenciais, Cloudflare/DNS, Asaas ou custos.

## 20/11/2026 — rascunho editorial Landing privado com fonte corrente (draft #561)

A [PR Sites #561](https://github.com/rafhael100/imagine-sites/pull/561) foi criada em branch isolada sobre a HEAD #560 `3b7274d8fd2b4ba1884ef2c8a8d28495bf9d1ce0`, sem alterar #541/#542 ou outra branch. O encoder privado Portal está fixado na HEAD #1019 `4a5e1e06376188f85c90bee3d10056ab3ce2302a`.

O novo `PortalLandingPageEditorialDraftCandidate` consome a leitura independente do briefing Landing aprovado candidato, valida conteúdo fechado (`portal.landing-content.candidate.v1`), gera um HTML **inerte e privado** com identidade #F9941D, escapa texto de cliente e produz ponteiro de revisão com hash separado, vínculo de tenant/pedido/site/contrato/revisão/epoch e SHA-256 da origem/artefato. Reconsulta o head após renderizar e nega drift observado. O CTA é não operante, não existem URLs externas, scripts ou formulários. O ponteiro não contém os bytes privados do briefing.

**CI confirmado em [44709743707](https://github.com/rafhael100/imagine-sites/actions/runs/44709743707)**: jobs PHP 8.3 e 8.4 SUCCESS, com **34 verificações novas** e **42 regressões de readback por versão**. Os outros workflows da HEAD devem ser consultados separadamente antes de declarar CI geral integral.

**Limites:** fonte de aprovação/ator e vínculo autenticado continuam inexistentes em produção; fixtures são sintéticas; nenhum writer ou journal de revisão Landing está integrado; releituras não são transação distribuída; renderização não concede aceite, autorização de publicação, pagamento ou entrega. Dez gates BLOCKED; PR draft, sem merge/deploy/produção/VPS/Cloudflare/DNS/Asaas/credenciais.

## 20/11/2026 — bloqueio de reaprovação após evento terminal Landing (draft)

Nova candidata Sites, filha isolada da #558, emparelhada à Portal #1017
(`30f10691c6710e7b5effb220d0f4788c7f91914e`). Testa em PG18
descartável as sequências hostis de evento `revoked/superseded → approved`
na mesma revisão/epoch, antes toleradas pelo reader #1015. A fonte Portal
foi endurecida para negar qualquer evento terminal daquela revisão,
independentemente da ordem, e a prova Sites exercita reader, encoder,
decoder e readback reais candidatos; admission e atores permanecem sintéticos.

**Resultado CI pendente de confirmação nesta HEAD.** Não é aprovação real
de cliente nem integração de produção. RLS/ator autenticado, S2S/mTLS,
ledger oficial, revisão editorial, publicação técnica, aceite e cobrança
permanecem não homologados. Dez gates BLOCKED; sem merge/deploy/produção.

## 20/11/2026 — Portal PG18 transacional → Sites Landing (draft, não operacional)

A [PR Sites #558](https://github.com/rafhael100/imagine-sites/pull/558), base
imutável Sites #557 `acc935766ae385a04e37f29613008c8c09f6730a`, consome
a [PR Portal #1015](https://github.com/rafhael100/imagine-portal/pull/1015),
fonte PG18 candidata fixada em `3c3ba328111445394ad756b9a12507db393a62d1`.
O Portal executa uma leitura READ ONLY REPEATABLE READ da revisão mais recente e
seu evento coincidente de aprovação, com verificação de epoch, ator registrado,
revogação posterior e digest; não existe fonte oficial/autorização de ator.

O [CI Sites 44687731596](https://github.com/rafhael100/imagine-sites/actions/runs/44687731596)
validou **2/2 jobs SUCCESS** (PHP 8.3/8.4): 10 verificações cross-repo em PG18
efêmero e 42 regressões de readback por versão. Também passou o
[CI Portal 44687290553](https://github.com/rafhael100/imagine-portal/actions/runs/44687290553):
25 verificações PG18 + 34 regressões por versão PHP. A primeira execução do
Portal falhou no lint de fixture; a correção foi validada na HEAD final.

**LIMITE:** schemas e atores são sintéticos, a prova positiva usa superusuário
apenas no PG efêmero, a role comum segue sem RLS autorizativa, admissão Sites
continua fictícia e nenhuma API/serviço real está ligada. Não há homologação
de aprovação autenticada, S2S/mTLS, artefato Landing, revisão formal, publicação
ou aceite do cliente. Dez gates BLOCKED; sem merge/deploy, VPS, produção,
DNS/Cloudflare, credenciais, Asaas ou pagamentos.

## 20/11/2026 — contrato privado do briefing Landing aprovado (draft)

A [PR Sites #556](https://github.com/rafhael100/imagine-sites/pull/556)
deriva da #555 fixada em `fbcbc9d090e62b2cd20c302ff9e549cfa46ef185`,
sem alterar as branches da cadeia #541/#542. O Portal implementou na
[PR #1012](https://github.com/rafhael100/imagine-portal/pull/1012)
o `ApprovedLandingPageBriefHead`, a fonte corrente aprovada padrão DENY,
três revalidações de tenant/pedido/site/contrato e um encoder privado candidato.
A HEAD Portal `e0f38d8d98c151ffa5743c9c372ac039074b9ea7` teve 6/6
workflows SUCCESS, com 34 novos testes de aprovação por PHP 8.3/8.4
([CI Portal 44674006409](https://github.com/rafhael100/imagine-portal/actions/runs/44674006409)).

No Sites, o decoder privado verifica a forma canônica, SHA-256 dos bytes
originais do briefing, tipo Landing, contrato e revisão, versão de conteúdo,
aprovação e epoch contra expectativas independentes. Bloqueia serialização
direta e entradas com autoridade de publicação/aceite, sem converter Landing
em Onepage. O [CI par 44674320186](https://github.com/rafhael100/imagine-sites/actions/runs/44674320186)
confirmou 31 checks novos de paridade com o encoder Portal verdadeiro, 30 de
readback de admissão e 30 de regressão de projeção em **cada** PHP 8.3/8.4.

**Resultado apenas contratual/offline:** não há fonte oficial no Portal
com aprovação autenticada/revogação atômica/auditoria, nem transporte mTLS
ativo, perfil/editorial e renderer Landing, revisão formal, publicação
homologada ou aceite. A leitura dupla/tripla não resolve ABA. Sem ativação
ou titularidade de autoridade no Sites; dez gates seguem BLOCKED.
Sem merge, deploy, main, VPS, produção, Cloudflare, DNS, Asaas ou pagamentos.

## 20/11/2026 — admissão Landing Page com vínculo independente de site (draft)

A [PR Sites #555](https://github.com/rafhael100/imagine-sites/pull/555),
branch `integration/sites-landing-site-bound-pair-2026-11-20-r12`,
partiu de Sites #554, SHA `4c2a6f010f6db51c0b950a6cc48c854ba0d614ac`,
sem disputar outra branch. A contraparte
[Portal #1010](https://github.com/rafhael100/imagine-portal/pull/1010)
está pinada em `9f85a3c2d4923bfa0420de2c0278859934833f55`;
continua a cadeia Sites #541/#542 e Portal #1008.

O Portal agora requer vínculo independente `tenantRef + orderRef + siteRef`,
com contrato, revisão e produto Landing Page conferidos nas duas leituras;
um contrato do pedido sozinho não prova autorização sobre o site.
O Sites acrescentou um leitor candidato, que compara duas projeções canônicas
contra a expectativa de empresa/pedido/site/contrato/revisão estabelecida fora
da resposta, e nega por padrão. O teste de paridade precedente foi atualizado
para a nova interface, sem alterar contrato de saída ou liberar flags.

HEAD de código inicial `a900f6ad3626629b53150df2eaa936502986f15c`.
Na [execução Sites 44667686496](https://github.com/rafhael100/imagine-sites/actions/runs/44667686496),
os dois jobs PHP 8.3/8.4 registraram **30 checks do readback, 30 da paridade,
15 do pedido Landing e 15 de regressão Onepage** cada. Os cinco workflows da
HEAD de código terminaram com SUCCESS. No
[Portal 44667265781](https://github.com/rafhael100/imagine-portal/actions/runs/44667265781),
os dois jobs PHP 8.3/8.4 aprovaram **54 checks de admissão, 20 de pedido
Landing e 30 de regressão Onepage** cada; os seis workflows Portal foram
SUCCESS. Todos são **testes offline com provedores sintéticos**.

**NO-GO:** faltam autorização/identidade S2S real, persistência Portal
transacional de briefing Landing aprovado e versionado com revogação/auditoria,
fonte de vínculo por tenant/site no banco oficial, renderização e revisão
formal, publicação técnica homologada e aceite do cliente. Dupla leitura não
equivale a transação. Dez gates operacionais continuam bloqueados; nenhuma
publicação, merge, deploy, pagamento, main, VPS, Cloudflare/DNS, Asaas,
credencial ou produção foi alterada. [Limites do recorte](docs/reviews/SITES-LANDING-BOUND-ADMISSION-READBACK-2026-11-20.md).

## 18/11/2026 — composição da recuperação privada do diário

A branch `integration/sites-request-recovery-composition-2026-11-18` deriva da
#539 / `9f9b19adc21944165e70062017471ede1657975b`, validada com 12/12 SUCCESS.
Preserva o serviço e contrato CLI da #538. Acrescenta duas identidades próprias
somente ao ramo CI, exige as flags20/21 e move o ensaio para depois dos 1241
checks e down16 anteriores. A fixture do leitor retorna exatamente aos bytes
comprovados na #536; SQL, migrations e história persistente não mudam.

A prova confirmou **38 novos /1279 totais**: três históricos existentes, writer
original revogado, stdout descartado, recibo privado alimentando o inspector
separado, isolamento, revogação atual, custódia, snapshots e rollback16.
Os **63 checks offline** do wrapper e **32 de domínio** passaram localmente,
com lint/Bash/AST e revisões independentes. A [#540](https://github.com/rafhael100/imagine-sites/pull/540),
commit `1c9b4d46949b907c225b1a0cc42e10c572b44e33`, confirmou **12/12 checks SUCCESS**.
O [registry 44403773102](https://github.com/rafhael100/imagine-sites/actions/runs/44403773102),
jobs **135153431208 / 135153430438**, confirmou **63 offline, 38 novas /1279
totais**, snapshots, down16 e cleanup em PHP 8.3/8.4. Ambos os clusters foram
confirmados inativos. O [Laravel 44403773083](https://github.com/rafhael100/imagine-sites/actions/runs/44403773083),
jobs **135153429401 / 135153430221**, confirmou **296/4705/27** e **296/4717/21**
em ambas as versões. Guard749, cinco blocos Bash e188 links passaram. Nenhum
banco local executado. [Contrato do ensaio](docs/reviews/SITES-PUBLICATION-RECOVERY-COMPOSITION-2026-11-18.md).

## 18/11/2026 — vocabulário CSS do artefato inerte

A branch `fix/sites-inert-css-dialect-2026-11-18` deriva da #538 /
`cb300444b57aa8b6a314855a6e1a403fc4ddfbed`. O scanner da auditoria admite as
cinco funções usadas pelo renderer e somente `@media`, separando strings,
comentários, at-keywords e chamadas aninhadas. Corrige a aceitação reproduzida
em memória de `image()` e `src()` com caminhos relativos. Não houve navegação
ou requisição de recurso observada nessa reprodução.

**260 checks F27** (108 novos) e **293 de registro offline** (14 novos) passaram.
Os negativos de registro recalculam digest, tamanho e compromisso e são
recusados antes de qualquer chamada à PDO. Os vetores de 5223 e 5167 bytes
conservam seus hashes; renderer, contratos SQL e migrations não mudam.
A suíte Laravel confirmou **296/4705/27** e o domínio **32 checks**.
A [#539](https://github.com/rafhael100/imagine-sites/pull/539), HEAD
`9f9b19adc21944165e70062017471ede1657975b`, confirmou **12/12 checks SUCCESS**.
O [F1-24 44402763812](https://github.com/rafhael100/imagine-sites/actions/runs/44402763812),
jobs **135149896696 / 135149895914**, confirmou **20/260/293** em PHP 8.3/8.4,
dois pacotes privados e cleanup. O [Laravel 44402763356](https://github.com/rafhael100/imagine-sites/actions/runs/44402763356),
jobs **135149895040 / 135149894500**, confirmou **296/4705/27** e **296/4717/21**.
Guard747, seis blocos Bash e178 links locais passaram. [Decisão e limites](docs/reviews/SITES-INERT-CSS-DIALECT-2026-11-18.md).

## 18/11/2026 — adoção da CLI de recuperação publicada em paralelo

A [#538](https://github.com/rafhael100/imagine-sites/pull/538), HEAD
`cb300444b57aa8b6a314855a6e1a403fc4ddfbed`, deriva da #536 e publicou a CLI
`recover-request.php`. Esta sequência conserva seu manifesto original de oito
campos, plano de seis, resultado de treze, recibo privado de três, `planSha256`
e diretório `/var/lib/imagine-sites/publication-recoveries`.

Foram conferidos **12/12 checks SUCCESS**. O [run offline 44400177491](https://github.com/rafhael100/imagine-sites/actions/runs/44400177491),
jobs **135140745492 / 135140745949**, confirmou **63 checks** em PHP 8.3/8.4.
O [Laravel 44400177436](https://github.com/rafhael100/imagine-sites/actions/runs/44400177436),
jobs **135140744723 / 135140745233**, confirmou **296 testes/4705 assertions/27
skips** e **296/4717/21** em ambas as versões. Esses runs não executaram a nova
fixture PostgreSQL. A prova completa será acrescentada em branch filha,
preservando o produto publicado e a alternativa local separada.

## 18/11/2026 — leitor exato da solicitação do diário

A branch `feature/sites-publication-request-reader-2026-11-18` deriva da #535 /
`d5ef3a45d45d0db073c5b43d8b23e68e537bec0a`, validada com 12/12 checks SUCCESS.
A consulta recupera o UUID e o snapshot privado de uma solicitação pelos cinco
seletores originais, sem repetir o writer após perda do recibo. Usa um reader
atual exclusivo e PDO somente leitura, preservando os 24 campos e duas flags
falsas. O primeiro incremento retorna em memória; CLI/arquivo vêm depois.

A migration 16 acrescenta uma API e amplia o guard de 12 para 13 assinaturas,
preservando OID/ACL/atributos e todas as demais funções. Reutiliza roles,
políticas e tabelas existentes. O rollback restaura o corpo nativo fixado e
remove somente a função nova. O SQL permanece VOLATILE: lê operação/evento
juntos e revalida o epoch corrente sem lock ou autorização até a resposta.

**49 checks PHP focados**, **99 anteriores do diário**, **296 testes Laravel /
4705 assertions /27 skips** e **32 checks sintéticos** passaram localmente.
A [#536](https://github.com/rafhael100/imagine-sites/pull/536), HEAD
`849636107cc8562f68ad6c42a34bb71c5b7f66e1`, confirmou **14/14 checks SUCCESS**.
O [registry 44394481654](https://github.com/rafhael100/imagine-sites/actions/runs/44394481654),
jobs **135120584998 / 135120584665**, executou as **1208 assertions anteriores**
sob o guard ampliado e confirmou **33 novas /1241 totais**, rollback 16,
snapshots e cleanup em PHP 8.3/8.4. O [F383 44394481894](https://github.com/rafhael100/imagine-sites/actions/runs/44394481894)
confirmou a sequência de dezesseis migrations e a remoção dos dois schemas.
O [Laravel 44394481500](https://github.com/rafhael100/imagine-sites/actions/runs/44394481500)
confirmou **296/4705/27** e **296/4717/21**. Não houve execução local de banco.
[Contrato e limites](docs/PUBLICATION-REQUEST-READER.md).

## 18/11/2026 — entrada local recusa textos inteiramente em branco Unicode

A branch `fix/sites-local-render-unicode-blank-2026-11-18` deriva da #534 /
`5a93720b68b81e2336fd7038b4f791a2bebc9c72`, validada com **12/12 checks SUCCESS**.
`LocalSiteRendering` passa a exigir ao menos um caractere fora de Unicode
`White_Space` nos quatro textos e em cada benefício, depois das validações
existentes e antes de renderizar. A reprodução anterior aceitava somente
NBSP/EM SPACE nesses cinco destinos, apesar da regra documentada de texto não vazio.

A mudança recusa a entrada completa, sem aparar texto ou remover itens. Bytes
com conteúdo, espaços nas bordas/interior e caracteres fora dessa propriedade
continuam preservados. `SiteBrief`, leitores históricos, renderer e vetores
permanecem intactos. O novo critério também alcança novas renderizações chamadas
pela composição de briefing, sem regravar artefatos históricos.

Validação local: **159 checks de serviço** (22 novos), **97 de CLI** (dez novos),
**296 testes Laravel/4705 assertions/27 skips** e **32 checks sintéticos**.
Os cinco negativos CLI preservam inputs e arquivo independente sem criar HTML;
os positivos comparam os bytes dos dois produtos com o renderer original.
A [#535](https://github.com/rafhael100/imagine-sites/pull/535), HEAD
`d5ef3a45d45d0db073c5b43d8b23e68e537bec0a`, confirmou **12/12 checks SUCCESS**.
O [run 44392728209](https://github.com/rafhael100/imagine-sites/actions/runs/44392728209),
jobs **135114374901 / 135114374731**, confirmou **159/97** e cleanup em PHP
8.3/8.4. O [Laravel 44392728069](https://github.com/rafhael100/imagine-sites/actions/runs/44392728069),
jobs **135114374101 / 135114373530**, confirmou **296/4705/27** e **296/4717/21**.
Guard em **733 arquivos**, dois blocos Bash, **182 links locais** e revisão
independente passaram. Sem nova prova PostgreSQL/registry ou navegador.
[Defeito, regra e testes](docs/reviews/SITES-LOCAL-RENDER-UNICODE-BLANK-2026-11-18.md).

## 18/11/2026 — recusa de CSS escapado e image-set no HTML inerte

A branch `fix/sites-inert-css-escapes-2026-11-18` deriva da #533 validada /
`1163c001b4974abbdd93ed51e6dbf54e520c4fb4`. A auditoria compartilhada passa a
recusar escapes CSS e as funções `image-set()`/`-webkit-image-set()` dentro do
bloco de estilo. Os casos reproduzidos anteriormente eram aceitos pelo filtro
lexical, embora representassem formas de especificar recursos. A reprodução
foi em memória, sem navegador ou requisição externa observada.

O renderer fixo não usa essas construções. Texto literal nos cinco campos,
meta description e bytes dos artefatos permanecem preservados. Os negativos
recalculam digest, tamanho e compromisso: a auditoria de pacote recusa o HTML e
o registro recusa `plan`/`record` antes de qualquer chamada à PDO.

Validação local: **152 checks F27**, **279 assertions de registro offline**,
**296 testes Laravel/4705 assertions/27 skips** e **32 checks sintéticos**
obrigatórios. Revisão independente do produto e do workflow sem bloqueadores.
O workflow F1-24 passa a exigir os dois marcadores focados em PHP 8.3/8.4.
A [#534](https://github.com/rafhael100/imagine-sites/pull/534), HEAD
`5a93720b68b81e2336fd7038b4f791a2bebc9c72`, confirmou **12/12 checks SUCCESS**.
O [F1-24 44391000613](https://github.com/rafhael100/imagine-sites/actions/runs/44391000613),
jobs **135108293907 / 135108293436**, passou em PHP 8.3/8.4 com **20 checks de
pacote, 152 de auditoria e 279 de registro**, recusa sem opt-in, dois pacotes
privados com `publishable=false` e remoção de seus diretórios. O
[Laravel 44391000603](https://github.com/rafhael100/imagine-sites/actions/runs/44391000603)
confirmou **296/4705/27** e **296/4717/21** nas duas versões. Guard de **731
arquivos**, seis blocos Bash e **173 links locais** passaram. Não houve nova
prova PostgreSQL/registry ou navegador; renderer, schema e autoridade não mudaram.
[Recorte, fontes e regressões](docs/reviews/SITES-INERT-CSS-ESCAPES-2026-11-18.md).

## 18/11/2026 — consumidor Portal nativo validado na #533

A [#533](https://github.com/rafhael100/imagine-sites/pull/533), branch
`integration/sites-portal-intake-recovery-2026-11-18`, concluiu **12/12 checks
SUCCESS** no HEAD `1163c001b4974abbdd93ed51e6dbf54e520c4fb4`, sobre a #532.
Usa o Portal #954 inalterado em `d1aaab523848baf80325a023f3ebbf4998a3607f`
e as CLIs atuais do Sites, em processos e bancos PostgreSQL 18 separados.

Pedido e comando vêm do Portal nativo. Sites recebe uma vez com stdout descartado,
desativa o receiver e recupera o native15 pelo leitor independente. O Portal
consome somente esse arquivo privado com seu validador, writer restrito e leitura
do comprador. Replay conserva uma linha; a projeção pública, operação/chave
alteradas, promoção de autoridade e substituição de siteRef são recusadas. O
teste original prossegue com pagamento, ownership e revogação de contrato.

O [run pareado 44389473142](https://github.com/rafhael100/imagine-sites/actions/runs/44389473142),
jobs **135102871574 / 135102870964**, confirmou em PHP 8.3/8.4 **13 checks
Sites, 20 checks Portal e 1 teste nativo/216 assertions**. Os logs mostram as
31 fontes Portal/quatro contratos Sites conferidos antes e depois, estado final
Sites idêntico após a recuperação, instalação e rollback das quinze migrations,
três schemas ausentes, cluster Sites inativo, cleanup recuperação → receiver e
remoção do serviço/rede Portal. O
[Laravel 44389473217](https://github.com/rafhael100/imagine-sites/actions/runs/44389473217)
confirmou **296/4705/27** e **296/4717/21** nas duas versões.

As três tentativas anteriores corrigiram somente pressupostos da fixture sobre
nome da conexão Laravel, representação de APP_DEBUG e inventário nativo de RLS.
Produto, SQL, migrations e fontes Portal permaneceram intactos. Validação local:
**32 checks sintéticos**, guard em **730 arquivos**, cinco blocos Bash e runner,
**161 links locais** na revisão inicial, lint, diffcheck e recusas sem opt-in.
A prova é própria e não altera as **1208 assertions registry** da base #532.
[Contrato, tentativas e limites](docs/reviews/SITES-PORTAL-INTAKE-RECOVERY-2026-11-18.md).

## 18/11/2026 — recuperação privada do recibo validada na #532

A [#532](https://github.com/rafhael100/imagine-sites/pull/532), branch
`feature/sites-project-intake-recovery-cli-2026-11-18`, concluiu **12/12 checks
SUCCESS** no HEAD `6da154611d082f7f7fe4aab87f17c9857403ed02`, sobre a #531 /
`958ce49899375a99c3dee9b7f40016701a465502`. A candidata
`recover-project-intake-receipt.php` oferece plano e recuperação do recibo exato
usando uma identidade de leitura atual. Mantém a sessão somente leitura durante
toda a operação e usa uma chamada ao serviço existente. Os quatro inputs privados,
o alvo observado e o destino exclusivo entram no compromisso conferido.

O arquivo privado conserva os quinze campos nativos; stdout contém oito campos
no plano ou dez após a recuperação, sem referências privadas e com três flags
falsas. A mesma custódia de recibo reserva o arquivo 0600, sincroniza e relê os
bytes. Falha pode deixar arquivo vazio, parcial ou completo; a CLI preserva esses
destinos e não repete automaticamente a consulta. O snapshot STABLE da autorização
não garante permanência do acesso até o fim da escrita do arquivo.

| Prova própria da #532 | Resultado confirmado |
| --- | --- |
| [Registry 44382215425](https://github.com/rafhael100/imagine-sites/actions/runs/44382215425), jobs **135077280635 / 135077280347** | PHP 8.3/8.4: **30 novas/1208 totais**, **83 offline da recuperação**, 77 do receiver e 123 da custódia. |
| [Laravel 44382215237](https://github.com/rafhael100/imagine-sites/actions/runs/44382215237), jobs **135077279527 / 135077278881** | Nas duas versões: **296/4705/27** e **296/4717/21** com opt-in sintético. |

Os três mapas decodificados dos arquivos recuperados alimentaram snapshots,
renderização e registros da composição, mantendo os dez projetos, os 29 checks
do receiver e os 119 próprios da composição. A recuperação não acrescentou linhas.
A migration 15 temporária foi removida antes de continuar o pipeline; o rollback
ordenado das catorze anteriores e o cleanup 19→18→predecessores foram confirmados,
com clusters próprios inativos. Sem nova execução HTTP, F383 ou workflow de
containers na #532. Validação local: **83 offline**, **296/4705/27**, **32 checks
sintéticos**, guard em **723 arquivos**, cinco blocos Bash, runner, **174 links**,
diffcheck e revisões independentes PASS.
[Manual](docs/PROJECT-INTAKE-RECOVERY-CLI.md).

## 18/11/2026 — consulta exata do recibo validada na #531

Branch `feature/sites-project-intake-receipt-reader-2026-11-18`, sobre a #530 /
`e49d5f8365d70d0e155703a1d3f2362684f04a57`. O novo
`StoredProjectIntakeReceiptReader::readExact(SiteProjectCommand)` permite consultar
o recibo usando os oito campos originais, sem conhecer previamente o siteRef.
Exige uma identidade de leitura atual, PDO principal fixa, sessão somente leitura,
uma transação independente e uma seleção exata. Recusa ausência, divergência e
falhas com o mesmo erro limitado. Não repete a operação de recebimento.

A migration 15 acrescenta `read_portal_project_intake_receipt` e um definer
NOLOGIN com SELECT somente em bindings, organizações e intakes. A autorização é
`active/can_read` corrente e EXECUTE exclusivo; o receptor histórico pode estar
revogado. A função STABLE observa autorização e recibo no snapshot do início da
instrução, sem prometer lock até commit ou revogação retroativa dessa leitura.
O recibo conserva seus quinze campos e três flags falsas. SQL001, recorder,
comando e schema fixados pelo Portal permanecem idênticos.

**Validação local PASS:** grupo novo **10 testes/341 assertions**, suíte geral
**296/4705/27**, **32 checks sintéticos**, lint e revisão independente do SQL e da
migration. O grupo novo usa PDO desconectada; a prova real abaixo confirmou
**53 verificações novas**, preservando as 65 de intake e verificando leitores
próprios e rollback/reaplicação da migration 15. A comparação do catálogo normaliza a ordem dos grants de função após
REVOKE/GRANT, conservando todos os privilégios e demais campos. Não se atribui o resultado
registry da #530 a esse schema novo.
[Manual e limites](docs/PROJECT-INTAKE-RECEIPT-READER.md).

A primeira execução da [#531](https://github.com/rafhael100/imagine-sites/pull/531),
HEAD `b131a7c9888da7b62b0e 97c2ff9970a59811001b`, instalou a migration 15 em
PHP 8.3/8.4, mas o [F383 44377646550](https://github.com/rafhael100/imagine-sites/actions/runs/44377646550)
parou antes da prova nova porque o inventário antigo exigia 45 políticas.
O teste agora inclui exatamente as três políticas de SELECT do novo definer,
totalizando 48; conserva tabela, comando, role e predicados exatos e continua
recusando políticas extras ou PUBLIC. Nenhum código de produto foi alterado
nesse ajuste. A prova das 53 verificações foi concluída no run final abaixo.

A segunda execução, HEAD `23246cb0e6b9b48a1f9ed6a52d84adb0e096c3a7`,
[F383 44378184715](https://github.com/rafhael100/imagine-sites/actions/runs/44378184715),
confirmou os 172 checks da fundação, 21 de leitura, 39 editoriais e 65 do intake,
mas a fixture nova parou ao expandir uma ACL de coluna vazia. A comparação agora
passa NULL ao `aclexplode` quando não há entradas, conservando os grants não
vazios ordenados e todos os demais metadados. Esse segundo ajuste também se
limita à fixture. A execução seguinte confirmou as 53 verificações e o rollback.

A [#531](https://github.com/rafhael100/imagine-sites/pull/531), HEAD
`958ce49899375a99c3dee9b7f40016701a465502`, concluiu **13/13 checks SUCCESS**.
O [F383 44379689221](https://github.com/rafhael100/imagine-sites/actions/runs/44379689221),
jobs **135068216968 / 135068217454**, confirmou em PHP 8.3/8.4 **53 checks novos**,
**65 de intake** e **245 de snapshots**, grupo PHP **10/341**, down/up15 e rollback
final das quinze migrations, com os dois schemas próprios ausentes e cleanup.
O [Laravel 44379688946](https://github.com/rafhael100/imagine-sites/actions/runs/44379688946)
confirmou **296/4705/27** e **296/4717/21** nas duas versões. O ensaio de
[containers 44379688772](https://github.com/rafhael100/imagine-sites/actions/runs/44379688772)
passou com containers e redes removidos. Não houve nova execução registry ou HTTP
na #531; o total 1178 pertence à base #530.


## 18/11/2026 — entrada privada de projetos validada na #530

A [#530](https://github.com/rafhael100/imagine-sites/pull/530), branch
`feature/sites-project-intake-receiver-cli-2026-11-18`, concluiu **12/12 checks
SUCCESS** no HEAD `e49d5f8365d70d0e155703a1d3f2362684f04a57`, sobre a #529 /
`d8a5630bf8957e238b71edf92b6e2a406950e49b`. A CLI candidata
`receive-project-intake.php` oferece plano somente leitura e uma chamada explícita
ao recorder nativo. Preserva o comando de oito campos, a função SQL e o recibo de
quinze campos, inclusive os três indicadores falsos. Os quatro arquivos fixados
pelo consumidor Portal continuam byte a byte iguais.

Quatro inputs privados fornecem configuração, comando original, senha e CA.
O compromisso inclui alvo, hash dos bytes do comando e destino completo do
recibo. A escrita exige esse compromisso e confirmação própria, reserva um arquivo
exclusivo 0600 e mantém seu descritor durante a gravação. O recibo privado conserva
o siteRef; stdout tem oito/dez campos sem referências privadas. A autoridade
continua sendo o binding nativo active/can_receive_project, sem epoch ou expiração.

Banco e arquivo são recursos separados. O ensaio real comprovou o commit apesar
da substituição do arquivo reservado durante uma chamada bloqueada, preservou o
substituto e a reserva movida e recuperou o mesmo siteRef por replay explícito
para novo destino. A resposta B descartada também deixou um recibo íntegro. A
CLI não apaga destinos nem executa retry automático.

**Validação local:** 77 checks da CLI, 123 de custódia temporária, suíte geral
286/4364/27, 32 sintéticos, lint, YAML/Bash, 710 arquivos no guard, links e
revisões independentes PASS. Os testes offline não simulam sucesso SQL.

| Prova própria da #530 | Resultado confirmado |
| --- | --- |
| [Registry 44373979915](https://github.com/rafhael100/imagine-sites/actions/runs/44373979915), jobs **135048052390 / 135048053323** | PHP 8.3/8.4: **29 novas/1178 totais**, mantendo os dez projetos e 119 checks próprios da composição; 77/123 offline. |
| [Laravel 44373979625](https://github.com/rafhael100/imagine-sites/actions/runs/44373979625), jobs **135048049793 / 135048050381** | Nas duas versões: **286/4364/27** e **286/4376/21** com opt-in sintético. |

Cleanup dos quatro inputs e recibos declarados antes dos auxiliares anteriores,
clusters inativos e rollback ordenado da composição confirmados. Sem nova
execução HTTP, F383 ou workflow de containers nesta PR.
[Manual e limites](docs/PROJECT-INTAKE-RECEIVER-CLI.md) ·
[Workflow dedicado](.github/workflows/sites-project-intake-receiver-cli.yml).

## 18/11/2026 — campos com quebras de linha preservados no formulário

A [#529](https://github.com/rafhael100/imagine-sites/pull/529), branch
`fix/sites-preview-multiline-fields-2026-11-18`, concluiu **11/11 checks SUCCESS**
no HEAD `d8a5630bf8957e238b71edf92b6e2a406950e49b`, sobre a #528 /
`035e50e 979e7cdf45f29a7e2c66d0aa1b5d63944`. O backend aceita CR/LF internos
na marca, chamada principal e botão, mas `input` de texto remove essas quebras.
A view usa `textarea` somente nesses três campos quando o valor contém CR ou
LF, preservando os controles habituais para valores de uma linha. Não há mudança
de CSS, parser, domínio ou limites. Texto e separação de linhas são conservados;
o HTML normaliza finais de linha para LF e o envio nativo usa CRLF.

**Prova local atual:** **24 testes/306 asserções focados**. A primeira execução
CI encontrou dois asserts dependentes da normalização do libxml local; a prova
agora confere bytes HTML e texto DOM separadamente, sem mudar o produto. A suíte
geral local **286/4358/27**, os **32 checks sintéticos**, lint, sintaxe JS e revisão
independente haviam passado antes desse ajuste dos testes. A jornada
reutiliza o projeto e o positivo da versão 2: um POST inválido em descrição
reapresenta os três campos multilinha; corrigir apenas descrição deve reenviar
os valores, conservando também a prova dos oito benefícios Unicode. O [HTTP
44368226611](https://github.com/rafhael100/imagine-sites/actions/runs/44368226611),
job **135027630937**, confirmou **4 novos/460 totais**, com cleanup. As duas
capturas do formulário, em 1440/390 pixels, foram conferidas visualmente.
O [Laravel 44368226606](https://github.com/rafhael100/imagine-sites/actions/runs/44368226606),
jobs **135027633135 / 135027634111**, confirmou **286/4364/27** e **286/4376/21**
em PHP 8.3/8.4. Sem nova execução de registry completo, F383 ou workflow de
containers nesta PR.
[Recorte, fontes e limites](docs/reviews/SITES-PREVIEW-MULTILINE-FIELDS-2026-11-18.md).

## 18/11/2026 — inspeção privada do snapshot recebido validada na #528

A [#528](https://github.com/rafhael100/imagine-sites/pull/528), branch
`feature/sites-brief-snapshot-inspection-2026-11-18`, concluiu **12/12 checks
SUCCESS** no HEAD `035e50e 979e7cdf45f29a7e2c66d0aa1b5d63944`, sobre a #527 /
`6ff2cabc0c32bcaa92de5b94f84c4cc3e7cdd167`. A CLI
`inspect-brief-snapshot.php inspect` reutiliza `StoredBriefSnapshotReader::readExact`
e sua API SQL existente. A seleção é `siteRef`, `snapshotRef`, `briefRevision`
e SHA dos bytes originais do manifesto, com reader corrente separado e três
mounts privados. Não procura a última revisão nem repete o receiver.

O resultado contém exatamente dez campos: schema/status, hashes do manifesto
e corpo, `briefRevision`, `sourceVersion` e quatro capacidades falsas. Não há
timestamp, JSON bruto ou referência privada em stdout. A consulta permite
apurar uma resposta de recebimento perdida sem atribuir autoria a uma tentativa
nem transformar a declaração `approved` em autoridade Portal. SQL, migrations
e serviços existentes permanecem intactos.

**Validação local PASS:** **56 checks offline**, suíte geral **284 testes/4310
assertions/27 skips**, **32 checks sintéticos**, lint PHP, AST do helper e diffcheck.
A fixture acrescenta leitores próprios e consulta A/B1/B2/B3 já existentes,
incluindo histórico após revogação do receiver; cobre isolamento, seletores,
TLS/custódia, capabilities exclusivas e revogação corrente. Conserva as 29 checks
do receiver, sem novos snapshots ou artefatos. O [registry 44365173017](https://github.com/rafhael100/imagine-sites/actions/runs/44365173017),
jobs **135016846372 / 135016845405**, confirmou **26 novos/1149 totais** em PHP
8.3/8.4, cleanup dos três inputs antes dos anteriores e clusters inativos.
O [Laravel 44365172022](https://github.com/rafhael100/imagine-sites/actions/runs/44365172022),
jobs **135016841702 / 135016841019**, confirmou **284/4310/27** e **284/4322/21**.
Não houve nova execução HTTP, F383 ou de containers nesta entrega.
[Contrato e limites](docs/BRIEF-SNAPSHOT-INSPECTION-CLI.md) ·
[Workflow dedicado](.github/workflows/sites-brief-snapshot-inspection.yml).

## 18/11/2026 — mensagens de limite de tamanho em português

A [#527](https://github.com/rafhael100/imagine-sites/pull/527), branch
`fix/sites-preview-limit-messages-2026-11-18`, concluiu **14/14 checks SUCCESS**
no HEAD `6ff2cabc0c32bcaa92de5b94f84c4cc3e7cdd167`, sobre a #526 validada no
HEAD `2ffbcb5303afe12593e11a11a4a6dd45a175ac3f`. A chave inline `max.string`
era ignorada pelo Validator instalado; o briefing recusava corretamente os
campos longos, mas exibia a mensagem padrão em inglês. O ajuste usa `max` com
o array de tipo `string`, preservando limites, locale global e a regra numérica
de versão esperada. As cinco mensagens passam a informar campo e teto em português.

**Prova local:** grupo focado **21/367**, suíte geral **284/4310/27**, laboratório
sintético **32**, lint e sintaxe JS PASS. A jornada HTTP reutiliza o negativo de
marca e acrescenta somente quatro negativos nos outros campos; confere mensagem
exata, tentativa integral e ausência de nova versão.

| Prova final da #527 | Resultado confirmado |
| --- | --- |
| [HTTP 44360758121](https://github.com/rafhael100/imagine-sites/actions/runs/44360758121), job **135001148995** | **5 novos/456 totais**, com cleanup. |
| [Laravel 44360757901](https://github.com/rafhael100/imagine-sites/actions/runs/44360757901), jobs **135001148241 / 135001148149** | PHP 8.3/8.4: **284/4310/27** e **284/4322/21**, incluindo o opt-in sintético. |
| [F383 44360757993](https://github.com/rafhael100/imagine-sites/actions/runs/44360757993), jobs **135001147443 / 135001148334** | **65 intake/245 briefing**, 14 migrations, rollback dos dois schemas e cleanup. |
| [Containers 44360758038](https://github.com/rafhael100/imagine-sites/actions/runs/44360758038), job **135001147730** | PASS, com cleanup dos recursos próprios. |

[Recorte e mensagens](docs/reviews/SITES-PREVIEW-LIMIT-MESSAGES-2026-11-18.md).

## 18/11/2026 — composição da entrada privada de snapshots com a proveniência

A [#526](https://github.com/rafhael100/imagine-sites/pull/526), branch
`integration/sites-brief-snapshot-cli-chain-2026-11-18`, concluiu **12/12 checks
SUCCESS** no HEAD `2ffbcb5303afe12593e11a11a4a6dd45a175ac3f`, sobre a #525 /
`e5c30cd7157b97aabedaec009e1fca366b0d0335`. Incorpora os seis arquivos da #523,
fixados em `751b084fdd7a3bbbd276c649de2caf8c2f555b77`, sem alterar a branch de
origem. A entrada `receive-brief-snapshot.php` mantém cinco mounts, plano de dez
campos e registro de onze, compromisso explícito, uma chamada ao recorder e
quatro flags falsas. A enumeração fechada do observer aceita somente a API
existente de recebimento; não há novo SQL ou migration.

Corrigidos nove negativos offline que capturavam a falha da própria assertion;
o harness local preserva somente a variável do loader PHP. **74 checks offline
PASS**, suíte geral **284/4280/27** e regressões **95/271/32 PASS**.
Os logins `bsr_a_receiver`/`bsr_b_receiver` ficam restritos ao ramo CI;
o registro composto admite seu writer e reader próprios, conservando os anteriores.
Nenhuma role antiga é reativada.

A fixture nova recebe duas revisões de briefing B nos intakes já existentes,
apura a segunda após descartar stdout, registra um artefato dessa seleção e
consulta a associação com o inspetor atual. Inclui controles positivos antes de
recusas de tenant/CAS, replay sob epoch atual, revogação, custódia e comparação
integral das linhas. O [registry 44358994325](https://github.com/rafhael100/imagine-sites/actions/runs/44358994325),
jobs **134994843518 / 134994844208**, confirmou **29 novos/1123 totais** em PHP
8.3/8.4, com os cinco mounts novos e os demais inputs removidos, clusters
próprios inativos e rollback/reapply14 dentro da composição. O
[Laravel 44358994106](https://github.com/rafhael100/imagine-sites/actions/runs/44358994106),
jobs **134994842923 / 134994842386**, confirmou **284/4280/27** e **284/4292/21**.
Não houve novo F383, HTTP ou containers; conservam a evidência da #525. [Contrato e limites](docs/reviews/SITES-BRIEF-SNAPSHOT-CLI-CHAIN-2026-11-18.md).

## 18/11/2026 — linhas vazias Unicode no formulário de benefícios

Branch `fix/sites-preview-benefit-blank-lines-2026-11-18`, sobre a #524 validada
no HEAD `89d8a99b83d13fd1b5472b55d7f2891809d2cf81`. O formulário passa a ignorar
linhas inteiramente formadas por espaços Unicode, depois da validação original
e do trim existente. O caso reproduzido recusava oito benefícios por contar
uma linha NBSP ou EM SPACE como nono item. Linhas com texto mantêm os espaços
Unicode originais; U+200B/FEFF e os limites de oito itens, 180 por item e 1500
no campo completo permanecem intactos.

**Prova local:** suíte geral **284 testes/4280 assertions/27 skips**, laboratório
sintético **32 checks**, lint PHP e sintaxe JS PASS. A revisão independente não
identificou bloqueadores. A fixture HTTP reaproveita a segunda revisão do projeto
existente, confere oito textos exatos e o digest e recusa nove itens sem criar
uma terceira revisão ou modificar os HTMLs anteriores. A #525 concluiu **14/14
checks SUCCESS**: [HTTP 44356190255](https://github.com/rafhael100/imagine-sites/actions/runs/44356190255)
com **4 novos/451 totais**; [Laravel 44356189709](https://github.com/rafhael100/imagine-sites/actions/runs/44356189709)
com **284/4280/27** e **284/4292/21** em PHP 8.3/8.4;
[F383 44356189343](https://github.com/rafhael100/imagine-sites/actions/runs/44356189343)
com **245 briefing/65 intake**, 14 migrations e rollback, e
[containers 44356189780](https://github.com/rafhael100/imagine-sites/actions/runs/44356189780).
Cleanup dos recursos próprios confirmado. Artefato **14453992417**, 37 arquivos,
SHA-256 `1c030947b35ebd3cb113b1743bad5b6c73bf11b6c231e85b9c6eab154ecfe343`
conferido após download. Renderer, SQL e migrations não mudaram. [Recorte e limites](docs/reviews/SITES-BENEFIT-BLANK-LINES-2026-11-18.md).

## 18/11/2026 — quebra de texto nas novas páginas geradas

Branch `fix/sites-renderer-long-text-2026-11-18`, sobre a #522 validada no HEAD
`0b735a1794ee3bb965e5a05d5d738083ddac9544`. A análise do renderer identificou que
marca, descrição, CTA e benefícios aceitavam palavras/URLs contínuas nos limites
do briefing, mas somente os títulos possuíam quebra de palavra. O ajuste adiciona
três declarações CSS para permitir quebra herdada, redução da marca no flex e
contenção do botão. Texto, DOM, âncoras e CSP permanecem idênticos fora do style.

O exemplo corrente passou a **5223 bytes**, SHA-256
`ab999fc8c61d4f22ab250c6d5d86442a0ceca24659d1f4173db3580e4694740e`.
Seu plano foi atualizado apenas em `expectedArtifact`; as provas históricas da
#517 conservam os valores antigos. Versões, registros e recibos persistidos não
são reescritos. Nova geração exige plano/compromisso coerentes com o renderer
corrente, sem fallback para o HTML anterior.

**Verificação local PASS:** renderer **27**, renderização local **137**,
proveniência **109**, registro anterior **271**, PHPUnit **279/4250/27**,
lint e comparação de bytes fora do CSS. O Chromium local não abriu por recusa de
socket do runtime, portanto não há medida ou captura local. A fixture HTTP usa
as páginas reais dos dois produtos a 320/390/1440 px. A [#524](https://github.com/rafhael100/imagine-sites/pull/524)
concluiu **15/15 checks SUCCESS** no HEAD `89d8a99b83d13fd1b5472b55d7f2891809d2cf81`.
O [run 44352671761](https://github.com/rafhael100/imagine-sites/actions/runs/44352671761)
confirmou **8 novos/447 HTTP totais**, **429 workspace** e **1094 registry** nas
duas versões PHP, com cleanup de arquivos, clusters, containers e redes próprios.
O [F383 44352671538](https://github.com/rafhael100/imagine-sites/actions/runs/44352671538)
confirmou **245 briefing/65 intake**, batch de 14 migrations e rollback dos dois
schemas; [Laravel 44352671587](https://github.com/rafhael100/imagine-sites/actions/runs/44352671587),
**279/4250/27** e **279/4262/21**. O artefato **14447704981** contém 37 arquivos,
com ZIP SHA-256 `d9e410e55714651afc29d5f96e0fcf1dd977b8196f3d01fe9963054453a0fcea`
conferido após download. Revisão visual das quatro capturas em 320/1440 px PASS;
a medição geométrica também cobre 390 px.
[Contrato e limites](docs/reviews/SITES-RENDERER-LONG-TEXT-2026-11-18.md).

## 18/11/2026 — entrada privada de registro do briefing armazenado

A [PR #522](https://github.com/rafhael100/imagine-sites/pull/522), branch
`feature/sites-brief-provenance-registration-2026-11-18`, concluiu **12/12 checks
SUCCESS** no HEAD `0b735a1794ee3bb965e5a05d5d738083ddac9544`, sobre a #521
validada em `73d610513c777c5f3a1709fcb19263d6073139c6`. A CLI candidata
`register-provenance.php` reutiliza `StoredBriefReleaseRegistration`, sem novo
serviço, SQL ou migration. Manifesto, plano, editorial, duas credenciais e CA
ocupam seis mounts privados fixos. O reader do snapshot e o writer usam logins
exclusivos distintos no mesmo endpoint, banco, OID, endereço e CA observados.
Ambas as conexões ficam somente leitura durante o plano real; a escrita exige
`commitmentSha256` exato e a confirmação `SITES_STORED_BRIEF_ARTIFACT_ONLY_APPROVED`.

A CLI revalida os seis arquivos antes de habilitar uma única chamada de registro
e antes de emitir stdout. Seus envelopes próprios têm dez/doze campos técnicos,
sem seletores privados e com quatro flags falsas. O compromisso não inclui o
login ou a época do reader; leitura e escrita são transações separadas. Falha ou
perda de resposta pode seguir commit e exige apuração com o inspetor histórico,
sem retry automático. A quarta capacidade fechada de `ReleaseArtifactConnection`
aceita somente a API exata de leitura do snapshot, em sessão somente leitura.

**Prova local: 95 checks offline, 109 de proveniência e 271 do registro anterior
PASS**, com revisão independente do wrapper e da fronteira de conexão. A suíte
geral local também passou com **279 testes/4250 assertions/27 skips**, usando
`--do-not-cache-result`; o workflow preserva quinze opt-ins e a base exata. A fixture
reaproveitou snapshots e o inspetor existentes, confirmou dois pares novos de
artefato/associação e apurou a saída descartada sem retry. O scan de **677 fontes**
e os links passaram, mantendo os dez gates BLOCKED.

| Prova final da #522 | Resultado confirmado |
| --- | --- |
| [Registry — run 44349683339](https://github.com/rafhael100/imagine-sites/actions/runs/44349683339) | PHP 8.3/8.4: **95 offline**, **27 novos de registro**, 25 de inspeção, 119 de composição e **1094 totais**; seis arquivos novos e três do inspetor removidos e clusters próprios inativos. |
| [Laravel — run 44349683049](https://github.com/rafhael100/imagine-sites/actions/runs/44349683049) | PHP 8.3/8.4: padrão **279/4250/27** e sintético **279/4262/21**. |

Não houve nova execução F383 ou de containers na #522. A correção posterior do
renderer e seus exemplos atualizados têm a validação própria indicada acima. [Manual do registro privado](docs/STORED-BRIEF-REGISTRATION-CLI.md).

## 18/11/2026 — consulta privada exata da proveniência

A [PR #521](https://github.com/rafhael100/imagine-sites/pull/521), branch
`feature/sites-brief-provenance-inspection-2026-11-18`, concluiu **12/12 checks
SUCCESS** no HEAD `73d610513c777c5f3a1709fcb19263d6073139c6`, sobre a composição
validada da #520. `inspect-provenance.php inspect` consulta a associação por
site/release/hash dos bytes originais do plano, com reader corrente separado.
Reutiliza serviço e SQL existentes. Três mounts privados são revalidados antes e
depois da consulta; stdout expõe dez campos técnicos e quatro flags falsas dentro
desses dez, sem referências privadas ou corpo do briefing.

| Prova final da #521 | Resultado confirmado |
| --- | --- |
| [Registry — run 44346085528](https://github.com/rafhael100/imagine-sites/actions/runs/44346085528) | PHP 8.3/8.4: **51 checks offline**, **25 checks novos da CLI**, **119 de composição** e **1067 registry totais**; três arquivos privados removidos e clusters próprios inativos. |
| [Laravel — run 44346084649](https://github.com/rafhael100/imagine-sites/actions/runs/44346084649) | PHP 8.3/8.4: padrão **279 testes/4250 assertions/27 skips** e opt-in sintético **279/4262/21**. |

A prova reutilizou seis associações existentes, incluindo resposta descartada,
autor revogado/epoch anterior e origem SQL direta. O recibo histórico não prova
execução do renderer, elegibilidade corrente ou autorização para publicar. A #521
não executou um novo workflow F383 ou de containers; essas provas continuam
identificadas pela #520 abaixo. [Manual e limites da consulta](docs/BRIEF-ARTIFACT-INSPECTION.md).

## 18/11/2026 — composição candidata do snapshot ao artefato registrado

Branch `feature/sites-brief-artifact-provenance-2026-11-18`, com referência anterior validada na #519 / `cdfad4464d65355962d487396911fe06092dfde5`. A [PR #520](https://github.com/rafhael100/imagine-sites/pull/520) concluiu **15/15 checks SUCCESS** no HEAD `8b717d3c0c03146bc48ee812e906bc70a1c479a9`, tree `4fa39a00cae8f373b6330c565c5f95e9b05333cc`. A camada composta candidata da migration 14 lê o snapshot exato, revalida o perfil e renderiza internamente; uma chamada SQL usa `record_release_artifact` existente e persiste a associação na mesma transação. Conserva plano/editorial originais, sem duplicar HTML ou corpo rico na relação e sem adoção retroativa de artefatos antigos sem associação. A API antiga permanece intacta; a capacidade composta é uma alternativa exclusiva, não um grant adicional à mesma identidade.

`StoredBriefReleaseRegistration` oferece plano de dez campos e registro de vinte, com `expectedCommitmentSha256` explícito. `commitmentSha256` vincula alvo canônico, plano/editorial originais e HTML; `planSha256` é o hash dos bytes do plano. O reader histórico separado retorna dezoito campos por site/release/hash exatos, sem `created`, compromisso antigo, texto bruto ou login privado. A leitura termina antes da escrita; não se promete autorização atômica entre as duas sessões. Erro pode seguir commit e não provoca retry. O SQL comprova estrutura/hashes/vínculo, enquanto PHP executa a geração e a auditoria. Nenhuma dessas provas autentica aprovação Portal, aceite do cliente ou publicação.

| Prova final da #520 | Resultado confirmado |
| --- | --- |
| [Registry — run 44344390814](https://github.com/rafhael100/imagine-sites/actions/runs/44344390814) | PHP 8.3/8.4: **119 checks novos**, somados aos **923 da base**, total **1042**; clusters próprios encerrados e cleanup confirmado. |
| [F383 — run 44344390782](https://github.com/rafhael100/imagine-sites/actions/runs/44344390782) | PHP 8.3/8.4: **245 checks de briefing**, **65 de intake**, batch das **14 migrations**, down com os dois schemas próprios ausentes e cleanup. |
| [Laravel — run 44344390833](https://github.com/rafhael100/imagine-sites/actions/runs/44344390833) | PHP 8.3/8.4: padrão **279 testes/4250 assertions/27 skips** e opt-in sintético **279/4262/21**. |
| [Containers — run 44344390839](https://github.com/rafhael100/imagine-sites/actions/runs/44344390839) | Ensaio e cleanup confirmados. |

Na preparação local, o grupo focado passou com **39 testes/1172 assertions**, composto por **28 novos e onze Unit existentes**, com `--do-not-cache-result`; não é a suíte geral. As regressões **109** de proveniência e **271** do registro antigo, lint e diffcheck também passaram. As correções durante o CI ficaram na fixture: serialização integral do catálogo para comparar valores `bytea`, em vez de identidades de streams PDO, e liberação de ciclos Laravel/PDO já inalcançáveis antes de abrir outra sessão. Não são correções de produto nem novas execuções locais. A #520 não acrescentou CLI, HTTP, provider, schema de exemplo ou grant operacional. A CLI de consulta posterior possui prova própria na #521, como registrado acima. [Contrato, jobs e limites da composição](docs/STORED-BRIEF-RELEASE-PROVENANCE.md).

## 18/11/2026 — armazenamento nativo candidato de snapshots do briefing

A [PR #519](https://github.com/rafhael100/imagine-sites/pull/519), branch `feature/sites-native-brief-snapshots-2026-11-18`, concluiu **15/15 checks SUCCESS** no HEAD `cdfad4464d65355962d487396911fe06092dfde5`. Partiu da #518 / `907e2883393b776eebcba59fafb672aa34c99bf6`. A migration 13 acrescenta armazenamento imutável do manifesto e do corpo rico Onepage, ligado ao intake nativo e autorizado por identidades SQL separadas de recebimento e leitura. O CAS de `briefRevision` e o replay dos bytes originais são persistentes; `sourceVersion` continua sendo o contador agregado independente da origem. O recibo técnico de treze campos conserva as quatro flags falsas, sem autenticar a aprovação declarada pelo Portal.

| Prova final da #519 | Resultado confirmado em PHP 8.3/8.4 |
| --- | --- |
| [F383 — run 44336308019](https://github.com/rafhael100/imagine-sites/actions/runs/44336308019) | **241 checks de briefing**, **65 de intake**, recorder **18 testes/84 assertions**, grupos de leitura/editorial **3/12** e **2/12**; rollback das **13 migrations**, ausência dos dois schemas próprios e cleanup. |
| [Registry — run 44336307622](https://github.com/rafhael100/imagine-sites/actions/runs/44336307622) | **923 checks totais**, incluindo **62 de registro**, quatro antigos/quatro novos de renderização e um check do schema; clusters próprios inativos. |
| [Laravel — run 44336308278](https://github.com/rafhael100/imagine-sites/actions/runs/44336308278) | Suíte padrão **251 testes/3140 assertions/27 skips** e opt-in sintético **251/3152/21** nas duas versões. |
| [Containers — run 44336308134](https://github.com/rafhael100/imagine-sites/actions/runs/44336308134) | Ensaio e cleanup confirmados. |

`BriefSnapshotInput` compartilha a validação estrita com o modo local existente. Recorder e reader têm uma tentativa e conexão própria; o reader é somente leitura e revalida os quatro seletores exatos. `StoredBriefRendering` retorna HTML/proveniência em memória, sem registro por conta própria. Essa capacidade da #519 é a base histórica da composição validada acima; sua evidência permanece separada da execução própria da migration 14 na #520. O comando local da #518 continua sem DB; endpoint, transporte, produtor Portal e materiais reais permanecem pendentes.

Na preparação local, o scan source-only de **652 arquivos PASS** conservou os **dez gates BLOCKED**; a fixture também recusou execução fora de CI antes de autoload/DB. A evidência final é a dos runs acima, sem atribuir o resultado preliminar 249/3133/27 ao último patch. [Manual dos snapshots](docs/PORTAL-BRIEF-SNAPSHOTS.md); [schema do recibo](contracts/portal-sites/candidates/brief-production-v1/brief-snapshot-receipt.schema.json) e [sexto exemplo sintético](contracts/portal-sites/candidates/brief-production-v1/examples/brief-snapshot-receipt.synthetic.json). O exemplo não prova commit; calendário em JSON Schema exige format checker e o PHP confere UTC6 por round-trip.

## 18/11/2026 — proveniência local do briefing ao artefato

Branch `feature/sites-local-render-provenance-2026-11-18`, sobre a #517 / `c53eac9eb8060a62eb060e68f3bc5b2956dbbdff`. O modo opcional da CLI de renderização confere quatro arquivos privados: plano, manifesto do snapshot, corpo rico Onepage e entrada editorial de seis campos. Verifica estrutura estrita, limites UTF-16, escopo, revisão declarada e hashes dos bytes originais, reutilizando o renderer existente. Após conferir o HTML esperado, reserva `index.html` e `render-provenance.json` exclusivamente, revalida os quatro inputs e mantém os dois descritores até a conferência final. **Validação local PASS: 109 checks do serviço, 80 da nova CLI e os 137/87 anteriores**, com revisão independente de serviço, custódia, fixture e workflow. A fixture mantém os quatro checks anteriores de renderização e acrescenta quatro; o HTML do novo modo alimenta o registro real existente. **PR #518 confirmada no HEAD `907e2883393b776eebcba59fafb672aa34c99bf6`: 12/12 check-runs SUCCESS.** O [run 44323010319](https://github.com/rafhael100/imagine-sites/actions/runs/44323010319) confirmou os quatro checks antigos, quatro novos e **62 de registro/922 registry totais** em PHP 8.3/8.4, com clusters próprios inativos. A [matriz Laravel 44323009592](https://github.com/rafhael100/imagine-sites/actions/runs/44323009592) confirmou padrão **206/2174/27** e opt-in sintético **206/2186/21** nas duas versões. Não houve PostgreSQL local. O sidecar conserva sete campos, com autoridade/publicação/pedidos falsos; stdout preserva a projeção anterior. O perfil de URLs é explicitamente mais estreito que o parser WHATWG do legado. Falhas podem deixar arquivos, sem rollback ou repetição automática. [Implementação, provas e limites](docs/reviews/SITES-LOCAL-RENDER-PROVENANCE-2026-11-18.md).

Verificação complementar do incremento de proveniência acima: domínio sintético **32 checks PASS**, PHPUnit padrão **206 testes/2174 assertions/27 skips PASS** em PHP 8.4.27 e scan de **637 fontes PASS**, mantendo os dez gates BLOCKED.

## 18/11/2026 — contrato candidato do briefing rico até a renderização local

**PR #517 confirmada no HEAD `c53eac9eb8060a62eb060e68f3bc5b2956dbbdff`: 10/10 check-runs SUCCESS**, incluindo [Laravel 44318819131](https://github.com/rafhael100/imagine-sites/actions/runs/44318819131), [laboratório sintético 44318819216](https://github.com/rafhael100/imagine-sites/actions/runs/44318819216) e [safety guard 44318819234](https://github.com/rafhael100/imagine-sites/actions/runs/44318819234). Essa evidência pertence à proposta documental; a implementação do consumidor local está na seção posterior adicionada acima.

Branch `docs/sites-brief-production-contract-2026-11-18`, sobre a candidata unificada #516 / `1634bc45094abfe51b6e391877b4b7f8fdadf692`. A investigação ampliada encontrou o handler e o formulário reais de briefing Onepage no legado Portal #941, fixados em oito blobs. O inventário agora distingue os onze textos/três flags, limites UTF-16, contador agregado da origem, materiais e regras não homologadas. A proposta separa o corpo rico do input editorial de seis campos e define um snapshot candidato e um plano que vinculam os bytes exatos dos quatro arquivos e do HTML esperado. Não pressupõe transformação automática de conteúdo nem usa `sourceState` como autoridade. **Verificação documental local PASS:** três schemas JSON Schema 2020-12 e seus exemplos, recusa de campo extra de autoridade, quatro ligações por SHA/tamanho e coerência de identidade/CAS e limites UTF-16 do exemplo. O renderer existente reproduziu exatamente o HTML sintético de **5167 bytes**, SHA-256 `12549ec46a09e83ea76353a43a48723daa99aa30c6bbdd461923f1a800543d15`. A revisão cruzada de documento/schemas e o scan de 632 fontes passaram. Não há novo runtime, endpoint ou worker nesta proposta. O consumidor local seguinte está delimitado no [contrato candidato](docs/SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md); [schemas e exemplos](contracts/portal-sites/candidates/brief-production-v1/README.md) e [fontes legadas](docs/SITES-F1-20-PORTAL-LEGACY-PARITY.md) permanecem revisáveis.

## 18/11/2026 — candidata unificada de interface e recebimento nativo

Branch `integration/sites-workspace-native-intake-2026-11-18`, baseada na #515 / `a260f9be5dbe11208e287386890169ff9c0fd1cb`. Incorpora os 26 arquivos de aplicação/testes/fixtures da linha de interface #511/#513/#514, byte a byte como em `c5df3ee02f040028cd2655b963c7a7dced07ab7c`, mais os três relatórios correspondentes. README e este status foram reconciliados preservando o recebimento nativo e a migration 12. Os workflows históricos de UI não são duplicados; a candidata possui um workflow próprio do conjunto. **PR #516 validada no HEAD funcional `a92b365a789b3d09638feb95becc5e2dc1d384ca`: 17/17 check-runs SUCCESS**. O [run integrado 44311588248](https://github.com/rafhael100/imagine-sites/actions/runs/44311588248) confirmou **429 verificações workspace, 918 registry e 439 HTTP**. O [F383 44311587726](https://github.com/rafhael100/imagine-sites/actions/runs/44311587726) confirmou **63 verificações nativas**, recorder **18 testes/84 assertions** e rollback das **12 migrations**, com schema ausente. A [matriz Laravel 44311587816](https://github.com/rafhael100/imagine-sites/actions/runs/44311587816) passou em PHP 8.3/8.4 com padrão **206/2174/27** e opt-in sintético **206/2186/21**; o padrão também passou localmente uma vez. Os ensaios HTTP assinado e containers existentes passaram. Clusters e recursos descartáveis foram limpos. Capturas desktop/mobile revisadas e artifact `14430817072` conferido: SHA-256 `81e812da3346e7f6c4c0e5ac5a6068585736ba412d25924c725be0a57f0b813d`. Os 24 arquivos Sites pinados pelo par Portal #941 permanecem idênticos à #512; isso não substitui nova execução pareada. A leitura da Portal #943 confirmou somente diagnóstico de laboratório, mantendo a pendência do corpo oficial versionado do briefing e seu consumidor. [Composição e limites](docs/reviews/SITES-INTEGRATED-CANDIDATE-2026-11-18.md).

## 18/11/2026 — continuidade: cobertura de permissões após o recebimento nativo

Branch `fix/sites-registry-intake-permissions-2026-11-18`, sobre a PR #512 / `9b80387fe1c580436b444a6aad2b63f3baafa37d`. A migration 12 substitui somente o guard do registry para incluir todas as tabelas comuns/particionadas de `sites_core`, preservando as onze bases obrigatórias e os predicados anteriores. Fecha a omissão da tabela nova `portal_project_intakes`, sem alterar grants ou dados. Up/down conferem pins e todos os atributos de `pg_proc` exceto o corpo; a reversão restaura também a limitação antiga. Produto, migration e fixture receberam revisão independente sem bloqueador. **PR #515 validada no HEAD funcional `1732ad5b0d7b60067154c2a3354cd16b99a6658b`: 14/14 check-runs SUCCESS**. O [run 44307367368](https://github.com/rafhael100/imagine-sites/actions/runs/44307367368) confirmou **13 verificações novas/918 registry**, em PHP 8.3/8.4 e PostgreSQL 18/TLS, com reprodução do corpo antigo, quatro ACLs indevidas, recuperação positiva e snapshots completos. O [run nativo 44307366977](https://github.com/rafhael100/imagine-sites/actions/runs/44307366977) confirmou 63 verificações do recebimento, recorder 18 testes/84 assertions e rollback das 12 migrations. A [matriz Laravel 44307367201](https://github.com/rafhael100/imagine-sites/actions/runs/44307367201) passou com suíte padrão 186/1902/27 e opt-in sintético 186/1914/21. Clusters próprios inativos e materiais dos auxiliares limpos. [Contrato e limites](docs/reviews/SITES-REGISTRY-INTAKE-PERMISSIONS-2026-11-18.md).

### Entregas incorporadas e suas evidências de origem

Estas PRs partiram da #510 por outra linha. A candidata unificada descrita acima incorpora o código de aplicação e testes dessa linha à base nativa #515. Os resultados abaixo identificam os próprios SHAs de origem e não substituem a validação nova do conjunto.

| PR / HEAD | Resultado confirmado |
| --- | --- |
| [#511](https://github.com/rafhael100/imagine-sites/pull/511), `d95618bdce599e90ba9b03ff73e9f19c8baee7c1` | Consulta paginada por versão: 15 checks SUCCESS, 25 verificações SQL novas/429 totais e 39 HTTP novas/433 totais; PHPUnit 188/2052/27. [Run 44300875232](https://github.com/rafhael100/imagine-sites/actions/runs/44300875232). |
| [#513](https://github.com/rafhael100/imagine-sites/pull/513), `a21781e1c1143392fbe37239a0d6ecb3938c0339` | Nomes acessíveis por versão: 11 checks SUCCESS, 6 HTTP novas/439 totais; PHP 8.3/8.4, suíte padrão 188/2070/27 e opt-in sintético 188/2082/21. [Navegador](https://github.com/rafhael100/imagine-sites/actions/runs/44303478376), [Laravel](https://github.com/rafhael100/imagine-sites/actions/runs/44303478143). |
| [#514](https://github.com/rafhael100/imagine-sites/pull/514), `c5df3ee02f040028cd2655b963c7a7dced07ab7c` | Links com destinos explícitos e legendas conforme acesso: 11 checks SUCCESS, 439 HTTP; PHP 8.3/8.4, suíte padrão 188/2090/27 e opt-in sintético 188/2102/21. [Navegador](https://github.com/rafhael100/imagine-sites/actions/runs/44304778713), [Laravel](https://github.com/rafhael100/imagine-sites/actions/runs/44304778161). |

As capturas desktop/mobile dessas três entregas foram revisadas e os artifacts conferidos por SHA-256: #511 `14426790594` / `d5e4d5e0b20941e549da82664a082512e0ce9fef932e165cf53db8fe4909005e`; #513 `14426143530` / `1d5c7ccfef8a0266e0fa5b9ef82d1904132b48d60075bd15d0fe158b3b478582`; #514 `14428565756` / `494b756e9dd3e89e78062e45b045bf8eb25b020fadced6b8d211d0c1d898bacd`. Os ambientes descartáveis tiveram a limpeza confirmada.

## 18/11/2026 — recebimento nativo pendente do Portal (candidato em revisão)

Sobre a HEAD Sites #510 `61a8d1db212d298051e6f64f8b923e545a4c6bb3`, o serviço Laravel
`PortalProjectIntakeRecorder` grava referências, projeto, outbox bloqueado e recibo
no schema nativo `sites_core`, com capacidade de recebimento própria default false.
Recibo somente após commit; replay exato conserva site/operação/chave, conflitos
são recusados e `tenantRef` é comparado à referência da organização do vínculo SQL.
A validação local passou em PHP8.4/PostgreSQL18 com 63 verificações reais,
18 testes/84 assertions novos e suíte completa de 186 testes/1902 assertions/27 skips.
Down/reapply da migration preservou projetos e não restaurou capacidades; os 32
checks de domínio e o safety guard passaram. O rollback exato do batch 1 também
removeu `sites_core` após todos os seeds/casos nativos. Revisão independente de
SQL/migration/serviço concluída sem bloqueadores funcionais. **CI remota da PR #512
confirmada no HEAD `9b80387fe1c580436b444a6aad2b63f3baafa37d`: 14/14 check-runs SUCCESS**.
O [run 44303070110](https://github.com/rafhael100/imagine-sites/actions/runs/44303070110)
passou em PHP 8.3/8.4, confirmando 63 verificações nativas, 18 testes/84 assertions
do recorder e a reversão completa do schema descartável.
Nenhum transporte, autoridade financeira, geração, instalação ou publicação foi
ativado; os dez gates permanecem BLOCKED. Detalhes: [contrato do recebimento](docs/PORTAL-PROJECT-INTAKE.md).


## 18/11/2026 — continuidade: consulta privada da associação

Branch `feature/sites-binding-inspection-2026-11-18`, sobre a PR #509 / `b288c40ee08186fb17509bc064c1ed5c63ace015`. A função `read_release_binding_head(uuid)` e a CLI `inspect-binding.php inspect` consultam o último evento de associação de um site conhecido, com leitor independente e sessão somente leitura. Projeto próprio sem evento retorna quatro campos nulos; ausência/empresa diferente conserva recusa uniforme. O histórico permanece observável mesmo após mudanças posteriores de elegibilidade, sem atribuir uma chamada perdida ou autorizar repetição. Migration010 cria somente a função sob owner existente. **172 assertions offline do serviço, 49 checks da CLI e PHPUnit 168 testes/1818 assertions/27 skips PASS**, com pins, sintaxe, workflow e revisões independentes do serviço, SQL e CLI. A fixture PostgreSQL/CLI confirmou **55 verificações novas/905 registry e 404 workspace**, incluindo histórico, isolamento, autoridade corrente, rollback/reapply e limpeza exclusiva. **PR #510 validada em `61a8d1db212d298051e6f64f8b923e545a4c6bb3`: 14/14 check-runs SUCCESS**. O [run 44296336981](https://github.com/rafhael100/imagine-sites/actions/runs/44296336981) passou em PHP 8.3/8.4 com PostgreSQL 18, arquivos próprios limpos e clusters inativos; o [run 44296337182](https://github.com/rafhael100/imagine-sites/actions/runs/44296337182) confirmou também as migrações e rollback legados. [Contrato e limites](docs/reviews/SITES-BINDING-INSPECTION-2026-11-18.md).

## 18/11/2026 — continuidade: limite consistente para novas versões

Branch `fix/sites-preview-revision-ceiling-2026-11-18`, sobre a PR #508 / `108e1211f05a42576b7a3a2b69eb9e9e0db23b17`. Corrige a validação que permitia criar a versão 10001 e impedia a edição seguinte. A revisão esperada máxima passa a 2147483646, conservando espaço para o sucessor INTEGER. A versão 2147483647 permanece legível e revisável; o workspace mostra o limite, conserva comparação/histórico/HTML e não oferece append ou convite de reenvio impossível. Permissões de edição e revisão permanecem distintas da capacidade numérica. **Parser 12 testes/191 assertions, nova view 7/76, conjunto de views 39/441 e suíte completa 168 testes/1818 assertions/27 skips PASS**, com revisão independente sem bloqueadores. As fixtures confirmaram **18 checks SQL novos/404 totais e 24 HTTP novos/394 totais**, com seeds esparsos, appends reais, leitura/revisão terminal e negativas sem mutação. **PR #509 validada em `b288c40ee08186fb17509bc064c1ed5c63ace015`: 19/19 check-runs SUCCESS** no [run 44294561272](https://github.com/rafhael100/imagine-sites/actions/runs/44294561272), com PHP 8.3/8.4, PHPUnit 168/1818/27 e limpeza completa. As capturas desktop/mobile foram revisadas; artifact `14422868379`, SHA-256 `f35ec976d4b6ea08aea02dbdadb8346e6fe543efa683d3fa33088e05bb081b4b`, conferido. [Comportamento e limites](docs/reviews/SITES-PREVIEW-REVISION-CEILING-2026-11-18.md).

## 18/11/2026 — continuidade: distinguir o briefing ainda não salvo

Branch `fix/sites-preview-unsaved-brief-2026-11-18`, sobre a PR #507 / `4f4339b752986c2ab4ac754d011a9c1b1134a302`. A resposta de validação 422 ou conflito 409 passa a identificar explicitamente os campos preservados como tentativa ainda não salva. Um painel expansível nativo mostra os seis campos do briefing salvo, revisão e digest do head na mesma página, inclusive quando o histórico exibido está em outra janela. As legendas de edição, comparação e histórico deixam de apresentar a tentativa como versão atual. A perda de permissão de edição com leitura mantida conserva rótulos corretos e não oferece POST. Criação falha não inventa um snapshot. **Sete testes/78 assertions novos, conjunto de views 32/365 e suíte completa 159 testes/1702 assertions/27 skips PASS**, com revisão independente do controller e views. A jornada HTTP confirmou **30 checks novos/370 totais**, respostas 422/409 por navegação real, painel sem JavaScript/navegação e snapshots das cinco tabelas. **PR #508 validada em `108e1211f05a42576b7a3a2b69eb9e9e0db23b17`: 19/19 check-runs SUCCESS** no [run 44244700974](https://github.com/rafhael100/imagine-sites/actions/runs/44244700974), com PHP 8.3/8.4, 386 workspace, PHPUnit 159/1702/27 e limpeza completa. As capturas desktop/mobile foram revisadas; artifact `14401282122`, SHA-256 `89ca63094f7a4aa30cda91dff2a5128f9b59e0458188a47fa9ad556e78f5f9f8`, conferido. [Comportamento e limites](docs/reviews/SITES-UNSAVED-BRIEF-2026-11-18.md).

## 18/11/2026 — continuidade: consulta editorial exata

Branch `feature/sites-editorial-inspection-2026-11-18`, sobre a PR #506 / `23f52d8ef6c542109f6271f33d06b218fbcd5d1a`. Acrescenta a função de leitura `read_my_site_editorial_head(uuid)` e a CLI privada `inspect.php inspect`, com um leitor separado, vínculo de empresa derivado da sessão e nova conferência de acesso após a leitura. O resultado limitado informa revisão/estado/digest, com os três nulos para projeto autorizado sem histórico. A migration009 cria somente a função, sem novos grants de LOGIN, tabelas, políticas ou roles; sua reversão preserva os dados e APIs anteriores. A consulta ajuda a apuração após resposta perdida, sem atribuir a revisão a uma tentativa ou autorizar retry. **166 assertions offline do serviço e 45 checks da CLI aprovados**, com revisão cruzada, sintaxe, workflow e safety guard; PHPUnit **152 testes/1624 assertions/27 skips PASS**. A fixture confirmou **53 checks novos/850 registry**, com snapshots completos, rollback/reaplicação e revogação durante consulta bloqueada; revisão independente aprovada. **PR #507 validada em `4f4339b752986c2ab4ac754d011a9c1b1134a302`: 15/15 check-runs SUCCESS** no [run 44243164734](https://github.com/rafhael100/imagine-sites/actions/runs/44243164734), com PHP 8.3/8.4, 386 workspace, PHPUnit 152/1624/27, oito negativos de custódia e limpeza completa. A primeira execução identificou uma variável compartilhada incorreta no ator de controle da fixture; o ajuste deriva a identidade da conexão verificada, sem mudar o produto. [Contrato, interpretação e limites](docs/reviews/SITES-EDITORIAL-INSPECTION-2026-11-18.md).

## 18/11/2026 — continuidade: recuperação da busca na fila

Branch `fix/sites-queue-search-recovery-2026-11-18`, sobre a PR #505 / `c32c0668032eec78a4a9da5c9e2ba4eb90e1269e`. O GET da fila passa a recuperar termos seguros de 101–200 caracteres Unicode, até 800 bytes, mantendo o valor integral escapado, filtros e mensagem junto ao campo. A composição da resposta não consulta a fila; resultados, aviso de vazio e paginação são omitidos. Corrigir/limpar começa na primeira página. O parser de navegação e o limite efetivo de 100 caracteres/400 bytes permanecem iguais; entradas estruturais indevidas, projeto e POST conservam as recusas estritas. **Suíte local completa: 152 testes/1624 assertions/27 skips PASS**, com revisões independentes do controller e view. A fixture HTTP confirmou **28 checks novos/340 totais**, com jornada sem JavaScript, snapshots completos, três capturas e recusa após revogação. **PR #506 validada em `23f52d8ef6c542109f6271f33d06b218fbcd5d1a`: 19/19 checks SUCCESS** no [run 44240462109](https://github.com/rafhael100/imagine-sites/actions/runs/44240462109), com 386 SQL em PHP 8.3/8.4 e PHPUnit 152/1624/27. As capturas desktop/mobile e busca corrigida foram revisadas; artifact `14399133746`, SHA-256 `8204550def2a6311d5c32ac1b72e1a067ccdacf327c8a5a89211189af43334f4`, conferido. A limpeza dos laboratórios foi confirmada. [Comportamento e limites](docs/reviews/SITES-QUEUE-SEARCH-RECOVERY-2026-11-18.md).

## 18/11/2026 — continuidade: solicitação privada do diário técnico

Branch `feature/sites-publication-request-2026-11-18`, sobre a PR #504 / `f7cef0082644eee3ef74815a7130f62dbb5e3b8b`. A candidata acrescenta `request.php plan|apply` sobre a API idempotente já existente. O manifesto vincula alvo, release, revisão esperada da associação, referências declaradas e novo nome de recibo. A aplicação exige confirmação do plano e principal restrito a `request_operation`. O resultado público conserva somente a projeção técnica; a referência da operação fica em recibo privado de três campos para preparar a inspeção posterior. A reserva do arquivo ocorre antes do SQL e sua gravação depois; falha pode seguir commit e não implica rollback. Replay aceita o estado mais recente e exige autoridade corrente, sem exigir o autor/epoch histórico. **434 checks offline do serviço, 207 da CLI/recibo, seis de cleanup e PHPUnit 141/1310/27 aprovados localmente**, com lint, workflow, revisões independentes e safety guard. A fixture confirmou **81 verificações novas/797 registry**, com pipeline CLI, quatro estados de replay, isolamento, falha pós-commit e recuperação explícita; as tentativas usam sites distintos. **PR #505 validada em `c32c0668032eec78a4a9da5c9e2ba4eb90e1269e`: 12/12 check-runs SUCCESS** no [run 44239108310](https://github.com/rafhael100/imagine-sites/actions/runs/44239108310), com PHP 8.3/8.4, 386 workspace, PHPUnit 141/1310/27, sete negativos reais de custódia, limpeza completa e clusters descartáveis inativos. A execução inicial recusou o ator editorial incorreto na fixture; a correção usa o revisor previsto no SQL, sem mudar o produto. [Contrato e falhas entre recursos](docs/reviews/SITES-PUBLICATION-REQUEST-2026-11-18.md).

## 18/11/2026 — continuidade: briefing local para artefato privado

Branch `feature/sites-local-artifact-rendering-2026-11-18`, sobre a PR #503 / `48d190f22ef9000dc52db55cc6b56510df6f7624`. A nova CLI recebe os seis campos locais já suportados por `SiteBrief` e reutiliza renderer/audit para gerar um `index.html` privado novo, com digest e tamanho para o registro existente. Preserva os comandos Artisan de fixtures fixas e os avisos do renderer. Não representa briefing oficial, não promove `sites_preview` e não acessa banco/rede. Custódia de input, saída exclusiva e recusa de overwrite fazem parte do recorte. **137 checks de serviço e 87 de CLI aprovados localmente**, além de lint, workflow, revisão independente e safety guard. A fixture confirmou **4 verificações novas, 58 de registro e 716 no conjunto**, com o arquivo realmente gerado consumido pelo pipeline anterior. **PR #504 validada em `f7cef0082644eee3ef74815a7130f62dbb5e3b8b`: 17/17 check-runs SUCCESS** no [run 44236130507](https://github.com/rafhael100/imagine-sites/actions/runs/44236130507), com PHP 8.3/8.4, 386 workspace, PHPUnit 141/1310/27, seis negativos reais de custódia, limpeza completa e clusters descartáveis inativos. [Escopo e consumidor](docs/reviews/SITES-LOCAL-ARTIFACT-RENDERING-2026-11-18.md).

## 18/11/2026 — continuidade: recuperação da comparação no workspace

Branch `fix/sites-preview-comparison-recovery-2026-11-18`, sobre a PR #502 / `5234e3ca36aeadbb363435ebef1f23a26ea9852e`. Seleções iguais, invertidas ou incompletas passam a mostrar erro422 junto ao formulário, preservando valores seguros, busca/filtros/cursor, histórico e briefing/head atual. O parser de navegação continua estrito; o par inválido não é consultado. Entradas estruturais indevidas, versão ausente e falhas de acesso preservam as recusas próprias. Comparação continua fora de POST e sessão. **37 testes/466 assertions focados e suíte completa 141 testes/1310 assertions/27 skips aprovados**, com lint, workflow, revisão independente e safety guard. A fixture confirmou **39 checks HTTP novos/312 totais**, incluindo correção pelo formulário, POST recusado sem mutação e snapshots completos. **PR #503 validada em `48d190f22ef9000dc52db55cc6b56510df6f7624`: 19/19 check-runs SUCCESS** no [run 44234103268](https://github.com/rafhael100/imagine-sites/actions/runs/44234103268), com 386 SQL em PHP 8.3/8.4, PHPUnit 141/1310/27 e limpeza completa. As capturas desktop/mobile e comparação corrigida foram revisadas; artifact `14396079009`, SHA-256 `244c00f1953794e539bd56b957d071ab667bf6604c756851404cd09b03cf98e5`, conferido. [Comportamento e verificação](docs/reviews/SITES-COMPARISON-RECOVERY-2026-11-18.md).

## 18/11/2026 — continuidade: recibos privados dos auxiliares de CI

Branch `fix/sites-ci-receipt-custody-2026-11-18`, sobre a PR #501 / `889bf55bde70e1ba617cb4fcde89e2c284c742e3`. Os dois helpers antigos de package/inspection passam a manter laboratório e recibo por descritores, incluindo alteração e restauração do negativo pelo mesmo arquivo aberto. A correção fecha a reabertura por pathname durante o subprocesso, preservando paths, owners, modos e pais opcionais. A regressão usa somente diretórios temporários próprios. **14 testes de recibo e 18 de material privado aprovados localmente**, com sintaxe, workflow, revisão independente e safety guard aprovados. **PR #502 validada em `5234e3ca36aeadbb363435ebef1f23a26ea9852e`: 17/17 check-runs SUCCESS.** O [run 44232778190](https://github.com/rafhael100/imagine-sites/actions/runs/44232778190) confirmou PHP 8.3/8.4 com 14 testes de recibo, 18 de material privado, 712 registry, 49 reconciliação, 386 workspace e PHPUnit 134/1186/27, seis negativos reais, limpeza completa e clusters descartáveis inativos. [Escopo e verificação](docs/reviews/SITES-FIXTURE-RECEIPT-CUSTODY-2026-11-18.md).

## 18/11/2026 — continuidade: reconciliação explícita do diário

Branch `feature/sites-publication-reconciliation-2026-11-18`, sobre a PR #500 / `3eacc40b2f323b246015510cad1ac60a5c17239a`. A CLI `reconcile.php plan|apply` usa a API de reconciliação existente, com principal separado, três inputs privados, revisão esperada e confirmação exclusiva do plano. O plano de seis campos não consulta a operação nem comprova prazo vencido. O resultado de 16 campos mantém `technicalStatus=inconclusive`, `canPublish=false` e `publicationVerified=false`; pode incluir recibo de ACK tardio sem provar nova inserção ou igualdade com o ator/epoch histórico. Não há retry, reset de lease, reenvio ou verificação externa. **271 checks offline do serviço e 81 da CLI aprovados**, com regressões de diário **99**, inspeção **150** e CLI de inspeção **21**, PHPUnit **134 testes/1186 assertions/27 skips**, sintaxe e revisão cruzada. O safety guard passou em cópia de fontes, mantendo os dez gates `BLOCKED`. O workflow fixa draft, branch, SHA da base e sete opt-ins em PHP 8.3/8.4. A fixture executou **49 verificações novas e 712 registry**, incluindo a recusa entre empresas após provar elegibilidade pelo reconciliador da empresa B. **PR #501 validada em `889bf55bde70e1ba617cb4fcde89e2c284c742e3`: 17/17 check-runs SUCCESS.** O [run 44231736687](https://github.com/rafhael100/imagine-sites/actions/runs/44231736687) confirmou os resultados em PHP 8.3/8.4, com 386 workspace, seis negativos de recibo, limpeza completa e clusters descartáveis inativos. [Relatório e limites](docs/reviews/SITES-PUBLICATION-RECONCILIATION-2026-11-18.md).

## 18/11/2026 — continuidade: associação e revogação explícitas

Branch `feature/sites-release-binding-2026-11-18`, sobre a PR #499 / `9789cd050e1d4b88b9c469149cb796985ed615a2`. A CLI `binding.php plan|bind|revoke` usa as APIs existentes com credenciais e confirmações distintas, alvo verificado, plano somente leitura, custódia relida e um CAS explícito por chamada. O SQL associa o artefato imutável à revisão editorial aprovada ou registra a revogação, preservando histórico. As respostas não inferem inserção versus repetição e mantêm publicação/pedidos reais falsos. O ensaio percorre a cadeia de CLIs com atores separados, sem reativar o autor já revogado.

**PR #500 validada em `3eacc40b2f323b246015510cad1ac60a5c17239a`: 12/12 check-runs SUCCESS na conferência final.** O [run 44229954311](https://github.com/rafhael100/imagine-sites/actions/runs/44229954311) passou em PHP 8.3/8.4 com **63 verificações novas de associação/revogação, 663 registry, 386 workspace, 351 checks offline do serviço, 128 da CLI e PHPUnit 134 testes/1186 assertions/27 skips**. Os cinco negativos de recibo, a limpeza binding → editorial → registration → inspection → package e os clusters inativos foram confirmados. A correção da fixture que antecedeu essa execução não alterou código do produto. [Relatório e evidência](docs/reviews/SITES-RELEASE-BINDING-2026-11-18.md).

## 18/11/2026 — continuidade: transição editorial explícita

Branch `feature/sites-editorial-transition-2026-11-18`, sobre a PR #498 / `dedbae34474e68f4ce9f1d28d457d730ec18998a`. A CLI `transition.php plan|apply` solicita uma ação editorial pela API SQL existente, com bytes exatos, revisão esperada, confirmação de hash e flag exclusiva. O plano não consulta head, duty privado ou artefato no registry; o SQL decide a autoridade e o estado corrente ao aplicar. O wrapper relê os quatro inputs antes de habilitar escrita apenas na nova sessão. Repetição editorial não é idempotente: resposta perdida pode seguir commit, sem retry ou incremento automático. Revisão interna mantém aceite do cliente e publicação falsos. **307 checks offline do serviço, 76 da CLI e 18 da custódia comum aprovados**, com revisão cruzada e sintaxe; PHPUnit local **134 testes/1186 assertions/27 skips**. **PR #499 validada em `9789cd050e1d4b88b9c469149cb796985ed615a2`: 18/18 checks SUCCESS.** O [run 44227302252](https://github.com/rafhael100/imagine-sites/actions/runs/44227302252) passou em PHP 8.3/8.4 com **68 verificações editoriais novas, 600 registry e 386 workspace**; os 307+76 checks offline e os 18 testes de custódia também passaram. A limpeza dos quatro helpers e o encerramento do cluster descartável foram confirmados. [Relatório e limites](docs/reviews/SITES-EDITORIAL-TRANSITION-2026-11-18.md).

## 18/11/2026 — continuidade: registro privado de artefatos

Branch `feature/sites-artifact-registration-2026-11-18`, sobre a PR #497 / `fb97fa465d162c23fa1ced8d1e0092ecc30798b7`. O comando `register.php plan|record` recebe bytes privados exatos e usa a API de registro já existente. O plano somente leitura vincula alvo, seletores e HTML; a escrita exige hash revisado, flag exclusiva, nova conferência de custódia e principal SQL restrito. Registra somente o artefato; aprovação editorial e associação continuam separadas. Repetição respeita identidade/bytes/autor/epoch, sem retry automático.

**PR #498 validada em `dedbae34474e68f4ce9f1d28d457d730ec18998a`: 17/17 check-runs SUCCESS na conferência atual.** O [run 44225215421](https://github.com/rafhael100/imagine-sites/actions/runs/44225215421) passou em PHP 8.3 e 8.4 com **54 verificações novas SQL/CLI, 532 registry, 76 inspeção, 386 workspace e PHPUnit 134 testes/1186 assertions/27 skips**. Os ensaios offline passaram **271 checks do serviço e 65 da CLI**. O fluxo real inclui consumo separado por `prepare`/`verify`, negativa de recibo e limpeza por custódia. A conferência não autoriza staging, publicação ou operação. [Relatório e evidência](docs/reviews/SITES-ARTIFACT-REGISTRATION-2026-11-18.md).

## 18/11/2026 — continuidade: texto literal na comparação de versões

Branch `fix/sites-inert-text-audit-2026-11-18`, sobre a PR #496 / `1219c49272b8df387aef7b7e12de4a812d1284c5`. A revisão do fluxo encontrou um falso bloqueio: briefing válido com URL literal ou expressão como `onboarding=aberto` era salvo pelo workspace e recusado posteriormente pela auditoria da comparação. A correção distingue texto escapado dos atributos e estilos ativos, preservando o HTML e o digest originais.

**PR #497 validada em `fb97fa465d162c23fa1ced8d1e0092ecc30798b7`: 15/15 check-runs SUCCESS.** O [run 44223033260](https://github.com/rafhael100/imagine-sites/actions/runs/44223033260) passou **273 checks HTTP, 16 novos SQL/386 workspace, 402 registry, 115 F27, 20 F24 e PHPUnit 134/1186/27**, em PHP 8.3/8.4. Capturas desktop/mobile revisadas; artifact `14390993429` conferido por SHA-256 `890c4da894eaabfab6c863f92f74d6e54111d5fd78a5f80b071e3f2b7f6bfd4d`. Revisão independente e safety guard aprovados. [Relatório e escopo](docs/reviews/SITES-INERT-TEXT-COMPARISON-2026-11-18.md).

## 18/11/2026 — continuidade: consulta privada do diário de publicação

Branch `feature/sites-publication-inspection-2026-11-18`, sobre a PR #495. A CLI `inspect` consulta uma operação conhecida pela API SQL existente, com principal restrito, alvo/TLS pinados, sessão somente leitura e resposta limitada a 14 campos técnicos. Não lista operações, altera estado, reconcilia prazo, cria acesso ou chama publisher. Histórico com binding revogado permanece distinguido da autorização corrente.

**PR #496 validada no SHA funcional `a91877eadc5a69b958af196d72d0ed2e3c999636`: 12/12 check-runs SUCCESS na conferência da entrega.** O [run 44220440360](https://github.com/rafhael100/imagine-sites/actions/runs/44220440360) passou em PHP 8.3 e 8.4: **76 verificações de inspeção com PostgreSQL/TLS e CLI reais, 478 de registry, 370 de workspace e PHPUnit 134/1186/27**. Os 27 skips da suíte geral são explícitos; os contratos de banco dedicados executaram suas verificações. A negativa de recibo e a limpeza exclusiva dos arquivos da fixture também passaram. Validação offline: **150 checks do serviço e 21 da CLI**, com regressões e revisão independente aprovadas. Esta atualização registra a evidência sem alterar o código funcional validado. [Relatório de inspeção](docs/reviews/SITES-PUBLICATION-INSPECTION-2026-11-18.md).

O [próximo contrato de integração](docs/reviews/SITES-NEXT-INTEGRATION-2026-11-18.md) registra as fontes Portal inspecionadas e as definições que faltam para briefing oficial e geração. O staging existente não foi alterado; os dez gates operacionais continuam BLOCKED.

Conferência da HEAD documental `1219c49272b8df387aef7b7e12de4a812d1284c5`: **17/17 check-runs SUCCESS**. A sincronização do draft acionou automaticamente o [run 44221426152](https://github.com/rafhael100/imagine-sites/actions/runs/44221426152), também aprovado em PHP 8.3/8.4 com as mesmas contagens. A diferença de código para o SHA funcional anterior é nula.

## 18/11/2026 — continuidade: limites Unicode e briefing inválido

Branch `feature/sites-brief-unicode-validation-2026-11-18`, sobre a PR #494. Os cinco campos deixam de bloquear emojis válidos pela contagem UTF-16 do navegador e exibem limites e erros acessíveis. Domínio e parser recusam NUL antes do banco; o middleware não o remove silenciosamente nas extremidades. Respostas422 preservam os demais campos e texto válido acima do limite, limpando somente o campo malformado com orientação para redigitar.

Suíte local integrada: **134 testes/1186 assertions/27 skips explícitos**, com regressões de busca, notas e sessão. **PR #495 validada em00597a48cd4513a32dff9bbc3f9c639e09e2a9e3:16/16 check-runs SUCCESS na conferência da entrega**. [Run44217865114](https://github.com/rafhael100/imagine-sites/actions/runs/44217865114):175 navegador,14 briefing/370 workspace SQL,402 registry, upgrade9+76, PHPUnit134/1186/27 em PHP 8.3/8.4. Artifact14389791091 conferido por SHA-256 `239022cba81dd16e56a34d3c5737360882f0c47a32bbeefd08624d0998d2aebe`; capturas desktop/mobile revisadas. Nenhuma migration ou autoridade nova; detalhes no [relatório de briefing](docs/reviews/SITES-BRIEF-UNICODE-VALIDATION-2026-11-18.md).

## 18/11/2026 — continuidade: atualização explícita do preview gerenciado V1

Branch `feature/sites-preview-managed-upgrade-2026-11-18`, na sequência da PR #493. O candidato ganha `upgrade-plan`, `upgrade-apply` e `upgrade-check`, com manifesto próprio, origem V1 exata e autorização de CLI distinta. Somente a migration000008 é aplicada sob o owner existente. A linha004 conserva ID/batch;008 entra em batch posterior, com origem registrada e verificada no marcador V2. DDL, ledger, marcador e postcheck são atômicos; não há renovação de bindings, reparo de permissões ou downgrade automático.

Instalação nova continua com seu fluxo e batch conjunto. A fixture parte do provisionador V1 original pinado, em processo separado, com PostgreSQL/TLS descartável e histórico fictício populado. **PR #494, HEAD79863ad89f5701e0122f320f0437598b90f9193d: upgrade real aprovado com9 checks de seed e76 de contrato**, incluindo falhas transacionais e concorrência da CLI, no [run44215333265](https://github.com/rafhael100/imagine-sites/actions/runs/44215333265). **Validação final em c85657477c50aa1ba66b4fc2ef0af134c5c25686:14/14 check-runs SUCCESS**. [Run44217150101](https://github.com/rafhael100/imagine-sites/actions/runs/44217150101): upgrade9+76, workspace356 e registry402, navegador139; PHP 8.3/8.4 e PHPUnit122/854/27 skips explícitos. Correção de sessão da PR #493 incorporada. Nenhum V1 no VPS foi confirmado ou atualizado. Detalhes: [relatório de upgrade gerenciado](docs/reviews/SITES-PREVIEW-MANAGED-UPGRADE-2026-11-18.md).

## 18/11/2026 — continuidade: busca por marca ou chamada principal na fila

Branch `feature/sites-queue-search-2026-11-18`, sobre a PR #492 / `ae3a9bd58c89d0c76b7d983c0206a9cad6bf80f8`. A busca consulta somente o briefing da versão atual, junto com os filtros de parecer/formato e a paginação. O contexto acompanha a abertura de projetos, histórico, comparação e retorno à fila. Alterar ou limpar a busca retorna à primeira página; limpar apenas a busca conserva os demais filtros.

O termo é literal, limitado a100 codepoints Unicode/400 bytes e validado antes de aparar espaços nas bordas. Maiúsculas/minúsculas usam casefold Unicode do PostgreSQL18, inclusive em banco localeC. `%`, `_` e barra invertida não se tornam curingas; acentos e composição Unicode não são normalizados. Campo vazio passa pelo middleware Laravel sem erro e controles inválidos não são aparados silenciosamente. Nenhuma migration, grant ou autoridade nova.

**PR #493 validada em f0a2f361d04297bfbafd5d11f55f3ab299a8e000:15/15 check-runs SUCCESS**. [Run44216520003](https://github.com/rafhael100/imagine-sites/actions/runs/44216520003):33 verificações de busca e356 do workspace em PHP 8.3/8.4,139 de navegador, PHPUnit122/854/27 skips explícitos. O preview remove o histórico de URL da sessão para manter cabeçalhos compactos; CSRF permanece verificado. Capturas desktop/mobile revisadas e artifact14388718594 conferido por SHA-256 `ce47477f728fd2c2af3f8c4181d74c99f4a7e3be30a4faef7c74ca7646ad39e3`. Detalhes: [relatório de busca na fila](docs/reviews/SITES-QUEUE-SEARCH-2026-11-18.md).

## 18/11/2026 — continuidade: parecer editorial com orientação persistida

Branch `feature/sites-editorial-feedback-2026-11-18`, sobre a PR #491 / b2210ec. O revisor informa os ajustes na própria versão; o autor consulta a orientação corrente mesmo em páginas antigas do histórico. Texto e decisão são preservados em422/409, sem sessão e sem reaplicar o envio a outra revisão. A nova versão mantém o parecer anterior no histórico e aguarda avaliação própria.

Migration000008 preserva reviews legadas, OID/ACL da assinatura antiga e histórico; novos ajustes exigem nota com até2000 caracteres Unicode. O provisionamento novo aplica004+008 atomicamente com fingerprint composto, e instalações managedV1 exigem upgrade explícito. Validação local: PHPUnit102/509/27 skips explícitos, lint e verificações offline aprovados. **PR #492 validada em ae3a9bd58c89d0c76b7d983c0206a9cad6bf80f8: 17/17 check-runs aprovados**. [Run44209215949](https://github.com/rafhael100/imagine-sites/actions/runs/44209215949): 323 verificações do workspace,402 do registry/ciclo/pacote/diário,101 de navegador; PHP 8.3/8.4. Migrations/rollback e integração Laravel dedicada sem skips no [run44209215528](https://github.com/rafhael100/imagine-sites/actions/runs/44209215528). Capturas desktop/mobile revisadas; artefato14385586419, SHA-256 `3327e822d7738ddcb6210b5d2fea6120095fb832e8c0ab0f665762aac9fe758d`. Detalhes: [relatório de orientação editorial](docs/reviews/SITES-EDITORIAL-FEEDBACK-2026-11-18.md).

## 18/11/2026 — continuidade: diário durável de tentativas de publicação

Nova branch isolada `feature/sites-publication-journal-2026-11-18`, sobre a PR #490 / `ad649b3f10bdee93837e9443efc9bf4b1edeb85c`. Migration000007 e wrapper PDO acrescentam intenções idempotentes, tentativa única por empresa/site, lease por relógio PostgreSQL, ACK técnico, reconciliação de expiração e leitura sem token. O histórico é imutável; um ACK tardio após revogação ou reabertura editorial permanece inconclusivo. O replay recupera o mesmo registro e não autoriza envio nem garante exclusividade entre processos do mesmo login.

Workflow novo restrito à branch/base/draft e matriz PHP 8.3/8.4; fixture de dois processos concorrentes, grants restritos, epochs, expiração e rollback recusado com histórico. Inventário legado atualizado sem relaxar a lista exata de políticas. **PR #491 validada em b2210ec32064ceec2faf393e85117369e185e5a8: 14/14 check-runs success**. [Run 44206897492](https://github.com/rafhael100/imagine-sites/actions/runs/44206897492): 107 verificações do journal, 402 totais do registry/ciclo/pacote/journal, 287 do workspace; PHP 8.3/8.4. [Run 44206897543](https://github.com/rafhael100/imagine-sites/actions/runs/44206897543): 99 verificações do schema, rollback e integrações Laravel dedicadas sem skips. PostgreSQL local não iniciado. Detalhes: [relatório do journal](docs/reviews/SITES-PUBLICATION-JOURNAL-2026-11-18.md).

Nenhum provider, transporte, principal ativo, retry, resolução ou desbloqueio instalado. `canPublish=false`, `publicationVerified=false`; aceite do Portal é somente referência declarada neste diário. Os dez gates, o bloqueio de rollback automático e a infraestrutura existente permanecem preservados.

## 17/11/2026 — continuidade: registro persistente, provisionador e navegação editorial

Base de desenvolvimento: PR #489 em `cac9084b4fbb0b2a5cf50ac843e89010fb9a019c`; branch DRAFT `feature/sites-release-readiness-2026-11-17`. O candidato acrescenta registro Sites de artefato/pedido/contrato/revisão, leitor PDO independente para o verificador de evidências existente, provisionamento `plan/apply/check` das prévias com owner NOLOGIN e ledger Laravel, fila paginada por formato/parecer e histórico paginado que mantém o briefing atual e não oferece revisões duplicadas.

O workflow restrito executa contratos SQL e jornada HTTP descartáveis sobre o HEAD da branch. SHAs, resultados efetivamente executados e artefatos ficam na descrição da PR; este registro de implementação não equivale a evidência operacional. Detalhes e limites: `docs/reviews/SITES-RELEASE-READINESS-2026-11-17.md`.

PR #490 aberta em `88cd4cc452c3be44162fa47c4e0a09c6af5969f9`: primeira execução SQL aprovada no run `44197904708`, com 250 verificações de prévias e 168 do registro persistente. Em continuidade, migration000006 e writer Laravel permitem reabrir revisão aprovada internamente, criar novo draft e exigir nova revisão/associação/aceite da versão exata; não há reutilização automática da aprovação anterior. O ciclo novo é exercitado pelo mesmo ensaio real de banco e pelos verificadores existentes.

A segunda entrega, `36f10cfc22bd7c10ab82839c53726481104704e4`, passou nos 16 check-runs, incluindo 191 verificações de registro/ciclo editorial e 56 de navegador no run `44199094329`. A próxima peça acrescentada é a preparação privada do pacote técnico: HTML intacto, manifesto da associação corrente e relatório de verificações, sempre sem autorização de publicação/aceite. O CLI usa leitor SQL restrito e TLS verify-full. A correção dos testes dedicados Laravel em `bb75ece9834b521cc1494b46e132a7755223d20f` exige execução sem skips: o run `44200772489` passou em PHP 8.3/8.4; em PHP8.4, o leitor executou 3 testes/12 asserções e o escritor 2 testes/12 asserções.

A preparação privada foi confirmada em `5afa3b653061dfcaac176b2204801cd5a619850a`, com os 16 check-runs aprovados. [Run 44201915769](https://github.com/rafhael100/imagine-sites/actions/runs/44201915769): 270 verificações de registry/ciclo/pacote, incluindo 36 da CLI real com TLS; 250 do workspace; 56 de navegador. Artifact `14381821977`, SHA-256 `0d6caf73d43b1e09e73657c6f389b3714e110553c71be1deade99acb9edb9a29`; filtros desktop/mobile revisados.

Comparação editorial e `verify` confirmados em `ad649b3f10bdee93837e9443efc9bf4b1edeb85c`, com 16/16 check-runs aprovados. [Run 44203027317](https://github.com/rafhael100/imagine-sites/actions/runs/44203027317): 287 checks SQL do workspace, 295 do registro/pacote (61 da CLI), 85 do navegador, 50 offline do pacote, PHPUnit 80 testes/312 asserções/27 skips explícitos. Artifact `14382942257`, SHA-256 `00074b8bddf202f56cb09b7ce9db531b436538ac883295e7843a6f00b7cc8e9f`; comparação desktop/mobile revisada. A edição permanece na versão corrente ao comparar versões antigas, e pacotes adulterados/obsoletos são recusados sem sobrescrita. A descrição da PR #490 contém a evidência final.

Nenhuma demonstração é promovida a pedido real. Registro técnico, liquidação e aceite continuam fontes independentes. Os dez gates operacionais permanecem BLOCKED, sem merge, deploy, alteração de VPS/18090 ou ativação de publisher.

## 17/11/2026 — PR #489 DRAFT: fila editorial e correções validadas no CI

Base de continuidade: PR #488, `ef1a8ba888c8862108b9329e0301764dbcbe8ddb`. Recuperação já comprovada nessa PR não foi duplicada. Branch `feature/sites-preview-validation-2026-11-17` acrescenta fila da versão corrente por empresa/digest, tratamento de briefing inválido sem perda de campos, conflito HTTP409, produto imutável e ações de tela conforme perfil. O guard de runtime passa a recusar deriva de privilégios, inclusive grants por coluna, PUBLIC/herança, TRUNCATE/TRIGGER, CREATE e escrita direta em revisão/auditoria.

**Código funcional verificado:** `9ad188aa197e43135b5a992de5dfcf00df4cd8aa`. [PR #489](https://github.com/rafhael100/imagine-sites/pull/489). [CI #44190839583](https://github.com/rafhael100/imagine-sites/actions/runs/44190839583): **250 verificações PostgreSQL18** (63 configurações de privilégio indevido e oito verificações da fila), **33 verificações HTTP/navegador** com Nginx/FPM/PG18 separados, TLS verify-full, CSRF, revisão independente, empresa cruzada, briefing preservado, conflito 409, Unicode, reinício e revogação. Todos os13 check-runs da HEAD funcional terminaram success (inclui duplicados push/PR). Imagens desktop/mobile revisadas; artifact14376686334, somente dados sintéticos, SHA256 `0446fd16afe09999c2af153b143bc50ebc426003a0bd9907034a8cd8684895d2`.

Validação local: lint PHP8.4, 32 domínio, 27 renderer, 61 auditoria HTML, 26 structured-copy e dez testes novos de briefing/render com41 assertions aprovados. PostgreSQL local não pôde abrir socket; a evidência SQL vem exclusivamente do CI. A primeira execução revelou um ParseError Blade antes da jornada; corrigido com regressão de render real e nova execução integral aprovada. Workflow novo restrito ao draft/branch/base exatos e a mudanças de código; workflows anteriores intactos. Revisão independente detectou limite de transporte pequeno para Unicode, corrigido com teto32KiB no proxy candidato e aplicação.

A fila informa revisão interna e não autoriza contrato, aceite de cliente ou publicação. Integração Portal/pagamento/registry de vínculos, backup externo e homologação continuam pendentes. VPS, porta18090, main/dev e serviços preservados. Detalhes: `docs/reviews/SITES-PREVIEW-VALIDATION-2026-11-17.md`.

## 16/11/2026 — PostgreSQL verify-full REAL na Leaseweb, ambiente preservado (PR483 DRAFT)

Fonte dd91486352bdc45b68e1b6289546759cc36360f5, CI11 check runs success incluindo43810046212. Em09:07–09:08 Brasília, TLS foi ativado por SIGHUP no PostgreSQL18 existente, sem restart; CA/hostname corretos verificados e CA/hostname errados/plaintext recusados. App Sites passou a verify-full com a mesma imagem;52 outros contêineres, banco, proxy, volumes/redes e porta127.0.0.1:18090 preservados. HTTPS privado200 com acesso/401 sem acesso. Preflight real postgres_tls=verified, global blocked. Nenhum pedido/PSP/prévia operacional liberado. Relatório: docs/SITES-P0-EXISTING-TLS-2026-11-16.md. Portal901 prepara ledger Sandbox nativo, sem ativação ou credenciais; não é conciliação real.

## 16/11/2026 — staging privado REAL na Leaseweb (PR482 DRAFT)

O usuário reconectou o console. Sites Laravel/Nginx/PostgreSQL18 iniciados no projeto exclusivo imagine-sites-p0-staging, bind127.0.0.1:18090, quatro redes e volume próprios. Runtime SQL mínimo/FORCE RLS e HTTPS200/401/404 verificados. Os50 contêineres anteriores mantiveram estado/início/reinícios. Backup cifrado+HMAC e restore em banco novo preservaram oito tabelas FORCE RLS. Código04c6a29,13 check runs success (incluem duplicados push/PR).

Somente painel técnico privado e /up; sem URL pública/túnel liberado ou fluxo de compra operacional. Preflight real segue blocked: TLS PostgreSQL verify-full, identidade Portal, Asaas Sandbox, aceite humano, revogação, backup externo e publicação/rollback pendentes. Sem produção, DNS, cobrança ou merge. Relatório: docs/SITES-P0-PRIVATE-STAGING-2026-11-16.md. Notas anteriores de ausência de acesso descrevem o estado ANTES da reconexão.

## 15/11/2026 — diagnóstico real Leaseweb e ensaio integrado atual (DRAFT)

- Console já autenticado permitiu diagnóstico somente leitura; SSH não está configurado no executor. Ubuntu24.04.5, Docker/Compose e recursos observados. Preflight PR478 executado na VPS manteve `blocked` e nove gates operacionais fechados.
- Conflito real: porta127.0.0.1:18081 prevista para Sites já pertence ao CRM. Nenhum serviço alterado. Novo diagnóstico consulta binds/listeners e metadados de backup sem ler segredos ou conteúdo.
- 14 testes Python locais e guarda F1-21 executados com sucesso. Nova workflow cruza Portal PR759 pinado com Sites F395/F396/F397 em PHP 8.3/8.4; resultado da revisão `d899b3402cc32bc5155841ed30309e607a73d651`: 15/15 checks recuperados aprovados. CI pareada#43774939689 validou146 asserções PHP por versão8.3/8.4 e14 testes Python; HTTP Laravel+PG18, bridge e contêiner também passaram.
- Executor privado opcional de domínio usa imagem PHP local, rede none/source read-only e compara contêineres antes/depois, sem falsificar CI ou habilitar gateways. Execução real na VPS comprovou32 asserções de domínio, sem rede/DB/migração; inventário dos contêineres existentes antes/depois igual. Isso não comprova homologação funcional completa.
- Matriz funcional e bloqueios: `docs/SITES-P0-LEASEWEB-INTEGRATED-REHEARSAL-2026-11-15.md`. Sem merge, produção, cobrança, DNS ou publicação.

## 14/11/2026 — contrato Portal↔Sites assinado e mTLS real em laboratório (PRs DRAFT)

- [Imagine Portal PR #733](https://github.com/rafhael100/imagine-portal/pull/733), **F1-387** — emissor Ed25519 do Portal compatível com o validador do Sites F1-386. Exige fonte de contrato, liquidação PSP e revogação independentes, ausentes por padrão. [CI PHP 8.3/8.4 #43567144112](https://github.com/rafhael100/imagine-portal/actions/runs/43567144112) **PASS** e validação do Portal vigente PASS. Não emite comprovante operacional.
- [Imagine Sites PR #471](https://github.com/rafhael100/imagine-sites/pull/471), **F1-388** — cliente `PortalProofMtlsHttpSource` executa POST HTTPS com CA, certificado de cliente e chave privada montados, verificação de hostname, sem redirect/proxy, com limite de corpo/tempo. CI usa CA e certificados efêmeros, o código original do emissor F1-387 e verificador F1-386 no fluxo completo, inclusive negativa de CA/client certificate errados, fraude de tenant, assinatura adulterada e revogação. [CI PHP 8.3/8.4 #43568005091](https://github.com/rafhael100/imagine-sites/actions/runs/43568005091) **PASS**. Sem endpoint público ou segredo real.
- [Imagine Portal PR #735](https://github.com/rafhael100/imagine-portal/pull/735), **F1-389** — resolvedor privado `PortalSitesEntitlementResolver` para a consulta de contrato F1-388, com principal S2S autenticado como pré-requisito e default deny; compara organização, contrato, revisão, produto e fingerprint antes de retornar prova F1-387. [CI PHP 8.3/8.4 #43568349600](https://github.com/rafhael100/imagine-portal/actions/runs/43568349600) **PASS**. Sem rota HTTP ou autenticação mTLS operacional no Portal.
- **Bloqueios reais ainda abertos:** PSP de assinatura/pagamento **sem provedor escolhido/homologado**, banco oficial de contratos e liquidação, fonte monotônica de revogação, IAM serviço→serviço e identidade real de cliente, vault/certificados oficiais, Restore/backup fora da VPS, API privada Portal com TLS, aceite do cliente na versão exata, editor/publisher com rollback e revisão independente AppSec/LGPD. Os testes são somente laboratório; **não declaramos compra aceita, site publicado ou GO-LIVE**.
- **Sem merge, DNS, Cloudflare, deploy ou cobrança real** nas branches desta sequência. A ordem correta continua autenticidade do PSP/Portal e IAM → validação na infraestrutura Leaseweb com restore e isolamento → aceite explícito do cliente → publicação controlada e rollback.


## 14/11/2026 — fundações operacionais de leitura, editorial e elegibilidade independente (DRAFT)

- [PR #467](https://github.com/rafhael100/imagine-sites/pull/467), **F1-384** — segunda migration PostgreSQL18 versionada adiciona vínculo controlado de `session_user` à empresa, função SQL READ-ONLY com executor NOLOGIN/RLS e classe Laravel `TenantSiteStatusReader`. No CI, dois logins de empresas distintas leem só seus projetos; revogação do login, SET ROLE e falsificação de GUC são negados. **5/5 workflows PASS em HEAD** [CI #43558521940](https://github.com/rafhael100/imagine-sites/actions/runs/43558521940). Nenhuma API pública ou IAM Portal ativo.
- [PR #468](https://github.com/rafhael100/imagine-sites/pull/468), **F1-385** — terceira migration PostgreSQL18 com `advance_editorial_revision`, editor/revisor de identidades SQL distintas, registro append-only e CAS de revisões; Onepage e Landing com 9 revisões, rework, revogação, isolamento de empresa e `client_accepted=false`/`publishable=false`. Integração Laravel `EditorialRevisionWriter` **PASS PHP 8.3/8.4** [CI #43558979055](https://github.com/rafhael100/imagine-sites/actions/runs/43558979055). Sem aceite do cliente ou publisher.
- [PR #469](https://github.com/rafhael100/imagine-sites/pull/469), **F1-386** — classe `IndependentPortalCommercialGateway` exige documento atual do Portal assinado Ed25519, testemunho **independente** de liquidação e testemunho **independente** de revogação. Testes artificiais de assinatura, versão, timeout e negativas aprovados PHP 8.3/8.4 [CI #43559372698](https://github.com/rafhael100/imagine-sites/actions/runs/43559372698). **Os três adaptadores reais não existem**, e o gateway de aplicação segue negador por padrão, sem rota.
- A cadeia #467→#468→#469 permanece **DRAFT/NO-GO, sem merge ou deploy**. A revisão DBA/AppSec ainda deve validar login SQL por empresa e SECURITY DEFINER para escala SaaS; as roles, PSP e chaves usadas no GitHub são **inteiramente fictícias**.
- **Próximo P0 externo indispensável para vendas reais:** fonte autoritativa de contrato/pagamento no Imagine Portal conectada ao PSP contratado, credenciais S2S armazenadas em vault e revogação independente; vinculação IAM Portal ao leitor SQL; provisionamento revisado da Leaseweb com TLS/backup remoto+restore; piloto real de aceite do cliente e publisher com rollback. Sem essas fontes não existe homologação real de checkout, nenhuma rotina deve marcar GO ou publicar um site de cliente.


## 13/11/2026 — integração da compra à prévia e fundação operacional (em PRs DRAFT)

- [PR #462](https://github.com/rafhael100/imagine-sites/pull/462), **F1-380** — pedidos fictícios persistidos pelo código original do Portal em PostgreSQL18 com FORCE RLS seguem por assinatura e HTTP local até o Laravel Sites em outro PostgreSQL18. Só Onepage/Landing completos entram; Performance, pendente, briefing ausente e empresa cruzada recusados. [CI #43543919610](https://github.com/rafhael100/imagine-sites/actions/runs/43543919610) **PASS**. PSP e contrato reais ausentes.
- [PR #464](https://github.com/rafhael100/imagine-sites/pull/464), **F1-381** — dois projetos admitidos alimentam `StructuredCopyCandidate` e `StaticSiteRenderer`; cria prévias Onepage/Landing com painel visual Imagine e manifesto de integridade, sob leitura SQL vinculada à empresa. [CI #43544718914](https://github.com/rafhael100/imagine-sites/actions/runs/43544718914) **8/8 workflows PASS**, pacote fictício nos artifacts por sete dias. Não há IA real, cliente ou publicação.
- [PR #465](https://github.com/rafhael100/imagine-sites/pull/465), **F1-382** — editor e revisor com logins SQL separados; revisão por versão/digest e 9 eventos auditáveis no PostgreSQL; ambas as prévias aguardam aceite do Portal, que não pode ser simulado como publicação. [CI #43545334803](https://github.com/rafhael100/imagine-sites/actions/runs/43545334803) **PASS**. Estado de cliente e publicação permanece falso em constraints.
- [PR #466](https://github.com/rafhael100/imagine-sites/pull/466), **F1-383** — primeira migration Laravel13 com 6 tabelas `sites_core` (empresa, contrato, projeto, nonce, revisão, outbox), FORCE RLS **sem políticas permissivas**, autoridade/pagamento/aceite/publicação travados em falso. [CI #43546094018](https://github.com/rafhael100/imagine-sites/actions/runs/43546094018) **PHP 8.3/8.4 PASS**, incluindo migração, testes SQL adversariais e rollback em banco efêmero. Não aplicar diretamente à VPS sem revisão DBA/AppSec.
- **Bloqueios P0 reais para terminar:** (1) identidade Portal↔Sites autenticada e prova oficial de contrato+pagamento PSP; (2) origem independente de revogação e aceite do cliente em versão/digest exatos; (3) migração/role/RLS operacional revisados, segredos no vault, TLS real e backups externos com restore; (4) pipeline de produção real da prévia para publicação/rollback, DNS/Cloudflare e monitoramento; (5) AppSec/LGPD e validação ponta a ponta na Leaseweb com autorização explícita. Todos seguem **NO-GO**.
- **Nenhum merge nesta cadeia #462/#464/#465/#466, nenhum deploy ou gasto externo.** As contas, pagamento e dados são fictícios nos testes. Não confundir workflows verdes com homologação operacional.


**09/11/2026 — integração de desenvolvimento e entrega de critérios de homologação.**

- `dev` consolidou F1-01..22 via PRs #24–#29, mantendo `main` intacta.
- [PR #30](https://github.com/rafhael100/imagine-sites/pull/30) — F1-23, classes reais candidatas Portal↔Sites testadas entre repositórios, PHP 8.3/8.4 CI PASS e merge controlado em `dev`.
- [PR #31](https://github.com/rafhael100/imagine-sites/pull/31) — F1-24, pacote de prévia privado, hash e `publishable=false`, PHP/Laravel CI PASS e merge controlado em `dev`.
- [PR #32](https://github.com/rafhael100/imagine-sites/pull/32) — F1-25, T0 do banco, destino CRM imutável e purge de metadados fictícios em PostgreSQL efêmero, CI PASS e merge controlado em `dev`.
- [PR #40](https://github.com/rafhael100/imagine-sites/pull/40) — F1-26: rotação sintética de chave com carência de até 120s, DENY de chave anterior e replay compartilhado. **CI PHP 8.3/8.4, Laravel e safety guard PASS em `e33a4af`; merge controlado em `dev` no commit `82e63a1`.** Não é identidade operacional nem fecha OP01.
- [PR #42](https://github.com/rafhael100/imagine-sites/pull/42) — F1-27, auditoria offline de HTML sintético Onepage/Landing, bloqueio de conteúdo ativo/externo e negativos após recálculo de hashes. **CI PHP 8.3/8.4, Laravel, F1-24 e safety guard PASS.** Sem publicação; OP03/OP05 permanecem abertos.
- [PR #43](https://github.com/rafhael100/imagine-sites/pull/43) — F1-28, fluxo sintético de produção/revisão Imagine e proposta não publicável ao Portal. PHP 8.3/8.4, Laravel e safety guard PASS, integrado em `dev` (`e4b631d`). **Não concede identidade/aceite ou autorização de produção.**
- [PR #44](https://github.com/rafhael100/imagine-sites/pull/44) — F1-29, estrutura estrita para proposta textual fictícia de IA com marca/produto definidos fora do gerador. CI PHP 8.3/8.4, Laravel e safety guard PASS; integrado em dev (`3fab91d`). Sem executar IA real.
- [PR #45](https://github.com/rafhael100/imagine-sites/pull/45) — F1-30, créditos abstratos e reservas idempotentes por tenant/operação com negação por quota. CI PHP 8.3/8.4, Laravel e safety guard PASS; merge em `dev` (`6048da6`). **Não é controle de custos reais.**
- [PR #46](https://github.com/rafhael100/imagine-sites/pull/46) — F1-31, teste offline encadeado F1-27..30 e validação estrita de proposta de revisão Portal. Sem aceite oficial, publicação ou ENTREGA. CI PHP 8.3/8.4, Laravel e safety guard PASS; integrado em `dev` (`025707d`).
- [PR #48](https://github.com/rafhael100/imagine-sites/pull/48) — F1-32, histórico append-only PostgreSQL 18 descartável, RLS, versão/digest, revogação sintética e relógio DB. **CI PHP/Laravel, RLS, PostgreSQL e safety guard PASS; integrado em `dev` (`d15514a`).** Não é persistência operacional.
- [PR #49](https://github.com/rafhael100/imagine-sites/pull/49) — F1-33, outbox de proposta de revisão Portal com digest/revisão exatos, ACL append-only e negativas. **CI Postgres/PHP/Laravel/safety guard PASS após correção de teste; integrada em `dev` (`8562009`).** Sem dispatcher.
- [PR #50](https://github.com/rafhael100/imagine-sites/pull/50) — F1-34, view com `security_invoker`/RLS que oculta propostas de revisão obsoletas e preserva histórico append-only. **CI PostgreSQL 18, RLS, Laravel e safety guard PASS; merge em `dev` (`4329440`).** Não é dispatcher nem autorização oficial.
- [PR #52](https://github.com/rafhael100/imagine-sites/pull/52) — F1-35, claim único SQL de proposta de revisão atual, com RLS, versão, digest, revogação e unicidade. **5 workflows CI PASS; integrado em `dev` (`7cd20e8`).** Sem dispatch real nem aceite.
- [PR #53](https://github.com/rafhael100/imagine-sites/pull/53) — F1-36, créditos fictícios em Postgres descartável com quota transacional e teste concorrente de duas conexões. CI PostgreSQL, PHP/Laravel e safety guard PASS; merge em `dev` (`c9a14fe`). Não é gasto real.
- [PR #54](https://github.com/rafhael100/imagine-sites/pull/54) — F1-37, comando Laravel CLI de teste gera Onepage/Landing fictícias, pacote privado e proposta Portal com digest exato, sem aceitação nem publicação. CI PHP 8.3/8.4, Laravel, bundle e safety guard PASS; merge em `dev` (`a8d26fc`).
- [SITES-HOMOLOGATION-ENTRY](docs/SITES-HOMOLOGATION-ENTRY-2026-11-09.md) — matriz de entrada e bloqueadores com issues #33–#38.

## 13/11/2026 — persistência em banco descartável, sem integração operacional

- [PR #444](https://github.com/rafhael100/imagine-sites/pull/444) — F1-360: admissão SQL ligada a tenant autenticado, idempotência, transação atômica com outbox e rollback; F1-361: worker SQL restrito, lease, reentrega e ACK por token; F1-362: código candidato do Portal e do Sites com provas assinadas chega ao PostgreSQL real, negando tenant divergente. **CI PostgreSQL18 PHP 8.3/8.4 PASS** [run #43512452420](https://github.com/rafhael100/imagine-sites/actions/runs/43512452420). Todos em banco efêmero.
- F1-363: nonce de replay persistente entre workers, na mesma transação da admissão/outbox, **CI PostgreSQL18 PHP 8.3/8.4 PASS** [run #43513143395](https://github.com/rafhael100/imagine-sites/actions/runs/43513143395).
- **F1-364** — novo componente LabTransactionalSignedIngressV1 de laboratório encapsula verificação de assinatura e atestação, nonce durável e admissão/outbox na mesma transação, com COMMIT/ROLLBACK controlado. Testes de duas contratações, replay, conflito entre tenant assinado e login SQL, falha de outbox e bloqueio por flag/nested transaction: **CI PostgreSQL18 PHP 8.3/8.4 PASS** [run #43514102206](https://github.com/rafhael100/imagine-sites/actions/runs/43514102206). Não é endpoint operacional.
- A F1-362 apenas utiliza código candidato pinado do Portal e do PR #443; nenhum serviço HTTP real, pagamento, credencial operacional, fonte independente de revogação, backup/restore ou ambiente homologado foi ativado. PR #444 segue **DRAFT/NO-GO**, com main/dev/VPS intactos, 10/10 gates ainda falsos.

## 13/11/2026 — fluxo HTTP bidirecional em laboratório, sem VPS

- [PR #445](https://github.com/rafhael100/imagine-sites/pull/445), F1-365: comando fictício assinado pelo código candidato Portal atravessa HTTP 127.0.0.1 até trabalhador Sites PHP separado, com nonce/admissão/outbox no PostgreSQL18. **CI PHP 8.3/8.4 PASS** [#43516241272](https://github.com/rafhael100/imagine-sites/actions/runs/43516241272).
- [PR #446](https://github.com/rafhael100/imagine-sites/pull/446), F1-366: outbox do Sites para mock HTTP do Portal, MAC de recibo, ACK por lease só após confirmação válida, falhas offline/HTTP503/recibo falso. **CI PASS** [#43516901253](https://github.com/rafhael100/imagine-sites/actions/runs/43516901253).
- **F1-367** no mesmo PR #446: dois contêineres PostgreSQL18 descartáveis e independentes por instância, um do Sites e outro do receptor fictício Portal. Recibo só é assinado depois de COMMIT do receptor; testes de rollback, reentrega, conflito e deduplicação. **CI PHP 8.3/8.4 PASS** [#43517567819](https://github.com/rafhael100/imagine-sites/actions/runs/43517567819).
- **Sem promoção operacional:** as provas são somente entre processos/contêineres efêmeros do GitHub; não são as instâncias Portal/Sites/CRM da VPS, não usam pagamento, IAM ou vault reais e não testam DR externo. PRs empilhados #444→#445→#446 permanecem DRAFT/NO-GO, sem merge/deploy. Os dez gates continuam bloqueados.

## 13/11/2026 — recuperação após perda HTTP, sem integração operacional

- [PR #448](https://github.com/rafhael100/imagine-sites/pull/448), F1-368: receptor Portal de teste confirma um recibo em PG18 independente mas simula perda da resposta HTTP. Sites conserva o evento sem ACK, o CI reinicia o processo receptor e outro trabalhador recupera o evento por estado persistido com novo lease; Portal retorna recibo assinado `already_recorded`, sem duplicar, e só então Sites confirma. **PG18 PHP 8.3/8.4 PASS** [run #43519387668](https://github.com/rafhael100/imagine-sites/actions/runs/43519387668).
- Os PRs #444 → #445 → #446 → #448 são candidatos de laboratório empilhados; todos seguem **DRAFT/NO-GO**. Isto não comprova falha de banco, restauração de backup, IAM Portal S2S, pagamento, privacidade, DNS ou HTTPS real. `main`, `dev`, VPS e Cloudflare não foram alterados; dez gates ainda BLOCKED.

## 13/11/2026 — backup/restore efetivo em terceira instância PG18 descartável

- [PR #449](https://github.com/rafhael100/imagine-sites/pull/449), **F1-369**: HTTP Portal fictício confirma registro e perde a resposta antes do ACK do Sites; GitHub Actions usa `pg_dump` e `pg_restore` PostgreSQL 18 em **terceiro contêiner**, para o banco do receptor fictício. Desliga o contêiner original e valida reentrega idempotente, MAC do recibo, novo token lease, conflito de digest, ACK final e nenhuma duplicação.
- **CI PostgreSQL18 PHP 8.3/8.4 PASS** [#43529184768](https://github.com/rafhael100/imagine-sites/actions/runs/43529184768), com dados sintéticos. **Não é disaster recovery operacional:** backups e instâncias no mesmo runner efêmero, sem backup externo, RPO/RTO, fonte real de contratos, IAM, TLS ou revogação independente. PRs #444→#445→#446→#448→#449 permanecem DRAFT/NO-GO, sem merge/deploy/VPS/DNS; gates BLOCKED.

## 13/11/2026 — restauração e evidência de recibos, sem homologação real

- [PR #449](https://github.com/rafhael100/imagine-sites/pull/449): F1-369 `pg_dump` + `pg_restore` verdadeiros em três PostgreSQL18 descartáveis; preserva recibo após resposta HTTP perdida, reinicia receptor na base restaurada e impede duplicação. **PHP 8.3/8.4 PASS** [#43529522002](https://github.com/rafhael100/imagine-sites/actions/runs/43529522002).
- [PR #451](https://github.com/rafhael100/imagine-sites/pull/451): F1-370 bloqueia backup antigo mesmo sendo restaurável, detectando recibos faltantes, digest adulterado ou registro extra. **PHP 8.3/8.4 PASS** [#43531366968](https://github.com/rafhael100/imagine-sites/actions/runs/43531366968).
- [PR #452](https://github.com/rafhael100/imagine-sites/pull/452): F1-371 grava o MAC de recibo validado pelo worker e a confirmação SQL em uma transação, permitindo detectar a perda do recibo no Portal com evidência do banco Sites. **PHP 8.3/8.4 PASS** [#43531839196](https://github.com/rafhael100/imagine-sites/actions/runs/43531839196). SQL não autentica o recibo sozinho; MAC validado no worker de CI e chaves fictícias.
- **NO-GO**: PRs permanecem empilhados em DRAFT, sem merges ou deploy. Backups estão no mesmo GitHub runner, não na Leaseweb; IAM, contratos/pagamentos, TLS/vault, revogação externa, LGPD/AppSec, RPO/RTO e evidência independente operacional permanecem bloqueados.

## 13/11/2026 — validação criptográfica dos recibos no próprio PostgreSQL

- [PR #453](https://github.com/rafhael100/imagine-sites/pull/453), F1-372: `pgcrypto` verifica o HMAC do recibo Portal fictício dentro do PostgreSQL18 antes do ACK e da gravação de evidência. Chave verificadora só na tabela privada da fixture; worker SQL não tem SELECT. Permissões de ACK antigo sem verificação foram revogadas na role de CI. Provas de MAC falso, SHA adulterado, replay e tentativa de bypass. **PHP 8.3/8.4 e os quatro workflows PASS** [run #43532504039](https://github.com/rafhael100/imagine-sites/actions/runs/43532504039).
- **Limite:** chave HMAC é fictícia/pública no GitHub e o código usa somente bancos descartáveis e HTTP em loopback. Não é segurança operacional; faltam serviço Portal autenticado, segregação de chaves/vault, TLS, pagamento e contratos oficiais, AppSec, LGPD, banco externo e governança de deploy. PRs permanecem DRAFT/NO-GO; main/dev/VPS/DNS intocados.

## 13/11/2026 — primeiro ensaio da aplicação Laravel em contêineres, não operacional

- [PR #454](https://github.com/rafhael100/imagine-sites/pull/454), **F1-373**: Dockerfile multi-stage Laravel13/PHP-FPM+Nginx, Compose com proxy local **127.0.0.1:18081**, rede privada de aplicação, runtime read-only, APP_KEY de staging externa, banco intencionalmente não provisionado. Build de imagens, HTTP `/up` 200 e ausência de rotas inseguras **PASS** [CI #43538005653](https://github.com/rafhael100/imagine-sites/actions/runs/43538005653). Nenhum deploy na Leaseweb.
- [PR #455](https://github.com/rafhael100/imagine-sites/pull/455), **F1-374**: comando Laravel `sites:homologation-preflight` somente leitura; verifica ambiente, debug, chave, URL e, se houver banco real configurado, identidade PostgreSQL18, role sem superuser e TLS. Mantém hard-blocked IAM Portal, contrato/pagamento, revogação, backup externo, cliente e release. **6 workflows CI PASS**, incluindo Laravel PHP 8.3/8.4 [#43538535626](https://github.com/rafhael100/imagine-sites/actions/runs/43538535626) e validação do comando dentro do Docker [#43538535545](https://github.com/rafhael100/imagine-sites/actions/runs/43538535545). JSON não mostra credenciais; operação retorna NO-GO.
- Os PRs empilhados seguem **DRAFT/NO-GO**, sem merges ou deploys. A API verdadeira de criação de projeto, identidade S2S, contratos/pagamento Portal, banco operacional, segurança AppSec e aprovação continuam bloqueados.

**Ainda não há ambiente operacional Sites publicado.** P0/RF-PEND/RV-CT/AppSec/LGPD, contratos financeiros, identidade, Cloudflare/DNS, banco produtivo, geração real por IA/n8n, captação/CRM fisicamente redundante e backups/restore continuam não homologados.

**Gate exclusivo do responsável:** conexão autorizada de ambiente Leaseweb + Cloudflare e domínio técnico de teste, com gestão segura de credenciais/quotas, antes de qualquer homologação real. Não inserir credenciais no GitHub público ou neste chat.

`config/operational-readiness.json` continua com gates BLOCKED. `AGI-11-RB01` DESABILITADO; sem promoção de `dev` para `main`, deploy, DNS de cliente ou dados reais.


## P0 — 16/11/2026: stack privado preparado, VPS inacessível nesta continuação

A partir da PR481/b7908e3, novo Compose `ops/staging/compose.yaml` de projeto exclusivo `imagine-sites-p0-staging`, porta obrigatória indicada por inventário fresco, redes/volume/banco próprios, runtime SQL mínimo, segredo via file mount e ingressos HTTPS/mTLS separados. O inventário rejeita18081 do CRM, binds Docker inclusive parados, listeners, colisões e recursos insuficientes. A console autenticada foi encerrada e SSH não está configurado no executor: nenhuma porta foi declarada livre, nenhum serviço iniciado ou banco criado na VPS. Asaas Sandbox, identidade/aceite/revogação operacionais e publisher seguem NO-GO. Evidências/requisitos: `docs/SITES-P0-PRIVATE-STAGING-2026-11-16.md`.

Evidência da PR482: SHAfuncional e4647169948448db90fdb11a4118c71dd20dfb99,13 check runs success; CI43801276171 aprovou stack próprio HTTPS/mTLS/PG18/RLS e restore apenas no runner. Sete testes de inventário e dois de MountedSecret aprovados. Execução real na VPS continua bloqueada por console encerrada/SSH ausente; não há porta livre comprovada nem URL nova.


## 2026-11-16 — laboratório complementar de ownership/perfis sobre #486

Base `a4db62ac07566fb6b0bd9a27988c2ad4c0622c56`. Novo teste/runner apenas; migration e workflows intactos. Owner NOLOGIN/NOBYPASSRLS e autor/revisor/leitor em duas empresas especificados. Sintaxe e 32 checks de domínio passaram; **SQL novo não executado** por restrição conhecida do executor local. Nenhuma prova de backup/aceite operacional. [Relatório e próxima execução](docs/reviews/SITES486-ROLE-CONTRACT-LAB-2026-11-16.md).

Aprovação posterior verificada: workflow novo `sites-preview-role-contract.yml`, restrito à branch complementar em draft, preparado com extração PG18 autenticada do #485. Sintaxe validada; teste SQL permanece pendente de CI desta branch.

## Candidato local: recuperação do workspace após #487 (17/11/2026)

Base `3bbc9dda632bd4405fe9cfc99249f55642a992ce`. Ensaio separado de dump/restore do namespace `sites_preview`, somente fixtures sintéticas, com dois clusters e guard Laravel/SQL. Novos testes/documentação; nenhuma migration/workflow/produção alterada. 17 comparações offline e sintaxe passaram; PostgreSQL não iniciado por restrição local anterior, execução SQL/restore pendente. Reutilizados, sem repetição, 109 SQL e 32 domínio do #487. Detalhes e comandos em `docs/SITES-PREVIEW-RECOVERY-LAB.md`. Não publicado; NO-GO operacional.

Revisão local fail-closed: 44 casos offline passaram (inclui falhas inesperadas não aceitas, destinos runtime divergentes, DDL/ACL e paths privados). Laravel isolado sem dotenv/cache, reconexão proibida, PDO/owner pinados, builds/ferramentas e cleanup reforçados. Sem SQL/startup/publicação; restore NÃO comprovado.

Revisão local de entrada interna: --clean valida exports e PHP/INI/extensões antes de qualquer cluster. Oito casos CLI e 44 guards offline passaram. Único workflow novo `sites-preview-recovery-lab.yml` preparado SOMENTE LOCALMENTE para revisão, restrito a draft da branch proposta `test/sites-preview-recovery-pg-lab-2026-11-17` sobre #487 SHA3bbc9dd. Não publicado, não executado e sem autorização implícita de execução; workflows preexistentes preservados.
