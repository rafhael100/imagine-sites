# STATUS-ATUAL — Imagine Sites Engine

## F1-338 — ZIP privado promovido de staging verificado em operação atômica — 13/11/2026

- **F1-338 / PR #422**, SHA `b344dc5eef99bc5be6e8612b7f15642a12c7116c`: substitui a criação antecipada do nome definitivo do ZIP por staging privado aleatório de modo 0600, fsync, readback integral de todos os bytes e promoção atômica `renameat2(RENAME_NOREPLACE)` no Linux. Não existe fallback a `rename` que sobrescreva destino. Revalida o nome final após a promoção e fsync da pasta. Limpa somente inode criado por este processo em falha, sem tocar destino concorrente. Testes adversariais cobrem interrupção de gravação, observação durante a escrita, concorrente que ganha a criação do nome e corrupção simulada pós-rename. **5/5 verificações CI retornadas `success` no SHA individual**.
- **Revisão simplificada:** o PR de backup ZIP offline isolado **#418** (base `dev`) recebeu a mesma F1-338, o novo teste de falhas e a matriz CI para análise mais curta. O PR independente de domínio/SEO **#417** e o plano de proteção de branches **#411** seguem disponíveis e separados do histórico de centenas de arquivos.
- **Candidato de integração:** a partir desta etapa inclui F1-302–338 acumulados; exigir o inventário F1-240 e seis pacotes F1-241, testes de ZIP PHP 8.3/8.4 e Laravel no SHA exato antes de revisão. `dev/main` não sofreram merge.
- **Não operacional:** ZIP continua contendo apenas `index.html` e `release-manifest.json` fictícios; `scopeAuthenticated=false`, `operationalRestore=false`, `publishable=false`, sem backup/restore do PG/RLS, PII, Portal autenticado, CRM real, Cloudflare, VPS, DNS ou custos. `config/operational-readiness.json` mantém `BLOCKED` com dez gates `false`. Issue #281 (proteção real + revisor externo) e OP01–OP05 (#33–#38) permanecem abertas.

---

## F1-336–337 — manifesto canônico e propriedade Unix dos backups sintéticos — 13/11/2026

- **F1-336 / PR #419**, SHA `6ddcd62f7c87fe4409721abf33df6e795a16a951`: ZIP privado aceita apenas bytes de manifesto JSON canônico do builder (`JSON_PRETTY_PRINT`, ordem fixa e newline). Rejeita versões JSON compactadas, campo Unicode escapado e remoção do newline mesmo com hashes HTML/bundle recalculados. **4/4 workflows retornados success**, incluindo F1-24 com arquivos Laravel fictícios.
- **F1-337 / PR #420**, SHA `0b84f1cb271e4728b7c1f3e7f3df13ab76762720`: leitura/escrita de ZIP e recuperação verificam UID efetivo do diretório e dos arquivos, além de permissões 0700/0600. Testes adversariais simulam arquivos 0644 e outro UID. **4/4 workflows retornados success**; sem alegar isolamento de tenants que compartilham a mesma conta Unix.
- **Revisão dividida:** o PR isolado de backup #418 (contra `dev`, cerca de dez arquivos) foi sincronizado com ambas as correções, para evitar divergência do candidato grande. PR isolado da política apex/SEO #417 e PR isolado de proteção do GitHub #411 permanecem independentes, sem merge e sem regras administrativas aplicadas.
- **Candidato integrado atual:** base `dev`, cadeia anterior F1-302–335 e F1-336/337; reexecutar CI, F1-240 (inventário diff) e F1-241 (seis pacotes de revisão) no novo SHA. `dev/main` permanecem intocadas. `config/operational-readiness.json` segue `BLOCKED` com 10/10 gates `false`.
- **Sem autorização operacional:** dados fictícios, sem cliente, sem backup do PostgreSQL, Portal/IAM autenticado, retenção/PII, Cloudflare, VPS, DNS, API real ou custos. Issue #281 de proteção de branches/revisor independente e OP01–OP05 #33–#38 seguem como bloqueios. Não fazer merge/deploy antes da homologação.

---

## F1-332–335 — integridade e escopo dos backups privados — 13/11/2026

- **F1-332 / PR #412**, SHA `9131d9f03d8d2fbd52c37f3595cbf84669200992`: restauração sintética exige pins independentes de tenant/site/release, além do hash do HTML e pacote; nega backup de outra empresa com manifesto e hashes próprios válidos **antes de criar staging**. Evidência `scopeMatched=true`, `scopeAuthenticated=false`. **5/5 fluxos retornados success**.
- **F1-333 / PR #413**, SHA `613360d78d0ddce697951cac93d3a66f38dfa985`: a mesma validação independente de tenant/site/release é compartilhada no ZIP `create`, `inspect` e `restore`. CLI de exportação/inspeção exige as três flags e o workflow F1-24 passa a tripla dos fixtures Onepage/Landing. **5/5 fluxos retornados success**.
- **F1-334 / PR #414**, SHA `d56002fc7bd1d7fcf70b98fac85423774ac2b387`: leitura e criação de arquivos ZIP são negadas se o diretório de backup for acessível a grupo/outros, e a pasta final não pode ser um symlink. CI prepara explicitamente pasta 0700 para cada backup; testes negativos para 0755. **5/5 fluxos retornados success**.
- **F1-335 / PR #415**, SHA de correção `a75b8d7c0192b7c8ac34b7f580ef2dccd2b8d57e`: após `write`+ `fsync`, reabre ZIP com descritor independente, compara todos os bytes persistidos e `fsync` da pasta pai. Teste adversarial detectou problema real: rotina de limpeza comparava tamanho originalmente 0 com tamanho já escrito e deixava arquivo parcial em falha; corrigido para verificar somente `st_dev/st_ino` estáveis, tipo regular e link único antes da exclusão. **Aguardar CI de SHA corrigido e de integração; não usar execução anterior com falha como evidência de sucesso.**
- **Escopo real ainda bloqueado:** pins CLI não são autenticação do Portal, criptografia ou autorização de armazenamento de clientes. Somente `index.html` e `release-manifest.json` fictícios, sem banco PostgreSQL, outbox, dados pessoais, domínio real ou Cloudflare; `operationalRestore=false`, `publishable=false`, `scopeAuthenticated=false`. O futuro backup de multiempresas depende de aprovação Portal/CRM, IAM, banco isolado, retenção LGPD e revisão AppSec.
- **Candidato integrado:** histórico F1-302–335 sobre branch isolada em base `dev`. Exigir CI integral do HEAD, F1-240 diff e F1-241 pacotes de revisão, proteções administrativas reais #281, revisões independentes e homologações OP01–OP05 #33–#38. `config/operational-readiness.json` permanece `BLOCKED` e dez gates `false`. Sem merge, deploy, Cloudflare/VPS, credenciais, cliente ou gasto.

---

## F1-331 — promoção atômica de restauração sintética em Linux — 13/11/2026

- **F1-331 / PR #409**, SHA `81296f270f0e041c1290ff59a3be78210da40d4b`: atualização do laboratório de restore F1-330. Antes, os dois arquivos ZIP eram gravados no novo diretório final; agora são gravados e confirmados em diretório staging privado nomeado com entropia local e modo 0700, com hashes e fsync. Somente após readback do staging faz-se `renameat2(RENAME_NOREPLACE)`, exigindo kernel Linux e sem fallback a sobrescrita, fsync do pai e novo readback final. Uma falha antes da promoção deixa apenas staging oculto e isolado; nunca expõe a pasta final parcial. Testes negativos de falha de escrita, corrida para criar o destino final, tentativa de repetir restore, traversal, formato e permissões. Testes F1-24 na matriz PHP 8.3/8.4 e regressões anteriores F1-329/330 devem estar `success` no SHA individual e no integrado.
- **F1-329/330 / PRs #406–#407** e integração anterior **#408**: 100/100 verificações retornadas na primeira página `success` no SHA `c754ab13a2c9dbb7bad32f43fe0c3d6aa5b1f1ff` de #408, incluindo revisão F1-240/F1-241. Não há prova de execuções fora da paginação disponível.
- **NÃO é autorização de produção:** atomicidade se limita à árvore de arquivos fictícios na mesma partição Linux. Pins SHA são alegados e não autenticados por Portal; nenhuma autenticação cliente, backup real de banco PostgreSQL/RLS, restore distribuído, outbox, chave, criptografia, publicação CDN ou fornecimento a terceiros. A função ZIP somente trabalha com `index.html` e `release-manifest.json` sintéticos e marca `operationalRestore=false` e `publishable=false`. Toda mudança administrativa via GitHub, UI real e go-live continuam bloqueados por #281 e OP01–OP05 (#33–#38).
- **Candidato integrado:** branch de integração protegida apenas por política de trabalho, não por ruleset aplicado. Novo PR draft base `dev`; rodar novamente CI de corpo completo no SHA exato, inventário F1-240 e seis pacotes F1-241. `dev`/`main` inalteradas; readiness `BLOCKED` com dez gates `false`. Sem merge/deploy, domínio/Cloudflare/VPS, CRM, PII, cliente, credencial ou custos.

---

## F1-326–328 — preparação verificável de proteção dev/main — 13/11/2026

