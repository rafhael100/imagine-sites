# Proveniência local do briefing ao HTML

**Data:** 18/11/2026. **Estado:** implementação candidata em branch isolada,
sem merge ou ativação operacional. [PR #518](https://github.com/rafhael100/imagine-sites/pull/518),
HEAD `907e2883393b776eebcba59fafb672aa34c99bf6`, com **12/12 checks SUCCESS**. Branch
`feature/sites-local-render-provenance-2026-11-18`, sobre a proposta
[#517](https://github.com/rafhael100/imagine-sites/pull/517), HEAD
`c53eac9eb8060a62eb060e68f3bc5b2956dbbdff`.

## Resultado e motivo

A renderização local anterior consumia seis campos editoriais e entregava um
HTML privado, sem conservar uma ligação verificável com o briefing rico de
origem. A proposta #517 definiu os arquivos candidatos e seu vínculo. Esta
implementação acrescenta um modo explícito ao comando existente para conferir
os quatro arquivos e produzir uma evidência privada junto ao mesmo HTML.

O chamador real é [`render.php`](../../ops/release-candidate/render.php).
O serviço [`BriefRenderProvenance`](../../src/Production/BriefRenderProvenance.php)
é puro e reutiliza `LocalSiteRendering::render`; não duplica os templates,
parser editorial ou auditoria de HTML. A entrada editorial continua escolhida
separadamente: não há transformação automática dos textos do cliente.

O [contrato candidato](../SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md)
preserva as fontes legadas fixadas, a separação entre `sourceVersion` agregado,
`briefRevision` candidata e revisão editorial, e os limites de autoridade.
A proposta #517 passou em seus dez check-runs, incluindo
[Laravel 44318819131](https://github.com/rafhael100/imagine-sites/actions/runs/44318819131).
Essa evidência anterior não é atribuída ao código deste incremento.

## Entradas e consistência

O grupo opcional `--plan`, `--snapshot` e `--source-brief` é indivisível e se
soma a `--brief` e `--output`. O modo anterior, com somente essas duas últimas
opções, continua disponível para Onepage e Landing Page. O modo novo aceita
Onepage, `materials=[]` e ambas as declarações de indisponibilidade de materiais
verdadeiras. Os [exemplos](../../contracts/portal-sites/candidates/brief-production-v1/README.md)
são inteiramente sintéticos.

| Arquivo explícito | Limite | Conferência |
| --- | ---: | --- |
| Plano | 32768 bytes | Nove campos exatos; vincula snapshot, entrada editorial e HTML esperado. |
| Manifesto do snapshot | 32768 bytes | Dezessete campos exatos; vincula o corpo rico e declara origem, escopo e revisão. |
| Corpo rico Onepage | 150000 bytes | Onze textos e três booleanos; limites UTF-16 do valor original e completude na cópia lógica aparada. |
| Entrada editorial | 32768 bytes | Os seis campos e limites do renderer existente; deve declarar o mesmo produto Onepage. |

O serviço recusa chaves extras ou duplicadas, inclusive após decodificar escapes,
UTF-8 inválido, NUL, tipos incorretos e inteiros com sinal negativo, fração ou
expoente. Confere tenant/site, snapshot/revisão e a equação declarada
`briefRevision = expectedBriefRevision + 1`. Os identificadores das operações
de snapshot e render são independentes; não se exige igualdade ou desigualdade
entre eles. Referências opacas não escolhem caminhos nem são capacidades de acesso.

Todos os tamanhos e hashes usam bytes originais. Espaço, escape ou LF diferentes
exigem atualizar os vínculos correspondentes. A cópia lógica usada para avaliar
vazio, e-mail e URL aplica o conjunto de espaços ECMAScript, sem alterar conteúdo
armazenado, normalizar Unicode ou converter o contador agregado em revisão.
O resultado do renderer só é aceito se tamanho e SHA-256 coincidirem com
`expectedArtifact`, antes de criar qualquer saída.

### Compatibilidade explícita de URLs

O validador PHP aceita um subconjunto conservador do `new URL()` WHATWG do
legado: esquema HTTP(S) em ASCII; host DNS ASCII convencional, sem labels IDNA
`xn--` ou ponto final; IPv4 canônico ou IPv6 entre colchetes; porta decimal de
até cinco dígitos entre 0 e 65535. Recusa userinfo, barras invertidas, controles,
espaços internos e formas que dependem de normalização, como host Unicode ou
IPv4 abreviado. Caminho e query UTF-8 permanecem literais e inertes. A referência
nunca é consultada pela rede. Não se afirma equivalência integral com o parser
Node. E-mail e domínio mantêm os padrões simples da fonte fixada.

## Custódia, arquivos e resposta

[`LocalSiteRenderingFiles`](../../src/Production/LocalSiteRenderingFiles.php)
compartilha a leitura e escrita protegidas entre os dois modos. Os quatro inputs
devem ter caminhos distintos, canônicos e explícitos, arquivos regulares de um
único hard link, modo 0400/0600 e custódia/ancestrais permitidos pelo leitor
existente. O parent das saídas deve pertencer ao EUID e ter modo 0700.

Os dois nomes fixos, `index.html` e `render-provenance.json`, precisam estar
ausentes antes de qualquer reserva. O writer abre ambos exclusivamente em 0600,
mantém os dois descritores e revalida os quatro inputs antes da criação, após
as reservas e ao fim da escrita. Confere bytes, hashes, inodes, modos,
propriedade, links e ancestrais observáveis. Cada saída passa por escrita
integral, flush/fsync, leitura de conferência e comparação de identidade entre
pathname e descritor. Ambos são fechados antes do stdout de sucesso.

O sidecar tem exatamente sete campos: `schemaVersion`, `status`, `planSha256`,
`artifact` com tamanho/digest e os três indicadores `authorityVerified`,
`canPublish`, `canAcceptRealOrders`, sempre falsos. O hash do plano compromete
os vínculos com os demais inputs; é necessário conservar os quatro originais
para uma conferência posterior. Hashes não armazenam conteúdo nem autenticam
quem forneceu um arquivo.

O stdout mantém os sete campos anteriores; `inputSha256` continua sendo o hash
da entrada editorial. Não expõe briefing, paths ou referências comerciais.
Uma recusa capturada pela CLI retorna status 1, stdout vazio e somente
`LOCAL_RENDER_DENIED`; interrupção ou resposta perdida pode deixar o resultado
indisponível.
O [manual da CLI](../../ops/release-candidate/README.md) contém a invocação e os
requisitos de arquivo completos.

As saídas não formam uma transação atômica. Falha, interrupção ou resposta
perdida pode deixar reservas vazias, arquivos parciais ou completos. Não há
unlink, overwrite, reparo, repetição automática ou garantia de replay durável.
As verificações observam mudanças de custódia; não prometem exclusão de toda
corrida indetectável pelo mesmo usuário confiável ou root.

## Provas do incremento

| Verificação local executada | Resultado |
| --- | --- |
| Serviço de proveniência | 109 checks PASS |
| CLI nova, com serviço e arquivos reais | 80 checks PASS |
| Renderer anterior, sem alteração do teste | 137 checks PASS |
| CLI anterior, sem alteração do teste | 87 checks PASS |
| Domínio sintético existente | 32 checks PASS |
| PHPUnit padrão, PHP 8.4.27 | 206 testes, 2174 assertions, 27 skips PASS |
| Scan conservador das fontes | 637 arquivos PASS; dez gates BLOCKED |
| Revisão independente | Serviço, writer, CLI, testes, fixture, runner e workflow sem bloqueadores restantes |

O teste do serviço verifica vínculos divergentes, bytes finais, formas JSON,
escopos e revisões, limites Unicode, materiais recusados e campos de autoridade
forjados. A revisão encontrou um esquema semelhante a HTTP usando U+017F; o
validador agora exige letras ASCII e possui um negativo com todos os hashes
recalculados. O caso `https://example.0x/` também é recusado, preservando o
subconjunto declarado de URLs.

O teste da CLI executa o comando real, incluindo corpo rico de 150000 bytes,
ordem inversa de flags, input 0400, bytes exatos das duas saídas, composições
válidas que se tornam incompatíveis e trocas de bytes/inode/modo em cada um dos
quatro inputs antes do writer. Cobre seis ocupações indevidas para cada destino,
além dos limites e aliases de caminhos. Essas trocas controladas não são uma
prova de exclusão de todas as corridas concorrentes.

### Integração PostgreSQL executada em CI

A [fixture de registro](../../tests/fixtures/release-registration/contract.php)
mantém os quatro checks de renderização anteriores e acrescenta quatro. Cria
os três arquivos adicionais sob custódia privada, usa a entrada editorial já
existente e executa a nova CLI. Confere stdout, HTML/sidecar exatos e custódia,
preservação dos quatro inputs/mounts/estado SQL/catálogos e recusa de repetição
sobre saídas existentes. Em seguida, **somente o HTML** alimenta a sequência
de registro existente. O sidecar é conferido localmente pela fixture, sem
ingestão pelo registry ou persistência de uma relação nova no banco.

O [workflow dedicado](../../.github/workflows/sites-local-render-provenance.yml)
fica restrito à draft da mesma origem, branch/base e SHA base exatos. Executa
PHP 8.3/8.4, os quatro scripts offline e o runner descartável PostgreSQL 18/TLS
com os onze opt-ins existentes. Exige os marcadores dos quatro checks antigos,
quatro novos, **922 verificações registry totais** e cluster próprio inativo.
O [run 44323010319](https://github.com/rafhael100/imagine-sites/actions/runs/44323010319)
confirmou esses resultados nos jobs `134866725644` (PHP 8.3) e `134866724964`
(PHP 8.4): **62 checks de registro, 922 registry totais**, os dois marcadores
de quatro checks e clusters próprios inativos. Os quatro scripts offline
também passaram em ambas as versões. O [run Laravel 44323009592](https://github.com/rafhael100/imagine-sites/actions/runs/44323009592)
confirmou padrão **206/2174/27** e opt-in sintético **206/2186/21**, nos jobs
`134866722658` e `134866721965`. Não houve PostgreSQL local. Não foi repetido
ensaio de navegador: os bytes e a interface visual do renderer não mudaram.

## Limites e continuidade

`sourceState=approved` é uma declaração. O serviço não consulta head, recibo de
intake, permissão corrente, pagamento ou aceite do cliente. Não grava snapshot
em `sites_core`, não promove `sites_preview`, não chama provider ou rede e não
aprova uma release. O registro posterior mantém suas identidades e regras SQL.

O próximo vínculo durável pode ser desenvolvido sobre este contrato e
consumidor, com capacidade própria, revisão imutável, CAS concorrente, replay
exato, revogação e recuperação de resposta perdida. A fonte oficial Portal e
o transporte pareado exigem provas próprias. Os [dez gates](../../config/operational-readiness.json)
continuam BLOCKED e AGI-11-RB01 permanece desabilitado; infraestrutura existente,
dados reais, deploy e publicação não são ativados por esta entrega.