- **F1-326 / PR #402**, SHA `6e43e34f3dfd08799e4934db58e233d69b16ebb1`: plano offline reproduzível de GitHub ruleset para `dev/main` com PR obrigatório, uma revisão independente, descarte de revisões antigas, aprovação do último push, força de checks de PHP 8.3/8.4 e bloqueio de deploy, proteção contra force-push/delete, sem bypass configurado. Usa jobs estáveis realmente executados em todas as PRs: `prevent-accidental-production`, `pure-domain (8.3)` e `pure-domain (8.4)`. Script de GitHub CLI só **pode** aplicar com aprovação de administrador, revisor real e três flags explícitas. **NÃO aplicado por esta entrega.** CI offline do plano e documentação Windows/PowerShell. **5/5 workflows success** no SHA individual.
- **F1-327 / PR #403**, SHA `ad864195dfdad003738f29daf2b86e4e5f8c4643`: auditoria read-only passa a validar parâmetros de aprovação (incluindo último push/revisões obsoletas) e os três checks obrigatórios, não apenas nomes/tipos de regra; self-test adversarial. **4/4 workflows success**.
- **F1-328 / PR #404**, SHA `933b3e11d87cb12d18fdbfd15d7947b4e6b89453`: auditoria do ruleset específico também exige prova *visível* de lista de exceções `bypass_actors=[]`; campo omitido por falta de permissão administrativa falha fechado. Não verifica disponibilidade de colaboradores de revisão, inherited rulesets ou recusa real de push; teste administrativo permanece P0. Resultado de CI do SHA F1-328 e do candidato integrado deve ser confirmado.
- **Runbook pronto:** `docs/SITES-F1-326-ADMIN-BRANCH-RULESET-PLAN.md`; script `ci/f326_branch_ruleset_plan.py`. O modo padrão é renderização e self-test SEM rede, credenciais, despesas nem alterações de GitHub. `audit` usa API de leitura apenas no computador de administrador; `apply` separado exige ação explícita e credenciais adequadas do GitHub CLI, **não deve ser chamado a partir de CI**.
- **Candidato integrado:** a política de domínio apex/SEO/redirect (F1-322–325) e a proposta de governança (F1-326–328) foram consolidadas sobre o histórico anterior. Revisões de AppSec/DBA/Portal/CRM e configuração administrativa real da issue #281 CONTINUAM NÃO SATISFEITAS; não cadastrar CODEOWNERS sem pessoas/equipes reais confirmadas. Exigir inventário F1-240, pacotes F1-241 e CI do SHA exato antes da promoção.
- **Operação:** `config/operational-readiness.json` permanece `BLOCKED` com os dez gates `false`. OP01–OP05 (#33–#38) e homologação Leaseweb/Cloudflare/CRM/Portal em aberto. Nenhum merge, deploy, publicação, usuário, token, DNS, integração real ou custo realizado.

---

## F1-322–325 — domínio principal sem www e consistência SEO/redirecionamento — 13/11/2026

- **F1-322 / PR #397** (`7c2a339f1d80ede646aada9e6e421b68defd1621`): a política offline de domínio base rejeita prefixos reservados dos apps Imagine (incluindo `agents`, `performance`, `universidade`) e sufixos públicos óbvios (`com.br`, `co.uk` etc.). O controle é conservador e NÃO substitui uma Public Suffix List atualizada ou prova de titularidade. **5/5 fluxos retornados success.**
- **F1-323 / PR #398** (`312862e8e77b420445856490750d180cbbad06ab`): sitemap canônico F1-289 exige origem independente fixada pela configuração do projeto; recusa proposta que tente falsificar conjuntamente `canonicalOrigin` e alias `www`. `mayServe=false` e `indexable=false`. **5/5 fluxos retornados success.**
- **F1-324 / PR #399** (`7aa3f333fb4472c948e06f742aabfc9422592e64`): simulador de redirecionamento HTTPS `www`→apex F1-288 exige destino independente fixado pelo projeto. Recusa destino forjado, inclusive se o alias for alterado coerentemente; `mayExecute=false`. **5/5 fluxos retornados success.**
- **F1-325 / PR #400** (`49575a384f280f9eecea5b72c6bdc87c11f3fd04`): teste de integração offline cruza política F1-287, sitemap F1-289 e redirecionamento F1-288; cobre domínios base brasileiros/internacionais, remoção de queries, fontes/domínios forjados, campanhas isoladas e `www` legado mantido sem migração. CI matricial PHP 8.3/8.4 nas duas políticas. Verificação completa de workflows do SHA e do candidato integrado ainda necessária.
- **Contrato alterado:** as funções `SyntheticSeoCanonicalMapV1::draft` e `SyntheticCanonicalRedirectReviewV1::inspect` exigem agora uma origem canônica/destino fixados em configuração **independente**. Chamadores reais futuros NÃO podem preenchê-los com `Host`, URL de requisição, entrada de IA ou com o mesmo array não confiável que desejam inspecionar. Não há DNS, ownership, redirect público, sitemap servido ou aprovação de cliente implícitos.
- **Próxima integração:** candidato draft em base `dev`, com F1-302–325 acumulados. Exigir CI integral no SHA, inventário diff F1-240, seis pacotes F1-241 e revisão AppSec/UX/Portal/CRM independente. `config/operational-readiness.json`: dez gates `false`, estado `BLOCKED`; proteção `dev/main` #281 e OP01–OP05 #33–#38 pendentes. Sem merge, deploy, DNS, Cloudflare/VPS, formulários públicos, dados de clientes ou custos.

---

## F1-321 — origem temporal do backoff de CRM vinculada ao T0 — 13/11/2026

- **F1-321 / PR #395**, head `f5acef3f3bb0d5c98ce7ae55a20012bb3c57806b`: o plano sintético F1-277 registra o `originalT0` UTC; a janela F1-279 nega reaproveitar esse plano com um T0 de outro registro, mesmo se timestamps subsequentes parecerem válidos. Teste negativo de troca do T0. No preflight F1-319, dois planos coerentes com T0 diferentes podem chegar ao mesmo horário previsto: o comparador F1-320 ainda precisa negar a mistura. `mayDispatchToCrm=false` inalterado.
- **Integração em revisão:** novo candidato inclui F1-317–321 e alterações anteriores F1-302–316. Executar e conferir novamente inventário F1-240, seis pacotes F1-241, testes CRM/PHP/Laravel e segurança no SHA exato. Resultados de PRs anteriores não substituem resultado do HEAD integrado.
- **Não operacional:** integração com Portal, autorização S2S, banco/restore anti-revogação, CRM físico primário/contingência, retenção PII/LGPD, homologação e produção continuam não implementadas ou não homologadas. Não há aceitação real, deduplicação distribuída ou confirmação externa de lead. Branch protection/revisão #281 e OP01–OP05 (#33–#38) em aberto, `config/operational-readiness.json` com `BLOCKED`/dez gates `false`. Sem merge, deploy, cliente, PII, segredo, DNS, Cloudflare/VPS ou despesas.

---

## F1-313–316 — painel interno Imagine: QA completo, navegação responsiva e provas Chromium — 13/11/2026

- **F1-313 / PR #385:** revisões administrativas Laravel mostram todas as dez verificações estruturais (antes exibiam sete), incluindo seções rotuladas, nomes dos links e FAQ semântica; cada item tem status textual legível e região QA com título acessível. Atualizados os contratos editoriais Laravel e o teste Chrome F1-43 que ainda esperavam sete itens.
- **F1-314 / PR #386:** design system original Portal Node preservado (logo oficial, laranja #F9941D, grafite #171B19); links de menus têm altura mínima 44px, foco explícito, quebra responsiva para mobile e sem overflow nos cartões e preview. **11/11 workflow runs retornados com success** no SHA `9186fa1c81d6c13d5be1d88eda0ce8ce3f329fe8`.
- **F1-315 / PR #387:** Chrome offline valida todos os targets `nav.tabs a` >=44px, 10/10 estados QA, região rotulada e preview isolado em 1365/820/390/320 CSS pixels, sem URLs externas; mantém screenshots fictícios para revisão. **4/4 workflow runs retornados com success** no SHA `6f262f9a1b500a557bc9647fa9bd6ca891bafe88`.
- **F1-316 / PR #388:** inspeciona reorganização responsiva adicional a 683 CSS px (largura equivalente a 1366/2). Não confundir com prova completa de zoom óptico real nem com certificação WCAG. Validar resultado final de CI no SHA `3789e4b129c2d52e5995de7004bd932f0a35b393`.
- **Integração UX atual:** sobre candidato de segurança #384, preserva todos os releases, hashes, políticas e escopos. Antes de promover a `dev`, revalidar F1-240, F1-241 e toda a suíte do novo HEAD; coletar revisão de UX, acessibilidade e AppSec independente. Issue de identidade #351 permanece aberta até telas reais, evidências por página, zoom 200% humano, mobile e aprovações.
- **Fronteiras mantidas:** somente `APP_ENV=testing`/laboratórios CLI com dados fictícios; nenhum botão operacional, aprovação de cliente, captura de leads, DNS, Cloudflare, VPS ou despesas. `config/operational-readiness.json` 10/10 `false`; governança de branches #281 e OP01–OP05 #33–#38 continuam P0.

---

## F1-308–312 — correções de segurança do verificador e writer privados — 13/11/2026

- **F1-308/309, PR #380**, SHA `6c282204713aad8fbdc4df4da9e75273434773cb`: verificador Python fixa diretório, usa `O_NOFOLLOW|O_NONBLOCK` para arquivos e lê JSON de evidência com limite de 4096 bytes antes de parse; testes sintéticos FIFO/symlink/hardlink/substituição, pins falsos e permissão. **5/5 workflows retornados com success**.
- **F1-310, PR #381**, SHA `f069e8b3d905692fb3d1de4c665b6ec9c932805c`: verificação independente de HTML inerte e metadados essenciais antes de aceitar um relatório QA todo verdadeiro, recusando script ou indexação com digests recomputados. **4/4 workflows retornados com success**. Não é auditoria WCAG/AppSec.
- **F1-311, PR #382**, SHA `927ddadd2c3e1f4eef835281252eccad2b102b89`: remove `chmod(path)` da trava privada pós-flock, cria inode ausente exclusivamente com umask 0077 e nega lock preexistente 0644. **5/5 workflows retornados com success**.
- **F1-312, PR #383**, SHA `731f7a05cce0f7967aeabcccbec6d92610173f5a`: staging do pacote privado usa `fopen('xb')`, umask 0077, write loop, flush e readback existente, sem `file_put_contents` truncador nem `chmod(path)`. Novo teste Laravel verifica arquivos 0600 sob umask permissivo. **CI do próprio SHA deve ser validado integralmente**, assim como o HEAD integrado.
- **Novo candidato de integração:** contém a cadeia F1-302–312 e deve rodar F1-240 (inventário integral), F1-241 (seis pacotes de revisão) e regressão completa antes de qualquer avaliação de promoção à dev. Ainda não há revisão AppSec/DBA independente atestada.
- **Não homologado:** PHP filesystem local, lock cooperativo e testes sintéticos não protegem contra processo local hostil com a mesma identidade, nem substituem IAM, digest autenticado ou observação de CDN. `config/operational-readiness.json` mantém `BLOCKED` e os dez gates falsos. GitHub #281 e OP01–OP05 #33–#38 pendentes. `dev`/`main` intocadas; sem merge, deploy, domínio, Cloudflare, VPS, CRM/Portal real, segredos, dados de clientes ou custos.

---

## F1-306–307 — QA estrutural em evidência privada, dupla verificação offline — 13/11/2026

- **F1-306 / PR #377**, SHA `10886f46d21140afc5d00e452427e5bbb2aba933`: `--include-qa` com `--evidence-json` adiciona dez critérios estruturais PHP/Laravel à evidência dos bytes privados previamente verificados; retorna falha se o QA falhar ou se o modo JSON não for solicitado. O relatório permanece sem HTML, caminhos ou dados pessoais, `trustedPins=false`, `officialClientAcceptance=false`, `releaseAuthorized=false`, `humanReviewRequired=true`, `wcagCertified=false`. **17/17 workflows retornados success** no próprio SHA.
- **F1-307 / PR #378**, SHA `2416524da92165ae5d92cc631f1e899653e4519f`: verificador Python independente para schema fechado, booleans, digests e bytes locais; pipeline F1-24 PHP 8.3/8.4 executa Artisan→JSON→Python para ambos os produtos, inclusive falso pin. **5/5 workflows retornados success** no SHA de origem.
- **Integração atual:** incorporar F1-306/307 ao candidato anterior #376 (F1-302–305), permanecendo em branch de integração e PR *draft*. Inventário F1-240, seis pacotes F1-241 e toda a suíte no SHA integrado precisam ser reexecutados e revisados. Resultado positivo de PR isolado não prova integração homologada.
- **NO-GO:** `dev`/`main` não promovidas; `config/operational-readiness.json` continua `BLOCKED` com dez gates `false`. Branch protection e revisão independente #281, integração/autenticação/infra e go-live OP01–OP05 (#33–#38) em aberto. Sem Portal/CRM real, Cloudflare, VPS, clientes, segredos, DNS, despesas ou deploy.

---

## F1-305 — relatório JSON de integridade privada e nova integração — 13/11/2026

- **F1-305 / PR #375**, SHA `6704dae9258c07d949beec0e2d63d1518face429`: opção CLI `sites:verify-fixture-bundle ... --evidence-json`, liberada apenas em testing + flags; emite digests/tamanhos para dois arquivos fictícios verificados, sem conteúdo nem caminhos. Inclui `trustedPins=false`, `officialClientAcceptance=false`, `releaseAuthorized=false`. **17/17 workflow runs encontrados no SHA concluídos com success**.
- **Candidato anterior #374**, SHA `bac06dc931ddcd85e95944a310515c6e9fd2cf10`: **100/100 runs retornados pela primeira página do conector concluídos success**, sem afirmar cobertura de outras páginas. Novos candidatos exigem revalidar F1-240, F1-241 e CI no próprio HEAD.
- **Novo candidato integrado:** inclui F1-302–305 sem merge para `dev/main`, sem deploy ou autorização de publicação. Precisa de revisão independente AppSec/DBA/Portal e proteção administrativa de branches #281.
- **NO-GO mantém:** dez gates `false` em `config/operational-readiness.json`; OP01–OP05 #33–#38 em aberto, sem cliente/PII, segredos, Cloudflare, VPS ou cobrança.

---

## F1-302–304 — endurecimento do pacote privado e candidato integrado — 13/11/2026

- **F1-302 / PR #371**, SHA `caec5eb156fd4ecb339c8353bfa5e00ab837f1ec`: proteção contra caminhos NUL, testes de arquivos não regulares e dados binários nos limites do leitor privado; **5/5 workflows success** nesse SHA.
- **F1-303 / PR #372**, SHA `e08211c10522ff83a300275f03b18c8df0098474`: lock revalida inode/size/link após `flock`, exige 0600, recusa conteúdo no lock, hardlink, path NUL. **5/5 workflows success** no próprio SHA.
- **F1-304 / PR #373**, SHA `7d1cf9fdc2076a6d919d275a2647dfa125ec46d9`: limites in-memory de HTML (150000) e manifesto (8192) alinhados ao readback F1-301; testes negativos F1-75. Validar a totalidade do CI no SHA exato antes da revisão.
- **Integração de revisão F1-304:** sobre #370, incorpora F1-302–304 e exige novo inventário de diff integral F1-240, seis pacotes F1-241, CI no SHA atual e revisão qualificada. PRs draft empilhados permanecem sem merge.
- **NO-GO:** `dev` e `main` sem alteração; gates `config/operational-readiness.json` 10/10 `false`; proteção de branches #281, OP01–OP05 #33–#38 e homologação real abertas. Nenhum deploy, aprovação do cliente, CDN, Portal/CRM operacional, PII, segredo ou custo.

---

## Continuação F1-300–301 — lock concorrente e readback fixado por inode — 13/11/2026

- **F1-300 / PR #368**, SHA `99af0e38da977899f095efcde29da611ed2e8d55`: bloqueio `flock` compartilhado entre escritor e verificador de Onepage/Landing, separado por produto, não bloqueante, sem sobrescrita; **21/21 workflows success** no SHA próprio.
- **F1-301 / PR #369**: validação de descritor regular (inode/dev/nlink/tamanho antes/depois), tamanho limitado, negação de symlink/hardlink e limpeza de stat cache. As duas operações privadas passaram a utilizar o leitor. Reexecutar CI no SHA mais recente do PR antes da revisão.
- **Novo candidato de integração** a `dev`: demanda inventário completo F1-240 e seis pacotes F1-241, reexecutando regressão no SHA exato; aprovações independentes ainda não existem. `dev/main` sem merge/deploy e sem branch protection configurada.
- `config/operational-readiness.json` permanece `BLOCKED` com dez gates `false`. Issues #33–#38, #281, #276 e #263 não dispensadas pelos testes. AppSec/DBA e homologação Portal/CRM/Cloudflare continuam obrigatórias.

---

## Continuação F1-298–299 — verificador privado e entrada v3 bruta Laravel — 13/11/2026

- **F1-298 / PR #365**, SHA `f72d1d434b43f9bc9b68cadb303c1a07df7eb68a`: CLI read-only `sites:verify-fixture-bundle` compara HTML/manifesto em disco com dois hashes informados pelo chamador, validando escopo e inventário exato. **19/19 workflows success** no SHA desta PR. Verificador de laboratório, sem fonte autenticada do pin e sem publicação.
- **F1-299 / PR #366**, derivado de #365: Laravel in-process somente testing/CLI consome envelope HMAC v3 de bytes JSON originais, negando duplicatas, escapes, MAC inválida, epochs, replay, downgrade e cross-tenant. Middleware `SyntheticLabRouteFence` agora cobre v1 e v3 mesmo com cache. Ampliadas verificações negativas F1-234 (PHP cli-server real) e F1-237 (Nginx/PHP-FPM interno isolado). Revalidar CI final após as últimas mudanças; nenhuma rota operacional/externa foi ativada.
- `dev` e `main` seguem sem merge. Issues #33 (Portal autenticação real), #34 (PostgreSQL e revogação), #35 (publicação), #36 (CRM), #37 (Agentes), #38 (homologação) e #281 (proteções/revisão) aguardam evidências externas. Dez flags `false` em `config/operational-readiness.json`. Não declarar go-live a partir de CI sintético.

---

## Continuação F1-295–297 — segurança Portal v3 e pacotes privados — 12/11/2026

- **F1-295 / PR #362**, commit `98f9fe51bc05ece3b922ee554e39f0175901fabd`: diagnóstico fail-closed distingue stubs e indicadores sintáticos do emissor Portal v3. Testes do commit: **5/5 workflows success**; a inspeção do código não demonstra autorização, transporte ou identidade S2S.
- **F1-296 / PR #363**, commit `6e4b56a95a7e0d3731b1b5e38bf900de10d50ac2`: verificação do SHA-256 do pacote inteiro contra digest externo fixado e readback dos arquivos privados do CLI antes de confirmar sucesso. Após corrigir a expectativa de um teste Laravel, **19/19 workflows success** no próprio commit. Não há origem autenticada para o digest no laboratório.
- **F1-297 / PR #364**, commit `4a5079c739cf27a6aa559d86c802fc2c733d816b`: staging temporário e renomeação única de diretório privado após verificação integral, sem sobrescrever pacote existente. **18/18 workflows success** no próprio commit; um novo commit documental exige checks do seu SHA.
- PRs **empilhados em draft**, sem merge em `dev`/`main` e sem deploy. O resultado dos testes não é certificação nem revisão independente de código.
- Verificação REST desta rodada: `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3` e `main` SHA `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`, ambos `protected:false` (issue #281). A conexão não consegue aplicar a proteção administrativa.
- **OP01–OP05 #33–#38 não homologados**: IAM Portal v3 atual, PostgreSQL e revogação externos, Cloudflare com observador independente, CRM redundante/T0 e ações AI Agents/aceite do cliente. `config/operational-readiness.json` permanece `BLOCKED`, `non_operational=true`, dez gates `false`.
- Próximos passos reais: proteção de branches por administrador GitHub, aprovação independente por competência, integração HTTPS com identidades **de homologação** no Portal/Sites, infra técnica e evidências de qualidade, segurança, privacidade e rollback. Não inferir go-live a partir de CI.

---

## Continuação F1-294 — diagnosticar emissor v3 Portal atual, fail-closed — 12/11/2026

- Auditoria de dois SHAs reais e públicos do Portal: `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8` (fast-track vigente consultado) e `8d1e20bc5c39dc4106b549c5e979351b5c766615` (emissor v3 histórico). A versão vigente não contém o caminho esperado do emissor histórico; **paridade no commit antigo não comprova integração operacional corrente**.
- Novo F1-294 CLI Python read-only e GitHub Actions clona os SHAs exatos, verifica SHA/blob da implementação histórica e emite NO-GO para integração mesmo quando o código v3 aparece, até homologação humana de identidade, autorização e transporte.
- F1-293 (parent SHA `b0ac0a86ade02fd5a4d7e697df15c05ef7b10f0a`) concluiu 12/12 workflows `success`, com parse canônico do envelope v3 anterior a MAC e nonce.
- Dependências OP01 e branch protection #281 permanecem abertas, readiness BLOCKED 10/10. Nada de merge, live IAM, CRM/Portal real, DNS, VPS ou deploy.

---
## Continuação F1-293 — bytes do envelope v3 sem duplicatas e sem normalização — 12/11/2026

- A assinatura v3 anterior verificava arrays após decodificação, não os bytes brutos do futuro endpoint. Adicionados os métodos `verifyCanonicalJsonSignature` e `verifyCanonicalJson` em `SyntheticCommercialEpochSignedCommand`, recusando duplicatas JSON, BOM, whitespace, aliases Unicode, alteração de tipos e payload não canônico **antes** da validação da MAC e da reserva de nonce.
- O verificador v3 mantém HMAC/keyEpoch/commercialEpoch, o replay store por padrão DENY e não altera nenhuma rota HTTP/cliente. CI dedicada PHP 8.3/8.4 e suíte geral passam a exercitar >25 verificações positivas/negativas.
- Origem PR #358, SHA `563b8d8cd8877c4f55e681850a605928776897bc`: 122/122 workflows aprovados, 780 caminhos auditados / 220 sensíveis. **Esta entrega nova precisa passar seu próprio CI**, não herda automaticamente as provas anteriores.
- Em paralelo, foi constatado que o emissor v3 só existe no SHA histórico `8d1e20bc5c39dc4106b549c5e979351b5c766615` do Portal, não no ref `dev/fast-track-portal-2026-11-05` inspecionado. Integração real permanece bloqueada; 10 gates BLOCKED, #33 e #281 abertos.

---
## Continuação F1-292 — OpenAPI CreateProject envelope v3 sem downgrade — 12/11/2026

- Achado P0 OP01: OpenAPI v1 anterior descrevia CreateProject com assinatura v1, enquanto LabSignedProjectIntake moderno tem seleção explícita de v3 com keyEpoch + commercialEpoch. Compatibilidade histórica mantida, novo contrato v3 isolado; sem ativar API.
- Novos JSON Schema e OpenAPI v3 candidate descrevem formato unívoco sem fallback v1/v2; JSON Schema não é autenticação/autorização.
- Testes Python conferem drift PHP/OpenAPI, sem servidores e campos restritos. Testes PHP verificam HMAC v3, prazo, replay sintético, tenant, epochs e negação de downgrade; workflow dedicado PHP 8.3/8.4.
- Dependências OP01 abertas: mTLS/identidade gerenciada, nonce durável sob concorrência, fonte Portal independente, revogação monotônica após restore e homologação HTTPS. Dez gates BLOCKED, nenhuma implantação.
- Base #356 validou 121/121 workflows, 773 arquivos e 217 sensíveis; a F1-292 exige CI própria.

---
## Continuação F1-291 — capturas reais do shell Imagine em Chromium — 12/11/2026

- F1-290/PR #354: aplicação dos tokens laranja/grafite do Portal Node e logo original no painel interno, sem mudar as prévias de clientes. O CI revelou e motivou correções de contraste sobre fundo cinza e teste que agora permite apenas a imagem oficial com src local.
- F1-291: exportação privativa de HTML fictício via Laravel in-process com flag de GitHub Actions, screenshot real Chromium CDP em desktop (1365), tablet (820), mobile (390) e tela estreita (320); verificação de noindex, sandbox, logo real, origem local, responsividade e ausência de formulários/scripts.
- Capturas e relatório SHA são artefatos temporários GitHub Actions, não material publicável; CI e revisão visual humana do novo HEAD ainda necessárias.
- Dez gates BLOCKED, nenhuma promoção a dev/main, Cloudflare/DNS, VPS, CRM real, dados privados ou gastos.

---

## Continuação F1-290 — identidade visual Portal Node na interface interna — 12/11/2026

- Issue #351: identidade de painel de revisão Laravel com tokens oficiais do Portal PR #589 (laranja `#F9941D`, grafite `#171B19`, branco e cinza-claro), sem redesenho dos sites dos clientes.
- PNG original importado do Portal Node com hash de objeto Git idêntico (`678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77`); inclusão no cabeçalho interno, com CSP `img-src 'self' data:` nos 16 laboratórios que reutilizam a view.
- Testes de fonte/tokens/contraste, Laravel in-process, fronteira de `srcdoc`, SHA e rotas desabilitadas; CI PHP 8.3/8.4 dedicada. Revisão visual humana/screenshot ainda não validada.
- Origem do trabalho: PR #353 em `27bfd56b4b2ab85f5c188cea04667bc32e1f75f3` (**119/119 workflows success**, 762 arquivos / 215 sensíveis auditados). Esta nova etapa exige CI própria.
- `dev/main` sem merge, regras administrativas faltando (#281), 10 gates BLOCKED; nenhuma execução de infraestrutura ou publicação.

---

## Continuação F1-289 — mapa SEO canônico privado — 12/11/2026

- Após F1-288, implementação de mapa de URLs canônicas e sitemap XML candidato, em memória, com origem sem www para novos sites públicos.
- Respeita legado com www indexado, campanhas em subdomínios próprios, valida URLs por allowlist e recusa paths perigosos e classes de aplicativos autenticados.
- indexable=false e mayServe=false em todas as respostas; previews continuam noindex, sem DNS, Cloudflare, publicação ou envio a crawlers.
- Testes PHP 8.3/8.4 dedicados e suite sintética principal devem ser concluídos no HEAD deste trabalho.
- Auditoria anterior consolidada #349: 117/117 workflows success, 753 caminhos de diff, 213 sensíveis, 6 pacotes. Dez gates BLOCKED, dev/main sem revisão/proteção administrativa atestada.

---
## Continuação F1-288 — preflight offline de redirecionamento canônico — 12/11/2026

- PR #349 consolidou F1-287 + ADR-015; SHA 4d7a7982bed62b622d519ab316d55ff0ddce5419: **117/117 workflows success**, incluindo F1-240/F1-241. Inventário completo: **753 caminhos**, **213 sensíveis**, seis pacotes com hash. Isso não é revisão humana nem liberação de produção.
- Nova F1-288 introduz simulador de redirect 308 somente para origem www previamente listada em proposta de novo site apex, sem servir HTTP 30x.
- Recusa URLs externas, cabeçalho Host, paths suspeitos, vazamento de query, mudanças de classe e hosts legados. PHP 8.3/8.4 e suíte sintética sob novo HEAD precisam aprovação específica.
- Nenhuma aplicação real, DNS/TLS, Cloudflare, CRM, VPS, PII, custo, deploy ou merge ativado. Dez gates continuam BLOCKED; issue #281 pendente de branch protection e revisão independente.

---
## Continuação F1-287 — domínios canônicos sem www — 12/11/2026

- Decisão transversal apex-first registrada no ADR-SITES-015 / PR #347; preservar host indexado legado até migração SEO aprovada.
- Novo componente de domínio puro SyntheticCanonicalOriginPlanV1 propõe apex HTTPS para site novo, subdomínios próprios para campanha e aplicativo, e preserva canônico histórico sob HOLD.
- Validação negativa de hosts/URLs ambíguos, testes F1-287 e CI PHP 8.3/8.4; **sempre** mayPublish=false e mayRedirect=false.
- Branch de trabalho feat/sites-f1-287-canonical-apex-policy-2026-11-12 criada sobre o candidato avançado de PR #346; integração em dev depende de revisão independente e proteção de branches na issue #281.
- Dez gates operacionais seguem BLOCKED. Sem merge, Cloudflare/DNS, VPS, dados reais, credenciais, redirect ou deploy.

---

## Continuação A11Y e CRM POSTBIND T0 — 12/11/2026

- Candidato avançado F1-286 #338 preservado; PRs redundantes #339–#343 encerrados sem merge, com referências cruzadas.
- PR #344: link de teclado agora foca `main#principal` efetivamente após Enter apenas no modo opt-in, mantendo bytes antigos quando desativado. **28/28 workflows PASS**.
- PR #345: fence de prazo original T0+432h após releitura sintética do vínculo CRM. O teste dedicado PHP 8.3/8.4 aprovou; **não é autorização real de reenvio**.
- Novo candidato `integration/sites-a11y-postbinding-t0-2026-11-12` exige inventário Git F1-240, seis pacotes F1-241 e todos os testes no SHA próprio; origem 1.262 commits ahead/0 behind `dev`.
- `dev/main` intactas, ambas reportaram `protected:false` (issue #281); dez gates `BLOCKED`. Nenhum merge, deploy, serviço externo ou dado real.

---


## Continuação F1-285–F1-286 — 12/11/2026

- F1-285 / PR #336: revisor Laravel privado compara link de acessibilidade on/off em 24 prévias; workflow dedicado aprovado.
- F1-286 / PR #337: Chromium CTA com bootstrap mais robusto e retry único apenas quando CDP não inicia e diretório de PNG está vazio; testes em validação.
- PR #335 anterior NÃO estava totalmente verde: um erro F1-255 ocorreu antes da primeira captura Chrome.
- Novo candidato `integration/sites-f1-286-dev-review-candidate-2026-11-12`, origem 1.243 commits ahead de `dev`. Revalidar F1-240/241 e CI total no próprio HEAD.
- Nenhum merge/deploy. `dev/main` sem branch protection atestada (issue #281), dez gates `BLOCKED`, sem CRM real, clientes ou custo.

---


## Continuação F1-281–F1-284 — 12/11/2026

- F1-281 / #331: vínculo CRM ativo e mesma revisão verificados duas vezes depois do escopo/T0, sem autorização operacional — **9/9 workflows PASS**.
- F1-282 / #332: skip link “Pular para o conteúdo” opcional, inert e com foco visível — **31/31 workflows PASS**.
- F1-283 / #333: revisão obrigatória ao ativar/desativar o link, novo SHA e reseta aprovação — **10/10 workflows PASS**.
- F1-284 / #334: Chrome real verificou primeiro Tab e Enter ao `#principal` em duas páginas x três telas — workflow dedicado PASS.
- Candidato anterior F1-280 / #330: **110/110 CI PASS**, 721 arquivos e 204 sensíveis, seis pacotes de revisão.
- Candidato F1-284: `integration/sites-f1-284-dev-review-candidate-2026-11-12`, exige CI completo e F1-240/241 deste HEAD. Base `dev` inalterada; `dev/main` `protected:false` na API (issue #281). Dez gates `BLOCKED`, sem merge/deploy/clientes.

---


## Continuação F1-275 — 12/11/2026

- F1-275 / PR #323: gramática exata da seção FAQ (incluindo o contêiner e linhas, não apenas contagem de tags), com rehash malicioso recusado; workflow específico de PHP aprovado.
- Candidato anterior F1-274 / PR #322: **106/106 workflows PASS**, 697 arquivos e 196 caminhos de revisão independente distribuídos em seis pacotes F1-241.
- Novo candidato F1-275 em `integration/sites-f1-275-dev-review-candidate-2026-11-12` exige auditoria do próprio SHA e revisão independente; os números de #322 são históricos.
- `dev/main` intactas, issue #281 sem proteção administrativa; dez gates `BLOCKED`, sem merge/deploy/cliente/CRM/domínio/credenciais.

---


## Continuação F1-274 — 12/11/2026

- F1-270–F1-273: FAQ expansível nativa, revisão isolada, Laravel e prova de teclado Chrome; candidato #320 **105/105 CI PASS**, inventário F1-240 693 caminhos/195 sensíveis em seis lotes F1-241.
- F1-274 / PR #321: `InertPreviewAudit` bloqueia HTML de FAQ adulterada mesmo com manifesto SHA-256 novo. Testes de pacote validam a rejeição (sem cliente real).
- Candidato consolidado `integration/sites-f1-274-dev-review-candidate-2026-11-12`, exige execução do CI e inventários F1-240/241 próprios, não reutilizar contagem de #320.
- `dev/main` intactas; issue #281 de branch protection pendente; dez gates `BLOCKED`. Nenhum merge, deploy, Cloudflare, CRM operacional, cliente real ou gasto.

---


## Continuação F1-270–F1-273 — 12/11/2026

- F1-270 / PR #316: FAQ expansível nativa, modo opcional do editor e QA DOM sem scripts — **26/26 workflows PASS**.
- F1-271 / PR #317: mudança de FAQ exige novo release e volta a `drafting` — **10/10 workflows PASS**.
- F1-272 / PR #318: comparador de FAQ no Laravel, 24 prévias e pacote privado de 4 arquivos — **26/26 workflows PASS**.
- F1-273 / PR #319: Chromium headless em 2 produtos × 3 telas com Tab/Enter/Espaço e 44px mínimos — **6/6 workflows PASS**.
- Novo candidato: `integration/sites-f1-273-dev-review-candidate-2026-11-12` para `dev`; origem 1.152 commits ahead/0 behind antes da documentação. Reexecutar F1-240/F1-241 no próprio HEAD.
- `dev/main` inalteradas, sem proteção GitHub ativa (issue #281); dez gates `BLOCKED`. **Nenhum merge/deploy, cliente real, captação, DNS, Cloudflare ou custo.**

---


## Continuação F1-268 e F1-269 — 12/11/2026

- F1-268 / PR #313: assinatura Ed25519 Base64URL única e expiração estrita — **5/5 workflows PASS**.
- F1-269 / PR #314: experimento de nonce de uso único e rejeição de replay dentro de um runtime local; não é ledger durável nem autoridade — CI Node dedicado PASS.
- PR #312, candidato anterior F1-267: **100/100 workflows PASS**, 672 arquivos/189 sensíveis inventariados; pacotes humanos ainda pendentes.
- Candidato novo F1-269: `integration/sites-f1-269-dev-review-candidate-2026-11-12`, exige F1-240/F1-241 novamente no próprio HEAD e todas as 100+ matrizes.
- `dev/main` sem proteção verificável na issue #281; dez gates `BLOCKED`; nenhum merge, deploy, cliente real, DNS, Cloudflare ou servidor.

---


## Continuação F1-266 e F1-267 — 12/11/2026

- F1-266 / PR #310: Chrome QA real corrigido com bootstrap conservador e retry único quando a porta CDP não aparece; **6/6 workflows PASS** na própria PR.
- F1-267 / PR #311: workflow de segurança procura modos alternativos de publicação Wrangler, permissões de escrita e comandos de infraestrutura; CI dedicado PASS.
- Candidato de integração F1-267 em PR draft para `dev`: substituir o candidato #309 (97/98, um erro Chrome) somente após TODOS os workflows novos e F1-240/241 no HEAD próprio.
- Nenhuma mesclagem ou deploy; bloqueios de proteção de branches na issue #281; dez gates `BLOCKED`.

---


## Continuação F1-264 e F1-265 — 12/11/2026

- PR #307 / F1-264: prévia Cloudflare Worker de laboratório usando HTML SHA-256 do motor PHP real, apenas `.invalid` e `noindex`; handler público permanentemente 503 — **5/5 workflows PASS**.
- PR #308 / F1-265: assinatura Ed25519 canônica, audiência, prazo máximo 300 s, checksum e negação de publicação; signatário ainda não verificado — **6/6 workflows PASS**.
- Candidato de integração F1-265: `integration/sites-f1-265-dev-review-candidate-2026-11-12`, base `dev` `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`, origem 1.092 commits ahead e 0 behind. Inventário F1-240/241 deve ser gerado novamente neste HEAD, sem reutilizar as contagens do PR #306.
- Nenhum merge, deploy, cliente, DNS, Cloudflare, VPS, credencial, lead/consentimento real ou gasto. Dez gates `BLOCKED`, issue #281 ainda requer proteção administrativa e revisão independente.

---


## Continuação F1-259 a F1-263 — 12/11/2026

Novo candidato de revisão, sem merge: `integration/sites-f1-263-dev-review-candidate-2026-11-12`, base `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`.

- F1-259 (#301): hashes pseudonimizados de contexto da evidência CTA — 6/6 CI PASS.
- F1-260 (#302): CTA secundário com paleta da marca e contraste AA; legado byte-a-byte preservado — 22/22 CI PASS.
- F1-261 (#303): seletor Laravel para quatro paletas, 96 visualizações e rejeição de URL incompleta — 24/24 CI PASS.
- F1-262 (#304): preflight de contato sem retenção de PII, Unicode, consent intent e honeypot — 8/8 CI PASS.
- F1-263 (#305): composição de privacidade, binding e replay, sem autorização de envio. Verificar CI completo no SHA final.
- Diferença medida antes da documentação: 1.076 commits à frente de `dev`, zero atrás. Exige inventário F1-240 e seis pacotes F1-241 neste novo HEAD; não reutilizar SHA/contegem do #300.

**Bloqueadores de integração/produção inalterados:** `dev/main` sem branch protection verificável (issue #281); dez gates `BLOCKED`; sem revisão independente AppSec/DBA/Portal/CRM, integração autenticada ou ambiente real homologado. Nenhum merge/deploy.

---


## Continuação F1-247 a F1-251 — 12/11/2026

**Candidato mais recente:** `integration/sites-f1-251-dev-review-candidate-2026-11-12`, base `dev` em `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. A origem #291 estava **998 commits ahead/0 behind** de `dev`; esta branch permanece **DRAFT, sem merge**.

- [#287](https://github.com/rafhael100/imagine-sites/pull/287), F1-247: validação estrita dos campos de `SiteBrief`, com controle Unicode/bidi/URL e preservação de HTML inerte; **14/14 workflows PASS**.
- [#288](https://github.com/rafhael100/imagine-sites/pull/288), F1-248: integridade de fontes copy+renderer e revisão por SHA, seis rascunhos; **7/7 PASS**.
- [#289](https://github.com/rafhael100/imagine-sites/pull/289), F1-249: alvos de navegação e CTA com pelo menos 44 px em Chromium; **18/18 PASS**.
- [#290](https://github.com/rafhael100/imagine-sites/pull/290), F1-250: `--no-scripts --no-plugins` no Composer dos contêineres, `package:discover` e `route:cache` só como UID 10001, builds Docker reais; **8/8 PASS**.
- [#291](https://github.com/rafhael100/imagine-sites/pull/291), F1-251: testes de reflow a 200% em Chrome em 18 variações; **5/5 PASS**, depois de repetir um job com falha de inicialização CDP.
- Documento principal e registro administrativo: `docs/SITES-F1-251-DEV-INTEGRATION-CANDIDATE-2026-11-12.md`. Uma gravação acidental temporária na `main` foi restaurada imediatamente por force-with-lease para o SHA anterior `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`; arquivo recriado em branch isolada, sem merge/deploy. A ausência de branch protection continua na [issue #281](https://github.com/rafhael100/imagine-sites/issues/281).

**Pendências:** todos os dez gates operacionais `BLOCKED`; auditoria completa F1-240/241 deve rodar no novo head, revisão independente AppSec/DBA/Portal/CRM e proteção de `dev/main` precedem merge. Nenhum cliente, lead, produção Cloudflare ou VPS foi usado.

---

## Continuidade do Imagine Sites — candidato funcional F1-246 (11/11/2026)

**Novas entregas completas em PRs draft, sem merge/deploy:**
- [#277](https://github.com/rafhael100/imagine-sites/pull/277): candidato anterior de integração em `dev`, **87/87 workflows PASS** no próprio head.
- [#278](https://github.com/rafhael100/imagine-sites/pull/278): F1-240, inventário Git completo sem o limite de 300 arquivos; **6/6 PASS**. Em sua versão foram catalogados **597 caminhos, 172** sensíveis.
- [#279](https://github.com/rafhael100/imagine-sites/pull/279): F1-241, seis pacotes de revisão com hash; **6/6 PASS**, inventário do seu head com **600 caminhos/173** sensíveis.
- [#280](https://github.com/rafhael100/imagine-sites/pull/280): F1-242, scanner CI inspeciona `.yml` e `.yaml`; **4/4 PASS**.
- [#282](https://github.com/rafhael100/imagine-sites/pull/282): F1-243, seis HTMLs funcionais (Onepage/Landing × três estilos) com serviços, processo, FAQ, navegação, SEO e metadados sociais; **6/6 PASS**.
- [#283](https://github.com/rafhael100/imagine-sites/pull/283): F1-244, 18 screenshots Chromium **full-page** em 1365/390/320px, com testes de CTA e teclado; **5/5 PASS**.
- [#284](https://github.com/rafhael100/imagine-sites/pull/284): F1-245, geração pela cadeia real de copy v4 → QA → pacote SHA → revisão humana obrigatória (sem client approval); **6/6 PASS**.
- [#285](https://github.com/rafhael100/imagine-sites/pull/285): F1-246, prova de **manifesto e digests idênticos em PHP 8.3/8.4**; **5/5 PASS**.
- Atualização do candidato em `docs/SITES-F1-246-DEV-INTEGRATION-CANDIDATE-2026-11-11.md`: origem `2dc2c0cc8ba8ed9b76061ce70929db21ef668d81`, **971 commits à frente de `dev`, zero atrás** antes do novo commit de documentação. Uma nova PR agregadora será validada em separado.

**Governança ainda bloqueada:** API de branches apontou `protected:false` em `dev` e `main`; issue [#281](https://github.com/rafhael100/imagine-sites/issues/281) exige configuração de proteção e revisores reais. Não fazer merge sem análise humana dos arquivos sensíveis, validação do ambiente e aprovação de mudanças.

**Operação:** dez gates `BLOCKED`, sem produção Cloudflare, VPS, domínio, cliente, CRM/IA real, dados pessoais ou publicação. CI de material fictício não é autorização de go-live.

---

## Integração, correções de segurança e prova HTTP — 11/11/2026

- [PR #266](https://github.com/rafhael100/imagine-sites/pull/266): candidato de integração da cadeia F1-38..231 para **revisão em `dev`**, não mesclado. No head `9da3fd7ba27a878c16e671577f86b4485ae7724f`, **83 workflows CI aprovados**.
- [PR #267](https://github.com/rafhael100/imagine-sites/pull/267): F1-232, bloqueio do destino das migrations PG18 F1-93/F1-94 com enum de bancos descartáveis, host e porta efetivos, executor GitHub Actions, usuário de teste e flag explícita. **8/8 workflows CI aprovados** no head `fa39340bcec780ca6eb76a453bf0cae769966f2f`.
- [PR #268](https://github.com/rafhael100/imagine-sites/pull/268): F1-233, middleware **global por requisição** que impede acesso a `/_lab/sites/v1/*` fora de CLI/testing/loopback mesmo com rotas previamente cacheadas. Regressões antigas adaptadas para negar IP remoto (404) e conferir método HTTP inválido a partir do loopback (405). **15/15 workflows CI aprovados** no head `2268dc60a8c2539b04220d8c76126ed414f1d00c`.
- [PR #269](https://github.com/rafhael100/imagine-sites/pull/269): F1-234, prova HTTP em PHP `cli-server`, sem rede pública. O GitHub Actions **compilou rotas sintéticas em cache** e mesmo assim o servidor não-CLI retornou 404 às rotas de laboratório, enquanto `/up` retornou 200. Resultado confirmado nas matrizes PHP 8.3 e 8.4; **5/5 workflows CI aprovados** no head `c2b16953f06bfad46bb5d92118e7bd9affd19b8e`.

**Status:** todos os PRs permanecem em `draft`; `dev` e `main` não foram promovidas. A validação por CI e pelo servidor HTTP local **não comprova** isolamento, IAM, backups, publicação ou desempenho operacional da VPS/Cloudflare. `config/operational-readiness.json` continua `BLOCKED`, com 10 gates falsos. Controle de consolidação: [issue #263](https://github.com/rafhael100/imagine-sites/issues/263). Bloqueadores P0: OP01–OP05 e SITES-GATE.


## Correção do comparador SEO — 11/11/2026

Base: F1-227 / PR #259, SHA `cbc0186c85fba06103f4b1eb41f6944d92be10db`.
O workflow principal [43099356573](https://github.com/rafhael100/imagine-sites/actions/runs/43099356573)
falhou em PHP 8.3/8.4 no negativo F1-226: a substituição de `<body>` era
inócua porque o renderer emite `<body data-site-product="…">`.

- O teste passa a alterar a tag existente e exigir exatamente uma alteração,
  inclusive no negativo de robots, antes de conferir a rejeição.
- O readback SEO expõe tenant/site já verificados; o comparador exige igualdade
  desses dois identificadores antes de reportar uma revisão SEO.
- A matriz de 12 pares inclui pacotes independentes e válidos de outra empresa
  ou outro site, com HTML idêntico, que devem falhar pelo motivo de escopo exato.
- Validação da nova branch pendente de CI. PHP não está instalado neste executor.
  Os resultados anteriores não certificam esta correção.

Branch isolada e PR draft; sem merge em dev/main, deploy ou serviços reais.
Referências sintéticas não concedem identidade autenticada; gates OP permanecem abertos.


## Continuidade do motor — F1-210 a F1-214 (11/11/2026)

**Cinco entregas consecutivas concluídas na cadeia de rascunhos; suítes específicas PHP 8.3/8.4 aprovadas.** Partimos da branch F1-209, sem repetir entregas antigas, e avançamos em prévias de compartilhamento e paridade entre revisão e exportação.

| Etapa | PR draft | CI verificado | Entrega |
| --- | --- | --- | --- |
| F1-210 | [#242](https://github.com/rafhael100/imagine-sites/pull/242) | [Run 43075842454](https://github.com/rafhael100/imagine-sites/actions/runs/43075842454) | Cartão social **somente de texto** no revisor Laravel; conferência dos sete metadados OG/Twitter contra bytes SHA-256, sem URL/imagem/remoto |
| F1-211 | [#243](https://github.com/rafhael100/imagine-sites/pull/243) | [Run 43076757986](https://github.com/rafhael100/imagine-sites/actions/runs/43076757986) | Auditoria CLI de pacote privado já salvo, readback dos quatro arquivos, rejeição de adulteração, symlinks e opções inválidas |
| F1-212 | [#244](https://github.com/rafhael100/imagine-sites/pull/244) | [Run 43077772547](https://github.com/rafhael100/imagine-sites/actions/runs/43077772547) | Uma tela de revisão para tema, paleta, ordem, menu e social; seletores preservam escolhas e Landing recusa menu extra |
| F1-213 | [#245](https://github.com/rafhael100/imagine-sites/pull/245) | [Run 43078512582](https://github.com/rafhael100/imagine-sites/actions/runs/43078512582) | Teste ponta a ponta: HTML do iframe **byte a byte idêntico** a index.html exportado e verificado pelo auditor |
| F1-214 | [#246](https://github.com/rafhael100/imagine-sites/pull/246) | [Sites Engine 43079064307](https://github.com/rafhael100/imagine-sites/actions/runs/43079064307) e [Laravel 43079064327](https://github.com/rafhael100/imagine-sites/actions/runs/43079064327) | Regressões F1-209/210/211/213 obrigatórias em cada PR + main; script de guarda detecta 14 mutações inseguras |

**Política preservada:** rascunhos `draft` encadeados, sem merge em `main`/`dev` ou deploy. Toda fixture usa dados inventados, artefatos internos `noindex`, revisão pendente e `publishable=false`, `officialClientAcceptance=false`. Nenhum lead, credencial, imagem remota, chamada a rede social, CRM real, VPS, DNS ou Cloudflare foi utilizado. Os gates OP01 #33, OP02 #34, OP04 #36 e **10/10 controles de produção** continuam bloqueados; testes CI não equivalem a autenticação ou autorização operacional.

**Próximo marco para transformar em serviço:** identidade S2S verificada Portal–Sites–CRM, revisão AppSec/DBA, captação dupla com consentimento, armazenamento e confirmação oficial, retenção LGPD, homologação com infraestrutura real e critérios de aceite; não acionar esses fluxos a partir de código de laboratório.



## Continuidade F1-169 a F1-181 — conteúdo, edição e layout (11/11/2026)

**13 entregas consecutivas em PRs draft encadeados; todas as suítes de CI específicas concluídas com sucesso.** A sequência acrescentou funcionalidades reais ao motor estático de Onepage/Landing Page, mas elas continuam isoladas em laboratórios de dados fictícios, sem qualquer publicação, captação real, integração CRM operacional ou aceite de cliente.

| Entrega | PR | CI específico aprovado | Capacidade implementada |
| --- | --- | --- | --- |
| F1-169 | [#207](https://github.com/rafhael100/imagine-sites/pull/207) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43009029417) | Seção ordenada “Como funciona”, schema de cópia v4, revisão invalida hash anterior |
| F1-170 | [#208](https://github.com/rafhael100/imagine-sites/pull/208) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43009678448) | Comparação no Laravel de serviços+etapas e página completa |
| F1-171 | [#209](https://github.com/rafhael100/imagine-sites/pull/209) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43010528618) | Patch editorial limitado a cópia, com revisão e SHA-256 fixados |
| F1-172 | [#210](https://github.com/rafhael100/imagine-sites/pull/210) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43011231411) | Revisor Laravel antes/depois, hashes e campos editados |
| F1-173 | [#211](https://github.com/rafhael100/imagine-sites/pull/211) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43011672952) | Chrome real: 36 inspeções de responsividade das etapas |
| F1-174 | [#212](https://github.com/rafhael100/imagine-sites/pull/212) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43012231772) | Pacote privado pós-edição com HTML, manifesto, resumo e dossiê verificados |
| F1-175 | [#213](https://github.com/rafhael100/imagine-sites/pull/213) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43015092590) | Seis permutações das seções, digest novo e revisão reiniciada |
| F1-176 | [#214](https://github.com/rafhael100/imagine-sites/pull/214) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43015615406) | UI Laravel para seis ordens: 72 combinações de revisão |
| F1-177 | [#215](https://github.com/rafhael100/imagine-sites/pull/215) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43016026865) | Chrome real: 108 inspeções de layouts com seções reordenadas |
| F1-178 | [#216](https://github.com/rafhael100/imagine-sites/pull/216) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43016687698) | Links internos opt-in de Onepage, sem URLs externas ou controle pela IA |
| F1-179 | [#217](https://github.com/rafhael100/imagine-sites/pull/217) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43017288882) | Chrome real: 54 inspeções do menu em 320/390/1365px |
| F1-180 | [#218](https://github.com/rafhael100/imagine-sites/pull/218) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43017860728) | UI Laravel para comparar menu ligado/desligado |
| F1-181 | [#219](https://github.com/rafhael100/imagine-sites/pull/219) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/43018600418) | Revisão combinada de ordem de seções e estado do menu: 108 visualizações |

### Limites e próximos gates

- **Estas entregas NÃO são deploy, publicação nem editor de cliente.** As flags de laboratório e rótulos de papéis sintéticos não equivalem a identidade/autorização. Artefatos continuam noindex, não publicáveis, com aceite oficial do Portal pendente.
- As PRs estão em *draft*, encadeadas entre si; **nenhuma foi incorporada a dev/main**, e não houve operação na VPS, Cloudflare, DNS, CRM real ou dados pessoais.
- [OP01 #33](https://github.com/rafhael100/imagine-sites/issues/33): autenticação real e identidade/autorização confiável do Portal; [OP02 #34](https://github.com/rafhael100/imagine-sites/issues/34): revisão independente de SQL, RLS, IAM e segurança; [OP04 #36](https://github.com/rafhael100/imagine-sites/issues/36): dois ingressos de lead independentes, consentimento, confirmação durável no CRM, retenção LGPD, observabilidade e homologação. **10/10 gates de produção permanecem BLOCKED.**
- Próximos trabalhos dentro do escopo autorizado: testes sintéticos de regressão/a11y, consolidação de branches sob revisão controlada e documentação de critérios de aceite; qualquer serviço real exige gate de segurança/infraestrutura próprio antes da implementação operacional.


## Continuidade sem pausa — F1-148 a F1-156 (11/11/2026)

**Nove entregas encadeadas, revisadas no GitHub Actions.** Elas continuam em PRs `draft` com base na cadeia anterior F1-142..147, e preservam os controles de AGENTS.md. Os testes de banco usam PostgreSQL 18 **descartável**, contas fictícias, PHP 8.3/8.4, sem dados pessoais, endpoints públicos ou produção.

| Etapa | PR | CI específico aprovado | Alteração |
| --- | --- | --- | --- |
| F1-148 | [#185](https://github.com/rafhael100/imagine-sites/pull/185) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42950292386) | Lease limitado ao T0+432h, sem cinco minutos extras antes do vencimento |
| F1-149 | [#186](https://github.com/rafhael100/imagine-sites/pull/186) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42950729275) | Lease exige binding/site/formulário/destino/revisão correntes e bloqueio transacional |
| F1-150 | [#187](https://github.com/rafhael100/imagine-sites/pull/187) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42951250666) | Código PHP real do CRM F3-46/47/48, JSON estrito e HMAC de metadados sem falsa permissão |
| F1-151 | [#188](https://github.com/rafhael100/imagine-sites/pull/188) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42951676941) | Código PHP real do Portal F133 confrontado com CreateProject v1 do Sites |
| F1-152 | [#189](https://github.com/rafhael100/imagine-sites/pull/189) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42951987344) | Decoder canônico de CreateProject no Sites: bytes, ordem e chaves duplicadas |
| F1-153 | [#190](https://github.com/rafhael100/imagine-sites/pull/190) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42952367289) | Paridade real com CRM para mínimo de formulário, retorno apenas de booleanos, sem PII |
| F1-154 | [#191](https://github.com/rafhael100/imagine-sites/pull/191) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42952818678) | Retira outbox técnico expirado por T0 original preservando tombstone de idempotência |
| F1-155 | [#192](https://github.com/rafhael100/imagine-sites/pull/192) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42953219100) | Une prazo, lease e descarte no **mesmo T0 da primeira persistência**, não no timestamp de outbox |
| F1-156 | [#193](https://github.com/rafhael100/imagine-sites/pull/193) | [GitHub Actions](https://github.com/rafhael100/imagine-sites/actions/runs/42953802190) | História única de UUID de lease, inclusive após substituição e descarte de outbox, contra replay |

**Diferença entre evidência de CI e produção:** toda execução acima é sintética; não existe autenticação S2S operacional Portal–Sites–CRM, confirmação de lead com resposta oficial, consentimento/retirada de dados, dois ingressos físicos, retenção LGPD de backups, política definitiva de tombstone, parecer AppSec/DBA independente nem homologação real na infraestrutura. Estas dependências estão em [OP01 #33](https://github.com/rafhael100/imagine-sites/issues/33), [OP02 #34](https://github.com/rafhael100/imagine-sites/issues/34) e [OP04 #36](https://github.com/rafhael100/imagine-sites/issues/36). **10/10 gates operacionais BLOCKED**. PRs empilhados sem merge na `dev` ou `main`; nenhum deploy, VPS, DNS, Cloudflare, PII ou custos. Nunca promover `LAB_*`, `CLAIM_RECORDED_UNVERIFIED`, `LEASED_*_UNVERIFIED` ou schema canônico a autorização de coleta, ACK, entrega CRM ou publicação.

## Continuidade encadeada — F1-142 a F1-147 (10/11/2026, America/Sao_Paulo)

**Resumo:** seis entregas implementadas em PRs draft empilhados, sem deploy ou merge em `dev/main`. Todas as matrizes específicas PostgreSQL 18 / PHP 8.3 e 8.4 passaram na HEAD dos respectivos PRs. Estes testes são de **laboratório sintético**, não evidência de sistema operacional pronto.

| Etapa | Evidência no GitHub | Avanço real do laboratório |
| --- | --- | --- |
| F1-142 | [PR #178](https://github.com/rafhael100/imagine-sites/pull/178), [CI](https://github.com/rafhael100/imagine-sites/actions/runs/42946802537) | Revoga acesso da role CI à função sem escopo e usa `SESSION_USER` associado a uma organização fictícia. Dois logins distintos não registram claim em outra organização. |
| F1-143 | [PR #179](https://github.com/rafhael100/imagine-sites/pull/179), [CI](https://github.com/rafhael100/imagine-sites/actions/runs/42947220971) | Mapeamento de login ganha revisão monotônica e revogação. Bloqueio de linha até COMMIT impede revogação concorrente durante claim; sessão já conectada perde permissão técnica após revogação. |
| F1-144 | [PR #180](https://github.com/rafhael100/imagine-sites/pull/180), [CI](https://github.com/rafhael100/imagine-sites/actions/runs/42947602914) | Adaptador PHP/PDO restrito a CLI/testing e transação. Todos os resultados do claim técnico são somente diagnósticos, nunca ACK/delivery/retry. |
| F1-145 | [PR #181](https://github.com/rafhael100/imagine-sites/pull/181), [CI](https://github.com/rafhael100/imagine-sites/actions/runs/42948198889) | Inserção de claim e outbox de metadados **na mesma transação**; sem duplicação em replay, sem gravação residual em rollback. |
| F1-146 | [PR #182](https://github.com/rafhael100/imagine-sites/pull/182), [CI](https://github.com/rafhael100/imagine-sites/actions/runs/42948629412) | Lease técnico `SKIP LOCKED` sem worker/envio. Classificação 72 horas iniciais e recuperação até o prazo original total de 432 horas, com recusa após expiração. |
| F1-147 | [PR #183](https://github.com/rafhael100/imagine-sites/pull/183), [CI](https://github.com/rafhael100/imagine-sites/actions/runs/42948981033) | Adaptador PHP/PDO da lease; códigos controlados, transação obrigatória, ausência permanente de autorização para envio, ACK ou status entregue. |

**Advertência operacional:** a ligação de login PostgreSQL a organização não substitui IAM de Portal, autorização de usuário ou autenticação S2S. A conta DB CI e todos os UUIDs/senhas são **fictícios**; NÃO usar como credenciais ou endpoints de produção. O outbox de F1-145 contém só metadados e estado `pending_unverified`: não transporta lead, não inclui consentimento, não chama CRM e não confirma entrega. A lease F1-146/147 é apenas classificação, não worker real. A simulação de passagem do tempo nos testes de lease usa somente administrador de base descartável; não é permissão para alterar T0 do cliente.

**Pendências P0 mantidas:** [OP01 #33](https://github.com/rafhael100/imagine-sites/issues/33) identidade/verificação real Portal; [OP02 #34](https://github.com/rafhael100/imagine-sites/issues/34) segurança operacional PostgreSQL/RLS; [OP04 #36](https://github.com/rafhael100/imagine-sites/issues/36) dois ingressos reais de lead, confirmação CRM, consentimento e LGPD; revisão SEG/DBA/AppSec **independente**, teste de ponta a ponta em infraestrutura autorizada e liberação comercial. **10/10 gates de produção permanecem BLOCKED**; nenhum merge em `dev/main`, VPS, deploy, DNS, Cloudflare, PII ou gastos. Continuar apenas nas etapas autocontidas enquanto as dependências de segurança/infraestrutura persistirem.

## Continuidade supervisionada — F1-133 e F1-134 (10/11/2026)

- [Sites PR #169 / F1-133](https://github.com/rafhael100/imagine-sites/pull/169): adicionou **ledger de metadados de lead exclusivo do CI** em PostgreSQL 18 (`sites_f133_lead_lab`), com PK (organização, submissão), seis UUIDs sintéticos, hora T0 do banco, role restrita com SELECT/INSERT por coluna, `ENABLE/FORCE RLS` e sem UPDATE/DELETE/TRUNCATE. Prova de concorrência física entre **duas conexões PDO**, SQLSTATE 55P03 no segundo INSERT enquanto o primeiro está pendente, replay após COMMIT sem duplicata, contexto/destino CRM original preservados, rollback, isolamento por tenant e timestamp não forjável. **Cinco workflows concluídos com sucesso**, incluindo a matriz PostgreSQL 18 PHP8.3/8.4, Laravel, testes sintéticos, RLS básico e safety.
- [Sites PR #170 / F1-134](https://github.com/rafhael100/imagine-sites/pull/170): novo `LabPostgresCrmLeadMetadataReadback` verifica uma linha existente sob transação, banco e role CI esperados, recusando contexto incorreto, ausência de registro e qualquer mudança de vínculo/site/formulário/destino. `SyntheticCrmLeadMetadataReadbackAssessment` devolve apenas códigos diagnósticos, com reserva, ACK, entrega CRM e retry automático **sempre false**, inclusive após correspondência de metadados. **PostgreSQL 18 PHP8.3/8.4, Laravel, testes sintéticos e safety aprovados** na HEAD com código; o workflow Docker ainda estava concluindo na última consulta.
- **GUC `app.tenant_id` NÃO autentica um tenant** se o cliente puder executar SQL arbitrário. A tabela é apenas fixture no banco descartável e não garante CAS operacional, dois ingressos físicos independentes, idempotência global entre datacenters, endpoint, identidade/verificação de vínculo real, recibo, ACK CRM, retenção LGPD nem consentimento. Os requisitos OP04 #36 continuam P0 e devem passar por SEG/DBA/AppSec independente.
- PRs #169 e #170 seguem **draft empilhados**, sem merge em dev/main, deploy, cliente/PII, formulário real, VPS, DNS, Cloudflare ou novas despesas. **10/10 gates de produção BLOCKED**.


## Continuidade supervisionada — F1-132 (10/11/2026)

- [Sites PR #168 — F1-132](https://github.com/rafhael100/imagine-sites/pull/168): implementou `SyntheticCrmLeadLedgerIntentV1`, que calcula fingerprint sintético de **organização + submissão** e compara vínculo, site, formulário e destino CRM original sem permitir reatribuição da mesma entrega. O teste `tests/f132-crm-ledger-evidence-cross-repo.php` executa o código-fonte real do CRM F3-07/F3-12 fixado na revisão `a2f29c8e19185e3451fe953f6fe9c9c9bcbbe3ef` (CRM draft PR #49), em PHP8.3/8.4.
- O validador `SyntheticIdempotencyCasInspector` do CRM prova que duas verificações concorrentes dão apenas `ATOMIC_STORE_REQUIRED` **sem eleger vencedor nem reservar**, e que mudança do destino original não se transforma em nova entrega. Revisão obsoleta, estados reservados, desconhecidos ou finais, operação forçada e overflow de versão continuam DENY.
- O `SyntheticDeliveryEvidenceInspector` real do CRM prova que **HTTP 200/201 ou um UUID alegado de registro não confirmam entrega**, assim como timeouts, retorno 202, vínculo divergente ou status de CRM não verificado. Não há ACK ao visitante, retry automático ou marcação de lead entregue. O fingerprint é não secreto e não substitui CAS global, vínculo autenticado ou recibo durável.
- Workflows **cross-repo CRM PHP8.3/8.4, testes sintéticos, Laravel e safety concluídos com sucesso** no SHA de código da F1-132. Docker isolado estava em execução na primeira validação. A suíte anterior F1-131 continua incluída.
- [OP04 #36](https://github.com/rafhael100/imagine-sites/issues/36) permanece integralmente ABERTA: sem dois ingressos físicos, armazenamento/T0 servidor, CAS global, confirmação CRM, identidade autenticada, LGPD operacional, homologação e revisão SEG/DBA/AppSec. **10/10 gates de produção BLOCKED**.
- PR draft empilhado sobre #167, sem merge dev/main, deploy, site ou formulário público, envio de lead, PII, VPS, DNS, Cloudflare ou despesas.


## Continuidade supervisionada — F1-130 / F1-131 (10/11/2026)

- [Sites PR #166 — F1-130](https://github.com/rafhael100/imagine-sites/pull/166): pré-teste sintético de retry de leads Sites–CRM ganhou fontes **independentes de época positiva e default DENY**. Exige época Sites e CRM estáveis antes/depois de verificar vínculo, destino CRM original, revisão, atividade e frescor, detectando A→B→A mesmo se os snapshots voltarem aos mesmos valores. Preserva T0 servidor alegado do recibo fictício e janela rígida **72h + 360h = 432h**. **4/4 workflows aprovados na HEAD de código**: PHP8.3/8.4 sintético, Laravel, safety e Docker.
- [Sites PR #167 — F1-131](https://github.com/rafhael100/imagine-sites/pull/167): novo gerador estrito de envelope **apenas de metadados** (oito campos, sem PII/T0/consentimento), validado pelo código PHP real `SyntheticLeadEnvelopeV1Inspector` do CRM Imagine no SHA fixo `a2f29c8e19185e3451fe953f6fe9c9c9bcbbe3ef` (CRM draft PR #49). O CRM retorna sempre `STRUCTURE_ONLY_UNVERIFIED` sem direito de submissão, ACK ou entrega. Negativas cobrem campos extras, destino forjado, UUID inválido e versões divergentes. **5/5 workflows aprovados na HEAD de código**, incluindo cross-repo CRM PHP8.3/8.4, Laravel, testes sintéticos, safety e Docker.
- O trabalho está restrito a fixtures e compatibilidade estrutural; **nenhuma chamada ao CRM, submissão de lead, endpoint, autenticação de origem, binding real, idempotência global, LGPD operacional ou recibo durável** foi implementada. O PR CRM #49 é proposta em draft. A issue [OP04 #36](https://github.com/rafhael100/imagine-sites/issues/36) mantém **todos** os critérios de produção pendentes, inclusive dois ingressos fisicamente independentes e SEG/DBA.
- PRs draft empilhados, sem merge dev/main, deploy, publicação, cliente/PII, VPS, DNS, Cloudflare ou despesas adicionais. **10/10 gates operacionais de produção permanecem BLOCKED.**


## Continuidade supervisionada — F1-128 / F1-129 (10/11/2026)

- [Sites PR #164 — F1-128](https://github.com/rafhael100/imagine-sites/pull/164): validadores HMAC v1/v2/v3 separados em `verifySignature` (verificação criptográfica/canônica sem storage) e `verify` (consumo definitivo do nonce). A pré-verificação comercial v3 não instancia mais replay store falso; a classe temporária `LabPreflightNoPersistReplayStore` foi desativada para retornar DENY. Com dois bancos PostgreSQL 18 efêmeros, os testes comprovam no laboratório que verificar a assinatura repetidamente não consome nonce, enquanto a admissão durável só o aceita uma vez. **Seis workflows distintos aprovados na HEAD mais recente do PR**, incluindo cross-repo PHP8.3/8.4, PostgreSQL18, Docker, Laravel, testes sintéticos e safety.
- [Sites PR #165 — F1-129](https://github.com/rafhael100/imagine-sites/pull/165): a prévia HTML inerte Onepage/Landing Page recebeu **dois novos checks de QA sem alteração do renderer**: seção `aria-labelledby` deve referenciar título h1/h2 não vazio dentro da própria seção; links e CTAs devem ter texto/label acessível. Foram adicionadas cinco mutações negativas, mantendo testes de scripts, âncoras, IDs únicos e `noindex`. **Quatro workflows distintos aprovados**: PHP 8.3/8.4 sintético, Laravel, segurança e Docker isolado. Não constitui auditoria WCAG ou aprovação humana.
- Ambos PRs permanecem **draft e empilhados**, não mesclados em dev/main. O código de assinatura e as páginas continuam em laboratório sintético; sem emissão operacional autenticada pelo Portal, IAM, vault, epoch comercial durável, HTTPS homologado, checklist real de publicação ou revisão independente AppSec. A pré-validação sem nonce não equivale a autorização de projeto.
- **10/10 gates operacionais de produção permanecem BLOCKED.** Nenhum deploy, site publicado, cliente, PII, VPS/Cloudflare, DNS ou novas despesas.


## Continuidade supervisionada — F1-128 (10/11/2026)

- [Sites PR #164 — F1-128](https://github.com/rafhael100/imagine-sites/pull/164): refatorou os verificadores sintéticos HMAC v1/v2/v3 para **separar a validação estrita de assinatura e comando** (`verifySignature`, sem nenhum ReplayStore) do passo definitivo de consumo único (`verify(..., ReplayStore)`). `LabSignedProjectIntake` usa a pré-verificação sem armazenamento temporário de nonces, consulta a revisão comercial e só então registra o nonce no `LabPostgresReplayStore` restrito. `LabPreflightNoPersistReplayStore` foi desativado, retornando sempre DENY.
- A regressão PostgreSQL 18 PHP8.3/8.4 verifica pré-validação repetível sem consumo, suporte v1/v2, alteração de HMAC v3 recusada, idempotência e replay negado entre duas conexões. **Seis workflows distintos concluídos com sucesso** na HEAD de código: PostgreSQL18, cross-repo Portal→Sites, sintéticos, Laravel, Docker isolado e safety.
- O trabalho permanece exclusivamente de **laboratório CLI/testing**, com fontes de chave/época/pedido fictícias, sem IAM oficial, OAuth/mTLS, vault, emissão operacional Portal, teste HTTPS, homologação na VPS ou parecer AppSec independente. A validação pura HMAC **não é autorização** e jamais deve substituir o consumo durável de nonce.
- PR draft empilhado sobre #163 e anteriores, sem merge em dev/main, deploy, DNS, Cloudflare, dados pessoais, servidor VPS nem custos. **10/10 gates operacionais continuam BLOCKED.**


## Continuidade supervisionada — F1-126 e F1-127 (10/11/2026)

- [Sites PR #162 — F1-126](https://github.com/rafhael100/imagine-sites/pull/162): cenário de indisponibilidade de revisão comercial em **três fases** de admissão HMAC v3: antes da reserva do nonce, após a reserva e no pré-COMMIT de um novo projeto. Com duas bases PostgreSQL 18 descartáveis, comprova rollback, recuperações distintas e uso obrigatório de novo nonce após a reserva. **Quatro workflows aprovados**, incluindo PostgreSQL18 PHP8.3/8.4, Laravel, sintéticos e safety.
- [Sites PR #163 — F1-127](https://github.com/rafhael100/imagine-sites/pull/163): corrigiu o risco de confundir o `LabPreflightNoPersistReplayStore`, que **nunca registra nonces**, com um armazenamento definitivo `ReplayStore`. Em modo v3 o intake agora exige a classe restrita `LabPostgresReplayStore` antes de consultar a autoridade. Testes recusam o preflight e um stub sempre-ALLOW, sem consulta comercial nem mutação de projeto; confirmam que o nonce permanece disponível no PostgreSQL e será consumido uma só vez. **Cinco workflows distintos aprovados na HEAD de código**, incluindo PostgreSQL18 PHP8.3/8.4, Laravel, sintéticos, safety e Docker isolado.
- Os dois PRs permanecem empilhados e em **draft**, sem merge em dev/main ou deploy. A política de tipo de storage é exclusiva do **laboratório CLI/testing PostgreSQL 18**, não uma especificação final da infraestrutura de produção; o sistema não tem Portal IAM real, fonte de autorização monotônica durável, credenciais S2S, vault, observabilidade ou parecer AppSec independente.
- **10/10 gates de produção permanecem BLOCKED**. Nenhuma alteração de VPS, Cloudflare, DNS, dados de clientes ou novos custos.


## Continuidade supervisionada — F1-123..125 e Portal F1-15..16 (10/11/2026)

- [Portal PR #270 / F1-15](https://github.com/rafhael100/imagine-portal/pull/270): fonte independente de geração de autorização comercial por pedido, padrão DENY, evita revogação/restauração A→B→A durante emissão. **Dois workflows concluídos com sucesso** (testes do Portal e Laravel/PHP 8.3/8.4).
- [Sites PR #159 / F1-123](https://github.com/rafhael100/imagine-sites/pull/159): prova cross-repo com o código Portal fixado em `24081214446da198ea551fb18ee35d6948f3da5d`; para Onepage/Landing Page, época comercial divergente nega emissão mesmo se o snapshot do pedido voltou a ser idêntico. **Quatro workflows concluídos com sucesso**, inclusive PHP8.3/8.4.
- [Portal PR #273 / F1-16](https://github.com/rafhael100/imagine-portal/pull/273): versão sintética **HMAC v3** autentica `commercialEpoch` junto de `keyEpoch`; `issueCommercialEpochSigned` obtém ambos das fontes candidatas, nunca do chamador. **Dois workflows concluídos com sucesso** no Portal.
- [Sites PR #160 / F1-124](https://github.com/rafhael100/imagine-sites/pull/160): verificador HMAC v3 e teste cross-repo real usando Portal fixado em `8d1e20bc5c39dc4106b549c5e979351b5c766615`. Compara envelopes para Onepage/Landing Page e recusa época comercial antiga, alteração não assinada e replay. **Cinco workflows distintos aprovados**, incluindo cross-repo PHP8.3/8.4, Laravel, safety, sintéticos e Docker.
- [Sites PR #161 / F1-125](https://github.com/rafhael100/imagine-sites/pull/161): modo CLI/testing **v3 explícito**, sem downgrade; fonte independente de época comercial padrão DENY. Autentica HMAC antes de consulta por recurso sem consumir nonce, reconsulta epoch antes do consumo durável e outra vez no início e pré-COMMIT da admissão PostgreSQL18. Testes em duas bases descartáveis e PHP8.3/8.4 comprovam rejeição de revisão obsoleta e HMAC alterada, rollback, nonce persistido e nova assinatura para retry. PostgreSQL18, Laravel, safety e sintéticos passaram na HEAD de código; Docker ainda era executado na última consulta.
- Os PRs permanecem **draft e empilhados**, sem merge em dev/main, publicação, cliente/PII, DNS, VPS/Cloudflare ou novas despesas. O Portal não possui emissor/autorizador operacional v3; épocas/chaves usadas nos testes são fixtures em memória, não prova de IAM, vault, monotonicidade durável ou identidade mutuamente autenticada. Falta homologação HTTPS real, revisão independente AppSec, auditoria, contrato aprovado e acesso autorizado à infraestrutura. **10/10 gates operacionais de produção permanecem BLOCKED.**


## Continuidade supervisionada — Portal F1-15/16 e Sites F1-123/124 (10/11/2026)

- [Portal PR #270 — F1-15](https://github.com/rafhael100/imagine-portal/pull/270): fonte comercial independente de época positiva, padrão DENY, evita revogação/restauração A→B→A do mesmo pedido durante emissão. PHP8.3/8.4, Laravel e testes gerais do Portal aprovados em dois workflows.
- [Sites PR #159 — F1-123](https://github.com/rafhael100/imagine-sites/pull/159): o workflow cross-repo usa Portal fixado no commit `24081214446da198ea551fb18ee35d6948f3da5d`, provando em Onepage/Landing Page que a mudança da época comercial nega emissão mesmo com snapshot idêntico. Quatro workflows distintos aprovados, incluindo cross-repo PHP8.3/8.4.
- [Portal PR #273 — F1-16](https://github.com/rafhael100/imagine-portal/pull/273): envelope sintético HMAC **v3** com campo `commercialEpoch` autenticado, além do `keyEpoch`; nova emissão protegida `issueCommercialEpochSigned` sem receber épocas nem segredo do chamador. Testes gerais e Laravel aprovados.
- [Sites PR #160 — F1-124](https://github.com/rafhael100/imagine-sites/pull/160): verificador sintético HMAC v3 exige época comercial corrente, autentica campo contra MAC e preserva nonce/contrato da v2. Cross-repo PHP8.3/8.4, Laravel, testes sintéticos e safety aprovados na HEAD com código; Docker isolado ainda estava em execução na última consulta. O verificador **não** usa fonte IAM real: as épocas esperadas vêm das fixtures.
- PRs **draft e empilhados**, sem merge em dev/main, publicação, cliente, VPS, DNS, Cloudflare ou custo. Emissor e verificador v3 continuam apenas no laboratório; integração ao admission store PostgreSQL, fonte confiável e durável de autorização comercial, autenticação S2S, vault, revogação, auditoria, homologação HTTPS e parecer independente AppSec ainda não existem. **10/10 gates operacionais BLOCKED.**


## Continuidade supervisionada — Portal F1-14 / Sites F1-122 (10/11/2026)

- [Portal PR #267 — F1-14](https://github.com/rafhael100/imagine-portal/pull/267): `LabPortalSitesVersionedIssuer` agora consulta o contrato/pedido comercial **duas vezes**, incluindo a última consulta antes de devolver HMAC v2. Mudança de elegibilidade, revisão ou empresa entre as consultas resulta DENY; testes do Portal e Laravel PHP8.3/8.4 passaram nos dois workflows.
- [Sites PR #158 — F1-122](https://github.com/rafhael100/imagine-sites/pull/158): CI cross-repo lê a revisão Portal fixa `2be656bdbf5a6d6fa00e563fd5b5e4942b54cd2c`, prova para Onepage e Landing Page que **revogação, nova revisão contratual ou mudança de empresa durante emissão** não devolvem envelope. Após restabelecimento fictício, o Portal pode emitir nova assinatura verificada pelo Sites. Quatro workflows concluídos com sucesso na HEAD de código, incluindo cross-repo PHP8.3/8.4, Laravel, testes sintéticos e safety.
- As fontes comerciais, de chave e época continuam em memória e não são IAM/vault oficiais; nem o emissor Portal F1-14 nem o receptor Sites F1-122 são endpoints operacionais. A segunda leitura não é transação atômica e não impede revogação posterior. Requer autenticação mútua, fonte contratual real, auditoria, homologação HTTPS e revisão AppSec independente.
- **10/10 gates operacionais continuam BLOCKED.** Todos os PRs são drafts empilhados; nenhum merge em dev/main, deploy, cliente, PII, DNS, VPS/Cloudflare ou nova despesa.


## Continuidade supervisionada — Portal F1-13 e Sites F1-121 (10/11/2026)

- [Portal PR #264 — F1-13](https://github.com/rafhael100/imagine-portal/pull/264): `LabPortalSitesVersionedIssuer` constrói comando com fonte comercial Portal em DENY por padrão, obtém **chave e época em um mesmo registro** de `PortalSitesSigningMaterialSource` independente, e reconsulta a fonte após assinar para negar troca do segredo ou da revisão durante a emissão. O método de entrada NÃO recebe segredo ou `keyEpoch` do solicitante. Testes Laravel PHP8.3/8.4 e Node.js aprovados nos dois workflows observados. A fonte continua fixture sintética sem IAM real.
- [Sites PR #157 — F1-121](https://github.com/rafhael100/imagine-sites/pull/157): a suíte cross-repo usa checkout Portal fixado na revisão pública `b2bfd8e8c3e5f646764b4b3d91ce34612886d452`; com Onepage e Landing Page, compara byte a byte a MAC v2 emitida pela nova fronteira do Portal e aceita pelo Sites. Nega fonte indisponível, alteração de `keyEpoch` entre as duas leituras e ausência de fonte comercial. **Quatro workflows distintos concluídos com sucesso**, incluindo cross-repo PHP8.3/8.4, Laravel, testes sintéticos e safety.
- As duas alterações continuam em PRs **draft e empilhados**; não foram mescladas. A origem de chaves/época NÃO é key vault, IAM, OAuth, mTLS, revisão monotônica em banco, aceite real nem autorização de domínio/cliente. A dupla leitura não é commit distribuído e não elimina revogação após assinatura. A revisão AppSec independente e o teste HTTPS de homologação ainda são pendências.
- **10/10 gates operacionais permanecem BLOCKED**, sem deploy, merge em main/dev, publicação, dados reais, VPS/Cloudflare, DNS nem gasto novo.


## Continuidade supervisionada — F1-119 / F1-120 (10/11/2026)

- [Portal PR #258 / F1-12](https://github.com/rafhael100/imagine-portal/pull/258): criado **emissor PHP v2 independente** de envelope HMAC com `keyEpoch` no Imagine Portal, empilhado sobre o candidato histórico Portal #105. Os workflows Node.js e Laravel/PHP 8.3/8.4 passaram. Não é emissor IAM operacional do Portal nem está em main/dev.
- [Sites PR #155 / F1-119](https://github.com/rafhael100/imagine-sites/pull/155): `tests/f23-cross-repo.php` usa as classes reais da revisão pública **fixa** do Portal `855212e93649235715202390f296444d91b6609e`, compara todo o envelope emitido e verificado em Sites para Onepage/Landing Page e exige recusa de v1 como v2, HMAC alterada, revisão de chave antiga e nonce repetido. **Quatro workflows distintos aprovados**, incluindo cross-repo PHP8.3/8.4, Laravel, sintéticos e safety.
- [Sites PR #156 / F1-120](https://github.com/rafhael100/imagine-sites/pull/156): nenhuma versão de envelope é aceita implicitamente: `SITES_F118_REQUIRE_EPOCH_SIGNED=1` habilita v2, `SITES_F118_ALLOW_LEGACY_V1_LAB=1` habilita v1 **somente em testes CLI**, e ausência/ambas as opções negam antes de consumir nonce. PG18 PHP8.3/8.4, Laravel, sintéticos e safety aprovados na primeira execução; Docker estava em validação na última consulta. Sem endpoint e sem promoção de v1.
- Os três PRs permanecem drafts empilhados em branches isoladas. **Não** há serviço oficial de autorização Portal, identidade mutuamente autenticada, vault/geração monotônica real, chave de produção, aceite do cliente ou liberação de site. O Portal atual na linha principal não foi alterado. A integração HTTP/HTTPS de homologação e revisão AppSec independente ainda são necessárias.
- Nenhum merge em dev/main, deploy, DNS, VPS, dados reais ou gasto adicional. **10/10 gates operacionais de produção permanecem BLOCKED.**


## Continuidade supervisionada — F1-117 / F1-118 (10/11/2026)

- [PR #153 — F1-117](https://github.com/rafhael100/imagine-sites/pull/153): a assinatura HMAC candidata foi ligada a uma fonte independente de época monotônica de chave (`PortalSigningKeyEpochSource`), cujo padrão é DENY. Uma rotação A→B→A durante o commit é detectada pela mudança da revisão mesmo com segredo final igual, com rollback de projeto/admissão e testes em duas bases efêmeras PG18. **Cinco workflows distintos concluídos com sucesso**: PostgreSQL18 PHP8.3/8.4, Laravel, Docker, testes sintéticos e safety.
- [PR #154 — F1-118](https://github.com/rafhael100/imagine-sites/pull/154): formato de comando HMAC sintético **v2** com `keyEpoch` autenticado pelo MAC. Sob `SITES_F118_REQUIRE_EPOCH_SIGNED=1`, v1 é negado e uma mensagem antiga é rejeitada após A→B→A anterior à operação, mesmo se o segredo original tiver sido restaurado. Os testes exigem MAC válido, revisão corrente e nonce único entre duas conexões/bases PG18. **Cinco workflows distintos concluídos com sucesso** na HEAD com código: PostgreSQL18 PHP8.3/8.4, Laravel, testes sintéticos, safety e Docker isolado. Nenhuma integração operacional foi ativada.
- **Limite essencial:** o novo v2 não é atualmente emitido por um serviço oficial do Portal nem foi homologado entre as branches atuais dos dois repositórios. O v1 permanece apenas no laboratório legado sem flag v2; as fontes de chaves e épocas são fixtures, não vault, IAM ou autenticação serviço-serviço real.
- Ambos os PRs continuam draft/empilhados sobre #152 e anteriores, sem merge em dev/main, sem cliente, PII, DNS, VPS/Cloudflare, deploy ou novos custos. A revisão AppSec independente e a integração autenticada seguem pendentes na [issue OP01 #33](https://github.com/rafhael100/imagine-sites/issues/33). **10/10 gates operacionais BLOCKED.**


## Continuidade supervisionada — F1-115 / F1-116 (10/11/2026)

- [PR #151 — F1-115](https://github.com/rafhael100/imagine-sites/pull/151): removeu a chave HMAC fornecida pelo chamador da entrada assinada; o serviço consulta `PortalSigningKeySource` independente, por padrão DENY, com emissor/audiência fixos. Testes de revogação, erro e rotação com duas conexões/bases PostgreSQL; **cinco workflows distintos concluídos com sucesso**, incluindo PostgreSQL18 PHP8.3/8.4 e Docker.
- [PR #152 — F1-116](https://github.com/rafhael100/imagine-sites/pull/152): o gateway candidato reconsulta a chave de assinatura na admissão inicial e antes do commit, retornando DENY se alterada. Testes de revogação exatamente no pré-COMMIT comprovam rollback de projeto+admissão e replay idempotente apenas com nonce novo. Os cinco workflows distintos executados na primeira HEAD do PR concluíram com sucesso; revisão independente ainda pendente.
- [Pauta AppSec independente](https://github.com/rafhael100/imagine-sites/blob/fix/sites-f1-116-final-signing-key-recheck-2026-11-10/docs/SITES-F1-116-INDEPENDENT-APPSEC-REVIEW-PACKET.md): evidências, riscos não cobertos e critérios concretos de revisão do OP01 [#33](https://github.com/rafhael100/imagine-sites/issues/33) e OP02 [#34](https://github.com/rafhael100/imagine-sites/issues/34). Nenhum parecer independente ou integração real foi concluído.
- Todos os PRs permanecem empilhados como drafts, sem merge em dev/main, deploy, dados reais, VPS/Cloudflare, domínio ou despesas. Chave fictícia HMAC e fontes em memória não são identidade oficial do Portal, e a GUC de tenant do PostgreSQL não é IAM. **10/10 gates de produção BLOCKED.**


## Continuidade supervisionada — F1-112..114 (10/11/2026)

- [PR #148 — F1-112](https://github.com/rafhael100/imagine-sites/pull/148): o armazenador de nonces `LabPostgresReplayStore` revalida **em cada consumo** o contexto CLI/testing, a flag de laboratório, a base e a role PostgreSQL restrita, os privilégios de `pg_roles` e a ausência de transação controlada externamente. Testes negativos rejeitam `RESET ROLE`, revogação de flag, execução fora de testing e transação com possibilidade de rollback. **Seis workflows distintos concluíram com sucesso**, incluindo F1-14 e F1-94 PHP8.3/8.4.
- [PR #149 — F1-113](https://github.com/rafhael100/imagine-sites/pull/149): teste na base efêmera remove temporariamente `INSERT` da role de nonces e comprova falha fechada sem mutar projeto. Após restaurar a permissão, a mesma mensagem ainda não consumida pode ser processada uma única vez. **Quatro workflows distintos aprovados**, incluindo PostgreSQL18 PHP8.3/8.4.
- [PR #150 — F1-114](https://github.com/rafhael100/imagine-sites/pull/150): cenário oposto: o nonce já foi consumido, mas a conta restrita perde temporariamente `SELECT` na admissão. A transação de projeto recusa, o ledger permanece inalterado, a mensagem original não pode ser reutilizada e **um novo nonce assinado** permite replay idempotente. **Quatro workflows distintos aprovados** antes desta atualização documental, incluindo PostgreSQL18 PHP8.3/8.4.
- As três etapas estão empilhadas sobre #147 e anteriores em PRs draft; não há autorização de merge. O teste mostra que armazenamento de replay e admissão não compartilham commit distribuído: o ponto da falha determina se o nonce original pode ou não ser reutilizado.
- Sem Portal IAM real, revogação operacional, gestão de chaves, prova de aceite, banco de produção/backup, VPS homologada, Cloudflare, DNS, cliente ou publicação. **10/10 gates operacionais BLOCKED**; nenhum merge em dev/main, deploy, dados reais ou gasto adicional.


## Continuidade supervisionada — F1-110 e F1-111 (10/11/2026)

- [PR #146 — F1-110](https://github.com/rafhael100/imagine-sites/pull/146): fluxo CLI/testing `LabSignedProjectIntake` encadeia envelope de comando com HMAC sintética, nonce consumido pelo `ReplayStore`, gateway de autorização independente e admissão transacional de projeto em PostgreSQL18. Foram incluídos testes para payload alterado, nonce repetido, autorização negada/revogada, repetição idempotente e compatibilidade com snapshot/epoch F1-102/105. Os cinco workflows observados terminaram com sucesso, incluindo matriz PG18 PHP8.3/8.4 e Docker. Nenhuma operação real é habilitada.
- [PR #147 — F1-111](https://github.com/rafhael100/imagine-sites/pull/147): execução integrada com **duas bases de dados descartáveis** no mesmo serviço PostgreSQL CI, separando o ledger de projetos do armazenamento de nonce F1-12/F1-14. Duas conexões PDO distintas demonstram replay recusado entre sessões; assinatura nova e autorização fictícia permitem somente admissão idempotente; nega adulteração de tenant, revogação e escopo externo. CI da F1-111 aprovado nas quatro matrizes distintas, incluindo PostgreSQL18 PHP8.3/8.4.
- As duas entregas continuam empilhadas sobre #145 e anteriores, em branches isoladas e PRs draft. Não houve merge em dev/main, deploy, integração real Portal IAM, ativação de VPS, DNS, dados pessoais, aceite do cliente ou despesas novas.
- A conta SQL do tenant e a HMAC de laboratório não conferem identidade operacional; o contexto GUC do PostgreSQL pode ser forjado por SQL direto. Nonce e gravação de projeto em bancos diferentes não possuem commit distribuído. A origem confiável, gestão de chaves, revogação operacional, backup/HA e revisão independente AppSec ainda estão pendentes. **10/10 gates de produção permanecem BLOCKED.**


## Continuidade supervisionada — F1-107..109 (10/11/2026)

- [PR #143 — F1-107](https://github.com/rafhael100/imagine-sites/pull/143): `fromCanonicalJson` recusa chaves duplicadas, byte encoding não canônico, whitespace, JSON inválido e mais de 4096 bytes. PHP8.3/8.4, Laravel, testes sintéticos, segurança e Docker concluídos com sucesso. **Não verifica assinatura nem IAM.**
- [PR #144 — F1-108](https://github.com/rafhael100/imagine-sites/pull/144): estende `tests/f23-cross-repo.php` para comprovar compatibilidade do comando real de uma **revisão pública fixada** do Portal com o snapshot estrito do Sites para Onepage e Landing Page. Um erro de autoload do teste foi encontrado e corrigido; a matriz cross-repo PHP8.3/8.4 foi aprovada, além de Laravel, sintéticos e safety. Não se trata da branch corrente do Portal nem de IAM operacional.
- [PR #145 — F1-109](https://github.com/rafhael100/imagine-sites/pull/145): centraliza a versão e campos do decoder de autorização e adiciona sentinela CI contra divergência entre JSON Schema e PHP, incluindo status, campos obrigatórios, tipos, contrato CreateProject, TTL e campos proibidos. CI da F1-109 concluído com sucesso nos cinco workflows distintos executados.
- Estes PRs estão empilhados sobre #142 e anteriores, em branches isoladas e como rascunhos. Exigem revisão independente antes de qualquer merge.
- **10/10 gates de produção seguem BLOCKED**: sem autenticação real do Portal, assinatura, prova de aceite, integração pública, Cloudflare, VPS homologada, cliente, DNS, deploy, alterações dev/main ou custos adicionais.


## Continuidade supervisionada — F1-105 / F1-106 (10/11/2026)

- [PR #141 — F1-105](https://github.com/rafhael100/imagine-sites/pull/141): o gateway candidato Portal→Sites passou a exigir revisão atual da autorização por fonte independente de epoch; snapshot elegível ainda válido não pode ser reutilizado quando a revisão consultada diverge. Fonte ausente, inválida ou indisponível nega por padrão. Testes no CLI PHP 8.3/8.4 e no PostgreSQL 18 descartável cobrem revogação, epoch divergente e alteração de epoch entre a autorização inicial e o commit. Os cinco workflows distintos executados na HEAD F1-105 concluíram com sucesso; sem merge.
- [PR #142 — F1-106](https://github.com/rafhael100/imagine-sites/pull/142): contrato candidato JSON Schema v1 e `fromArray` estrito para o registro do snapshot, com versão obrigatória, seis campos exatos, comando vinculado, tipos e TTL. Testes PHP8.3/8.4 contra campos extras, formatos inválidos, empresas diferentes e expiração. Aguardar CI final da própria HEAD.
- Os dois PRs são empilhados e ainda dependem de #140 e linhas anteriores; revisão independente AppSec, contrato real do Portal, IAM autenticado e revogação operacional permanecem pendentes.
- Fontes de snapshot e epoch são **fictícias**; duas fontes igualmente obsoletas não comprovam autorização corrente. A GUC de tenant do PostgreSQL pode ser forjada com SQL direto; não disponibilizar acesso arbitrário ao banco. **10/10 gates operacionais BLOCKED**, nenhuma publicação, merge em dev/main, PII, DNS, despesa ou uso da VPS.


## Evolução supervisionada — F1-103 e F1-104 (10/11/2026)

- [PR #139 — F1-103](https://github.com/rafhael100/imagine-sites/pull/139): a política candidata F1-102 de snapshot Portal, com vínculo exato empresa/contrato/operação/idempotência e TTL limitado, foi conectada **somente no teste PG18 descartável** ao serviço de admissão de projetos. Sem snapshot, com grant expirado/revogado, ou com operação diferente, não admite; decisão corrente fictícia permite gravar ou fazer replay idempotente. Testes PG18 PHP8.3/8.4 aprovados; sem IAM real.
- [PR #140 — F1-104](https://github.com/rafhael100/imagine-sites/pull/140): rechecagem do gateway de autorização imediatamente antes do COMMIT tanto para projeto+admissão quanto para replay; revogação intermediária faz rollback e preserva o ledger. Testes de PostgreSQL18 PHP8.3/8.4, Laravel, domínio e gate de segurança aprovados na HEAD de código antes da atualização documental; CI final de HEAD precisa ser conferido. Nenhum merge.
- Branches empilhadas: #140 depende #139, #138, #137, #136, #135, #134, #133 e agregado #132. Revisão independente AppSec ainda não concluída.
- Este avanço não habilita Portal IAM oficial, assinatura, aceite do cliente, CRM/AI Agents reais, Cloudflare, VPS, domínio, deploy nem dados de cliente. `imagine_sites.tenant_ref` é forjável por SQL direto; as checagens não comprovam identidade. **10/10 gates operacionais BLOCKED** e sem custos/merges/publicação.


## Continuidade supervisionada — F1-100..102 (10/11/2026)

- F1-100: rechecagem da autorização Portal *fictícia* antes do commit de consulta da revisão vigente, com teste de revogação intermediária e rollback; [PR #136](https://github.com/rafhael100/imagine-sites/pull/136). Os cinco workflows distintos executados concluíram com sucesso, sem aprovação operacional.
- F1-101: rechecagem da mesma autoridade antes do commit tanto da nova gravação quanto de replay idempotente, com testes de revogação e rollback sobre PG18; [PR #137](https://github.com/rafhael100/imagine-sites/pull/137). Depende de #136; revisão CI na HEAD deve ser conferida antes de qualquer integração.
- F1-102: snapshot candidato de autorização do projeto Portal-Sites, vinculado campo a campo a tenant, pedido, contrato/revisão, produto, operação e chave idempotente, status e TTL máximo de 30 segundos. Source default DENY, modo CLI/testing explícito, fonte fictícia em memória; suíte PHP8.3/8.4 incorporada. NÃO há IAM real ou autorização de produção.
- As novas alterações são PRs empilhados em branches isoladas sobre as etapas F1-99..97 e o PR agregado #132. Nenhum merge em dev/main, deploy, publicação, dados pessoais ou despesas novas.
- PostgreSQL tenant GUC não autentica recurso; a checagem em tempo limitado ainda pode ficar obsoleta depois; assinatura/aceite do cliente e revogação operacional seguem ausentes. **10/10 gates em config/operational-readiness.json permanecem BLOCKED.**


## Continuidade supervisionada — 10/11/2026 (F1-97..99)

- [PR #133 — F1-97](https://github.com/rafhael100/imagine-sites/pull/133): rechecagem dos quatro arquivos privados no replay idempotente, antes do COMMIT; simulação de alteração durante avaliação de autoridade e restauração. Suítes CI relevantes concluídas com sucesso. Sem merge.
- [PR #134 — F1-98](https://github.com/rafhael100/imagine-sites/pull/134): leitor da revisão vigente adquire lock compartilhado por empresa/site na mesma chave do gravador F1-95. PostgreSQL18, Laravel, guardas, testes sintéticos e Docker isolado concluídos com sucesso. Sem merge.
- [PR #135 — F1-99](https://github.com/rafhael100/imagine-sites/pull/135): verificador de arquivos privados nega caminho com qualquer ancestral simbólico e arquivo com hardlink (nlink diferente de 1). Testes negativos novos de symlink parental e hardlink externo. **CI da HEAD ainda requer verificação de todos os jobs relevantes; não é aprovação para integração**.
- A progressão é de PRs empilhados: #135 depende de #134, que depende de #133 e da consolidação #132. Todos seguem em revisão; não modificar dev/main, não fazer deploy, não publicar HTML para clientes.
- Ressalvas: a GUC PostgreSQL é controlável por SQL direto, não é IAM. Locks só coordenam processos cooperantes; verificação de caminho não impede troca TOCTOU; arquivos não são snapshot distribuído nem prova de aceite. **10/10 gates operacionais permanecem BLOCKED**.


## Atualização — 10/11/2026 (linha supervisionada de desenvolvimento)

### Marco atual F1-96 — PR agregado #132

- [PR #132](https://github.com/rafhael100/imagine-sites/pull/132): consolidação F1-45..96 para revisão independente em `dev`, **sem merge, deploy, gastos ou dados reais**. A HEAD final precisa passar a matriz de CI e ainda depende de revisão humana/AppSec.
- [PR #130 — F1-95](https://github.com/rafhael100/imagine-sites/pull/130): serviço real de laboratório `LabVerifiedDraftRevisionStore` grava revisões em `sites_drafts` após verificar os 4 arquivos privados, projeto admitido F1-94, hashes de HTML/dossiê e autorização fictícia atual. Sequência rígida, replay da última revisão exato e advisory lock por empresa/site; não é serviço público.
- [PR #131 — F1-96](https://github.com/rafhael100/imagine-sites/pull/131): `LabVerifiedLatestDraftReader` revalida autorização antes da leitura e compara a revisão vigente do PostgreSQL 18 com os arquivos privados; nega versões antigas, diretórios ausentes, bytes alterados e permissão revogada. **Suíte integrada com 37 assertivas por versão PHP 8.3/8.4** aprovada em CI individual.
- **Limites:** RLS com `imagine_sites.tenant_ref` é forjável por usuário SQL; diretório local não dá snapshot atômico nem storage imutável; gateway Portal dos testes é ficcional. Sem IAM, aceite real, CRM, Cloudflare, observador HTTPS externo, VPS homologada ou AppSec independente, os **10 gates operacionais seguem BLOCKED**. Nenhuma publicação ou integração em `dev`/`main` ocorreu.
- [PR #129](https://github.com/rafhael100/imagine-sites/pull/129): agregado anterior, aprovado em 24 workflows e 41/41 jobs, mas **não mesclado** e substituído pela consolidação #132.

### Marco anterior F1-94 — PR agregado #129 (histórico)

- [PR #129](https://github.com/rafhael100/imagine-sites/pull/129): histórico de F1-45..94, aprovado anteriormente em 24 workflows e 41/41 jobs, substituído pelo #132. Sem merge ou deploy.
- [PR #128 — F1-94](https://github.com/rafhael100/imagine-sites/pull/128): segunda migração real `sites_project_admissions` e admissão transacional de projeto sob gateway `PortalProjectAuthorityGateway` **DENY por padrão**. Idempotência baseada em empresa, operação, site, pedido, contrato, revisão e hash semântico, com rollback em conflitos. Os seis workflows individuais passaram, incluindo PostgreSQL 18 com **25 assertivas por versão PHP 8.3/8.4**.
- O novo teste comprova que o trigger bloqueia UPDATE/DELETE e a FK exige projeto da própria empresa. Também comprova **limite de segurança: o usuário SQL que controla `imagine_sites.tenant_ref` pode assumir o contexto de outra empresa**. Portanto, RLS/GUC não equivalem a identidade/isolamento operacional confiável; é indispensável autenticação real no Portal e restrição absoluta ao usuário SQL.
- [PR #127](https://github.com/rafhael100/imagine-sites/pull/127) passou anteriormente em 23 workflows distintos e 39/39 jobs; agora é proposta histórica substituída, **não mesclada**. O mesmo ocorre com PRs agregados antigos. Os dez gates `BLOCKED` continuam sem evidência operacional, pois não existem ainda IAM real, Cloudflare público, CRM/IA operacionais ou VPS homologada.
- Preservados: nenhum dado real, contratação, DNS, código em `dev`/`main`, deploy, publicação ou aceite do cliente. Revisão independente obrigatória antes de merge.

### Marco anterior F1-93 — PR agregado #127 (histórico)

- [PR #127](https://github.com/rafhael100/imagine-sites/pull/127): registro da consolidação até F1-93, **substituída pelo PR #129**, sem merge em `dev`/`main` e sem liberação operacional. O CI de sua HEAD anterior passou em 23 workflows e 39/39 jobs.
- [PR #125 — F1-92](https://github.com/rafhael100/imagine-sites/pull/125): correção da validação do snapshot do Portal usando o mesmo instante confiável do envelope HMAC v2; testes de expiração, revogação e nonce. **Seis workflows acionados aprovados**.
- [PR #126 — F1-93](https://github.com/rafhael100/imagine-sites/pull/126): migração Laravel real cria `sites_projects`/`sites_drafts` em PostgreSQL 18 efêmero, RLS FORCE, constraints de produto/revisão/hash e trigger contra UPDATE/DELETE. **PHP 8.3 e 8.4 aprovados**, 16 checagens por job, role não privilegiada, cross-tenant, transação sem vazamento e rollback destrutivo proibido. O trigger SQL precisou de correção de delimitador e depois passou.
- **Limite de segurança explícito:** o valor `imagine_sites.tenant_ref` em `SET LOCAL` pode ser manipulado por acesso SQL direto; **não é identidade nem autorização por empresa**. Só usar com conexão restrita, código autenticado pelo Portal e sem SQL arbitrário. A migração nega execução fora de `APP_ENV=testing` + `SITES_F93_SCHEMA_LAB=1`.
- O PR #124 passou previamente **22 workflows e 37/37 jobs**, porém é proposta anterior e NÃO foi mesclado. Dev/main e os dez gates `BLOCKED` seguem inalterados. VPS, CRM, IA e Cloudflare reais, domínio, backup, LGPD/AppSec e aceite expresso do cliente seguem pendentes.

### Marco anterior F1-91 — PR agregado #124 (histórico)

- [PR #124](https://github.com/rafhael100/imagine-sites/pull/124): registro histórico até F1-91, aprovado anteriormente em 22 workflows e 37/37 jobs. Substituído pela revisão de #127, **sem merge ou liberação de produção**.
- **F1-90:** a tela local não simula mais aprovação interna por GET; `internal_review` revisão 2, dossiê SHA-256 e QA pendentes, sem API pública. Validado anteriormente em 21 workflows e 36/36 jobs no PR #122.
- **F1-91:** `composer validate` e auditoria `composer audit --locked` tanto do conjunto completo quanto das dependências de produção. No PR #123 o job aprovou e declarou `No security vulnerability advisories found` em ambas as consultas (10/11/2026), sujeito à atualização dos feeds e à nova execução no agregado.
- Propostas agregadas anteriores #114, #117, #120 e #122 são histórico substituído, **não foram mescladas**. Identidade/aceite real Portal, CRM, Cloudflare/CDN, AppSec independente e VPS Leaseweb continuam não homologados. Os dez gates `BLOCKED` permanecem intactos.

### Continuidade F1-88..90 — 10/11/2026

- [PR #120 — consolidação F1-45..89](https://github.com/rafhael100/imagine-sites/pull/120): **registro histórico substituído**; foi aprovado em 21 workflows e 36/36 jobs, e encerrado sem merge.
- [PR #118 — F1-88](https://github.com/rafhael100/imagine-sites/pull/118): prova conjunta de dossiê e HTML privado em disco + decisão editorial persistida/relida com role PostgreSQL por tenant.
- [PR #119 — F1-89](https://github.com/rafhael100/imagine-sites/pull/119): prova por GET dos bytes HTML efetivamente servidos em servidor HTTP de **loopback**, sem alegar observação Cloudflare remota.
- [PR #121 — F1-90](https://github.com/rafhael100/imagine-sites/pull/121): a tela visual deixa de chamar a transição de **aprovação interna simulada** ao abrir a prévia, usando um dossiê ainda pendente de revisão, SHA-256 próprio e nenhum formulário/ação para aprovação/publicação. Validação CI desta alteração ainda subordinada ao PR.
- O PR agregado histórico #114 foi **encerrado sem merge**, substituído pelas propostas posteriores. `dev`/`main` e os dez gates `BLOCKED` permanecem inalterados. Isso ainda NÃO é Portal autenticado, evidência de cliente, CRM, HTTPS/CDN real, VPS homologada ou autorização operacional.

- **Registro histórico (substituído):** [PR #114](https://github.com/rafhael100/imagine-sites/pull/114) reunia a linha F1-45..F1-85, com **21 workflows distintos e 36/36 jobs aprovados** no head `940c9dc`, incluindo Laravel PHP 8.3/8.4, PostgreSQL 18, cópia/restauração de banco descartável, Chromium e execução real de laboratório Docker com usuário PostgreSQL sem superuser. **PR posteriormente encerrado sem merge, substituído por #120; nenhum destes códigos foi integrado em `dev` ou `main`**.
- **F1-86:** [PR #115](https://github.com/rafhael100/imagine-sites/pull/115) implementa verificação **após gravação em disco** do pacote privado de revisão com quatro arquivos, hashes e conteúdo canônico, sem links simbólicos/arquivos extras, mais comando Laravel de leitura somente e negativos. Submetido à validação CI, dependente da linha F1-85.
- **Estado real:** as classes e testes de laboratório não concedem identidade do Portal, aceite do cliente, Cloudflare, IA/n8n real, CRM, backup da VPS, avaliação AppSec independente ou go-live. **10/10 gates operacionais continuam `BLOCKED`**, issues #33–#38 abertas. Não houve publicação, DNS ou alteração de produção nesta linha.
- Documentação atualizada em [README.md](README.md), incluindo comandos fictícios do gerador e do verificador. Os registros anteriores abaixo preservam o histórico de PRs individuais e não indicam que etapas ainda em draft foram mescladas.

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
- [PR #63](https://github.com/rafhael100/imagine-sites/pull/63) — F1-45, assinatura HMAC sintética de proposta Sites→Portal, vínculo exato a tenant/site/release/revisão/hash, anti-replay e verificador de autoridade atual DENY-default. **CI PHP 8.3/8.4, Laravel e safety guard PASS** (`368ea0d`); draft sem merge. Não é identidade ou aceite operacional.
- [PR #64](https://github.com/rafhael100/imagine-sites/pull/64) — F1-46, JSON Schema candidato + envelope gerado pelo PHP e assinatura HMAC verificada independentemente em Python, com negativos de payload. **CI PHP 8.3/8.4, contratos, Laravel e safety guard PASS** (`a8a3dd8`); draft dependente de #63, sem endpoint real.
- [PR #65](https://github.com/rafhael100/imagine-sites/pull/65) — F1-47, nonce durável em Postgres 18 descartável, issuer e RLS isolados, expiração, duas conexões PDO independentes e negativa por revogação sintética antes do consumo. **CI PG 18/PHP 8.3/8.4, Laravel e safety guard PASS** (`527e34c`); draft dependente de #64. Sem integração real.
- [PR #68](https://github.com/rafhael100/imagine-sites/pull/68) — F1-48, gateway DENY-default com snapshot independente de revisão Portal (tenant, site, versão, digest, revogação). **3 workflows CI PASS** em `7a0a9f3`, inclusive PHP 8.3/8.4. Draft sem merge; não implementa identidade ou aceite real.
- [PR #69](https://github.com/rafhael100/imagine-sites/pull/69) — F1-49, fonte SQL atual de duas empresas com RLS e nonce PostgreSQL 18. **Jobs PostgreSQL 8.3/8.4 PASS no descendente `2c75e04` (PR #71)**; primeira rodada do próprio PR falhou sem criar jobs. Não é autoridade operacional.
- [PR #71](https://github.com/rafhael100/imagine-sites/pull/71) — F1-50, HMAC v2 inclui authorizationRevision e nega token de epoch anterior após revogação/reativação. **Testes de domínio PHP 8.3/8.4 PASS em `6264fb6` (PR #74)**; workflow próprio não criou jobs. Não resolve TOCTOU/identidade real.
- [PR #72](https://github.com/rafhael100/imagine-sites/pull/72) — F1-51, presets Classic/Modern/Warm, versão clássica byte-idêntica, anti-injeção e SHA imutável; **testes puros PHP 8.3/8.4 PASS em `6264fb6` (PR #74)**. CI próprio incompleto por job não iniciado; não publica.
- [PR #73](https://github.com/rafhael100/imagine-sites/pull/73) — F1-52, seis prévias e screenshots Chromium distintos, manifesto SHA-256, arquivos apagados. **Smoke Chromium CI PASS** em `0878e65`, bem como testes puros PHP 8.3/8.4; outros workflows sofreram falhas sem jobs. Não é homologação visual total.
- [PR #74](https://github.com/rafhael100/imagine-sites/pull/74) — F1-53, revisão Laravel CLI seleciona tema, gera versão exata com SHA, preserva Classic anterior. **Job de testes Laravel PHP 8.4 PASS em `6264fb6`**; PHP 8.3 do workflow específico não iniciou, logo a matriz Laravel está incompleta. Core PHP 8.3/8.4, bundle e safety guard PASS. Sem aceite/publicação.
- [PR #75](https://github.com/rafhael100/imagine-sites/pull/75) — F1-54, troca de estilo retorna a NOVO draft com revisão/release/digest diferentes e revisão interna obrigatória, sem autoaprovar. **3 workflows PASS** (`4fdb40b`). PR draft sem merge.
- [PR #76](https://github.com/rafhael100/imagine-sites/pull/76) — F1-55, preflight de lead requer vínculos independentes Sites+CRM e preserva destino, T0, 72h/432h; nega revogação, divergência e recibo entregue. **3 workflows PASS** (`14c7786`). Sem PII ou chamadas reais.
- [PR #77](https://github.com/rafhael100/imagine-sites/pull/77) — F1-56, integração dos PRs visuais #56..62 com a linha atual, preservando os novos temas, Laravel e regras CRM. **7 workflows PASS** (`e20ba16`), após correção da limpeza do perfil Chromium. Nenhum merge/deploy.
- [PR #78](https://github.com/rafhael100/imagine-sites/pull/78) — F1-57, seletor Classic/Modern/Warm na UI CLI/testing, navegação por produto/dispositivo, revisão e SHA exatos. **5 workflows PASS** (`27edaa2`) após corrigir erro de diretiva Blade e tornar o teste Chrome preciso sobre foco (sem afirmar navegação interna no iframe). Galeria F1-41 Classic-only; F1-52 valida renderizações temáticas separadas. Sem aceite/publicação.
- **F1-58 (PR draft pendente de CI):** galeria de revisão de 12 páginas HTML em CLI/testing, cobrindo 2 produtos × 2 visualizações × 3 temas, links offline, hashes exatos, CSP e publicação/aceite negados. Sem cliente, serviço público ou Cloudflare.
- [PR #80](https://github.com/rafhael100/imagine-sites/pull/80) — F1-59, Chromium audita 12 prévias HTML internas, overflow e contraste de fundos sólidos. **4 workflows CI PASS** (`9557a02`); draft sem merge, não certifica WCAG.
- **F1-60 (PR draft CI pendente):** demonstração em PostgreSQL descartável: consumir nonce de revisão v2 sob os mesmos locks de autorização e concessão de papel, com negativas por revogação, epoch, cross-tenant e concorrência. Não resolve revogação distribuída real.
- [PR #83](https://github.com/rafhael100/imagine-sites/pull/83) — F1-62, 324 amostras de contraste do degradê da hero e 12 verificações extras a 320px no Chromium. **4 workflows PASS** (`e71f52c`); draft, não equivale a WCAG.
- [PR #84](https://github.com/rafhael100/imagine-sites/pull/84) — F1-63, QA editorial orientativo no painel privado (título, descrição, CTA, benefícios), sem alterar HTML ou conceder aceite. **7 workflows PASS** (`6f6f04b`), em draft.
- [PR #85](https://github.com/rafhael100/imagine-sites/pull/85) — F1-64, inventário legível/JSON dos 10 gates operacionais BLOQUEADOS, links para issues #33–#38, testes de mutações que não concedem ALLOW. **3 workflows PASS** (`1d2e7fa`); draft, sem acessar provedores.
- [SITES-HOMOLOGATION-ENTRY](docs/SITES-HOMOLOGATION-ENTRY-2026-11-09.md) — matriz de entrada e bloqueadores com issues #33–#38.

**CI F1-49..53: nota operacional:** algumas execuções do GitHub Actions tiveram resultado global `failure` com zero ou menos jobs do que a matriz solicitada. Foram registrados **somente os jobs realmente executados como PASS**, não a validação integral dos workflows incompletos. É obrigatório repetir os jobs faltantes antes de qualquer merge/homologação. Esta falha não foi atribuída a código nem a um incidente externo sem evidência conclusiva.

**Paridade entre repositórios:** [Portal PR #171](https://github.com/rafhael100/imagine-portal/pull/171) (PORTAL-SITES-F1-12) implementou o consumidor sintético DENY-default da proposta assinada de revisão v2 Sites→Portal. **CI Laravel PHP 8.3/8.4 e testes gerais Portal PASS** (`e753e68`). Continua sem identidade operacional, aceite real ou merge.

**Ainda não há ambiente operacional Sites publicado.** P0/RF-PEND/RV-CT/AppSec/LGPD, contratos financeiros, identidade, Cloudflare/DNS, banco produtivo, geração real por IA/n8n, captação/CRM fisicamente redundante e backups/restore continuam não homologados.

**Gate exclusivo do responsável:** conexão autorizada de ambiente Leaseweb + Cloudflare e domínio técnico de teste, com gestão segura de credenciais/quotas, antes de qualquer homologação real. Não inserir credenciais no GitHub público ou neste chat.

`config/operational-readiness.json` continua com gates BLOCKED. `AGI-11-RB01` DESABILITADO; sem promoção de `dev` para `main`, deploy, DNS de cliente ou dados reais.
